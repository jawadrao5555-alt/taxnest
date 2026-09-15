<?php

namespace Tests\Feature;

use App\Services\PosSettingsSnapshot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Guards for the PR #72 pos_custom_access deploy regression and recovery.
 */
class PosCustomAccessDeployGuardTest extends TestCase
{
    private PosSettingsSnapshot $snap;

    private string $baselinePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema();
        $this->snap = new PosSettingsSnapshot();
        $this->baselinePath = storage_path('app/testing-settings-before.json');
        @unlink($this->baselinePath);
    }

    protected function tearDown(): void
    {
        @unlink($this->baselinePath);
        parent::tearDown();
    }

    public function test_existing_custom_access_stays_byte_stable_across_service_jobs_migrations(): void
    {
        DB::table('companies')->insert(['id' => 1, 'name' => 'A', 'created_at' => now(), 'updated_at' => now()]);
        $payload = '["orders","reports"]';
        DB::table('users')->insert([
            'id' => 10, 'company_id' => 1, 'role' => 'user', 'pos_role' => 'pos_cashier',
            'pos_custom_access' => $payload, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $before = $this->snap->capture(1);
        $legacy = require database_path('migrations/2026_09_15_011000_backfill_service_jobs_custom_access.php');
        $policy = require database_path('migrations/2026_09_15_013000_service_jobs_custom_access_no_silent_rewrite.php');
        $legacy->up();
        $policy->up();
        $after = $this->snap->capture(1);
        $diff = $this->snap->diff($before, $after);
        $this->assertSame([], $diff['changed']);
        $this->assertSame($payload, DB::table('users')->where('id', 10)->value('pos_custom_access'));
    }

    public function test_service_jobs_only_addition_is_detected_and_legitimate_existing_grant_is_left_alone(): void
    {
        $before = '["orders","dashboard"]';
        $after = '["orders","dashboard","service_jobs"]';
        $this->assertTrue($this->snap->isServiceJobsOnlyAddition(
            $this->snap->normalizePublic($before),
            $this->snap->normalizePublic($after)
        ));
        $this->assertFalse($this->snap->isServiceJobsOnlyAddition(
            $this->snap->normalizePublic('["orders","service_jobs"]'),
            $this->snap->normalizePublic('["orders","service_jobs"]')
        ));
        $this->assertFalse($this->snap->isServiceJobsOnlyAddition(
            $this->snap->normalizePublic('["orders"]'),
            $this->snap->normalizePublic('["orders","reports"]')
        ));
    }

    public function test_restore_dry_run_exact_write_idempotency_and_ambiguity_refusal(): void
    {
        DB::table('companies')->insert([
            ['id' => 1, 'name' => 'A', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'B', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('users')->insert([
            [
                'id' => 1, 'company_id' => 1, 'role' => 'user', 'pos_role' => 'pos_cashier',
                'pos_custom_access' => '["orders"]', 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => 2, 'company_id' => 1, 'role' => 'user', 'pos_role' => 'pos_cashier',
                'pos_custom_access' => '["orders","service_jobs"]', 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => 3, 'company_id' => 2, 'role' => 'user', 'pos_role' => 'pos_manager',
                'pos_custom_access' => '["reports"]', 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $before = $this->snap->capture();
        file_put_contents($this->baselinePath, json_encode($before));

        // Simulate the bad migration outcome for users 1 and 3 only.
        DB::table('users')->where('id', 1)->update(['pos_custom_access' => '["orders","service_jobs"]']);
        DB::table('users')->where('id', 3)->update(['pos_custom_access' => '["reports","service_jobs"]']);
        // User 2 already had service_jobs — unchanged.

        $after = $this->snap->capture();
        $plan = $this->snap->planProtectedRestore($before, $after);
        $this->assertTrue($plan['ok']);
        $this->assertCount(2, $plan['restore']);
        $this->assertSame([], $plan['refused']);

        $dry = Artisan::call('pos:settings-restore', ['--from' => $this->baselinePath]);
        $this->assertSame(0, $dry);
        $this->assertSame('["orders","service_jobs"]', DB::table('users')->where('id', 1)->value('pos_custom_access'));

        $write = Artisan::call('pos:settings-restore', ['--from' => $this->baselinePath, '--write' => true]);
        $this->assertSame(0, $write);
        $this->assertSame('["orders"]', DB::table('users')->where('id', 1)->value('pos_custom_access'));
        $this->assertSame('["orders","service_jobs"]', DB::table('users')->where('id', 2)->value('pos_custom_access'));
        $this->assertSame('["reports"]', DB::table('users')->where('id', 3)->value('pos_custom_access'));

        // Idempotent second write.
        $again = Artisan::call('pos:settings-restore', ['--from' => $this->baselinePath, '--write' => true]);
        $this->assertSame(0, $again);

        // Ambiguity: also change role → refuse.
        DB::table('users')->where('id', 1)->update([
            'pos_custom_access' => '["orders","service_jobs"]',
            'pos_role' => 'pos_manager',
        ]);
        $poisoned = $this->snap->capture();
        $badPlan = $this->snap->planProtectedRestore($before, $poisoned);
        $this->assertFalse($badPlan['ok']);
        $this->assertSame('ambiguous_or_unsupported_changes', $badPlan['reason']);
    }

    public function test_tenant_company_branch_role_isolation_in_snapshot_diff(): void
    {
        DB::table('companies')->insert([
            ['id' => 1, 'name' => 'A', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'B', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('users')->insert([
            [
                'id' => 1, 'company_id' => 1, 'branch_id' => 10, 'role' => 'user', 'pos_role' => 'pos_cashier',
                'pos_custom_access' => '["orders"]', 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => 2, 'company_id' => 2, 'branch_id' => 20, 'role' => 'user', 'pos_role' => 'pos_cashier',
                'pos_custom_access' => '["orders"]', 'is_active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
        $before = $this->snap->capture();
        DB::table('users')->where('id', 1)->update(['pos_custom_access' => '["orders","service_jobs"]']);
        $after = $this->snap->capture();
        $diff = $this->snap->diff($before, $after);
        $this->assertCount(1, $diff['changed']);
        $this->assertSame('1', $diff['changed'][0]['row']);
        $this->assertSame('1', $diff['changed'][0]['company_id']);
        $this->assertSame('["orders"]', DB::table('users')->where('id', 2)->value('pos_custom_access'));
    }

    public function test_deploy_guard_script_auto_restores_then_keeps_fail_closed_marker(): void
    {
        $src = file_get_contents(base_path('scripts/lib/live-remote-apply.sh'));
        $this->assertStringContainsString('pos:settings-restore --from="$SETTINGS_BASE" --write', $src);
        $this->assertStringContainsString('REMOTE_SETTINGS_RESTORED', $src);
        $this->assertStringContainsString('REMOTE_SETTINGS_REGRESSION', $src);
        $this->assertStringContainsString('settings baseline retained', $src);
        $this->assertDoesNotMatchRegularExpression(
            '/REMOTE_SETTINGS_REGRESSION[\s\S]{0,80}rm -f "\$SETTINGS_BASE"/',
            $src
        );
    }

    private function schema(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->text('permissions')->nullable();
            $table->text('pos_custom_access')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_pos_cashier')->nullable();
            $table->boolean('pos_can_reprint')->nullable();
            $table->unsignedBigInteger('pos_till_id')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
    }
}
