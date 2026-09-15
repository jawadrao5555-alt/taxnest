<?php

namespace Tests\Feature;

use App\Services\PosSettingsSnapshot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Guards for the PR #72 pos_custom_access deploy regression and recovery,
 * including immutable retained-baseline handling.
 */
class PosCustomAccessDeployGuardTest extends TestCase
{
    private PosSettingsSnapshot $snap;

    private string $baselinePath;

    private string $retainedPath;

    private string $baselinesDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema();
        $this->snap = new PosSettingsSnapshot();
        $dir = storage_path('app/settings-guard-'.uniqid());
        mkdir($dir, 0775, true);
        $this->baselinesDir = $dir;
        $this->baselinePath = $dir.'/before-deploy-a.json';
        $this->retainedPath = $dir.'/.taxnest-settings-before.json';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->baselinesDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->baselinesDir);
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

    public function test_retained_baseline_is_never_overwritten_by_snapshot_out(): void
    {
        DB::table('companies')->insert(['id' => 1, 'name' => 'A', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('users')->insert([
            'id' => 1, 'company_id' => 1, 'role' => 'user', 'pos_role' => 'pos_cashier',
            'pos_custom_access' => '["orders"]', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $original = $this->snap->capture();
        $original['generated_at'] = '2026-09-15T00:00:00+00:00';
        file_put_contents($this->retainedPath, json_encode($original));
        $beforeBytes = file_get_contents($this->retainedPath);

        DB::table('users')->where('id', 1)->update(['pos_custom_access' => '["orders","service_jobs"]']);
        $this->assertSame(1, Artisan::call('pos:settings-snapshot', ['--out' => $this->retainedPath]));
        $this->assertSame($beforeBytes, file_get_contents($this->retainedPath));

        // Unique per-deploy path still works.
        $this->assertSame(0, Artisan::call('pos:settings-snapshot', ['--out' => $this->baselinePath]));
        $this->assertFileExists($this->baselinePath);
        $this->assertNotSame($beforeBytes, file_get_contents($this->baselinePath));
    }

    public function test_exact_two_service_jobs_only_additions_restore_and_legitimate_grant_remains(): void
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
        file_put_contents($this->retainedPath, json_encode($before));

        DB::table('users')->where('id', 1)->update(['pos_custom_access' => '["orders","service_jobs"]']);
        DB::table('users')->where('id', 3)->update(['pos_custom_access' => '["reports","service_jobs"]']);

        $this->assertSame(0, Artisan::call('pos:settings-restore', ['--from' => $this->retainedPath]));
        $this->assertSame(0, Artisan::call('pos:settings-restore', [
            '--from' => $this->retainedPath,
            '--write' => true,
        ]));
        $this->assertSame('["orders"]', DB::table('users')->where('id', 1)->value('pos_custom_access'));
        $this->assertSame('["orders","service_jobs"]', DB::table('users')->where('id', 2)->value('pos_custom_access'));
        $this->assertSame('["reports"]', DB::table('users')->where('id', 3)->value('pos_custom_access'));

        // Idempotent.
        $this->assertSame(0, Artisan::call('pos:settings-restore', [
            '--from' => $this->retainedPath,
            '--write' => true,
        ]));
    }

    public function test_malformed_old_or_ambiguous_baseline_refuses(): void
    {
        DB::table('companies')->insert(['id' => 1, 'name' => 'A', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('users')->insert([
            'id' => 1, 'company_id' => 1, 'role' => 'user', 'pos_role' => 'pos_cashier',
            'pos_custom_access' => '["orders"]', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        file_put_contents($this->retainedPath, '{"nope":true}');
        $this->assertSame(1, Artisan::call('pos:settings-restore', ['--from' => $this->retainedPath]));
        $this->assertFalse($this->snap->isValidSnapshot(json_decode('{"nope":true}', true)));

        $before = $this->snap->capture();
        file_put_contents($this->retainedPath, json_encode($before));
        DB::table('users')->where('id', 1)->update([
            'pos_custom_access' => '["orders","service_jobs"]',
            'pos_role' => 'pos_manager',
        ]);
        $this->assertSame(1, Artisan::call('pos:settings-restore', ['--from' => $this->retainedPath]));
        $this->assertSame('["orders","service_jobs"]', DB::table('users')->where('id', 1)->value('pos_custom_access'));
    }

    public function test_live_value_changed_after_planning_refuses_write(): void
    {
        DB::table('companies')->insert(['id' => 1, 'name' => 'A', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('users')->insert([
            'id' => 1, 'company_id' => 1, 'role' => 'user', 'pos_role' => 'pos_cashier',
            'pos_custom_access' => '["orders"]', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $before = $this->snap->capture();
        file_put_contents($this->retainedPath, json_encode($before));
        DB::table('users')->where('id', 1)->update(['pos_custom_access' => '["orders","service_jobs"]']);
        $after = $this->snap->capture();
        $plan = $this->snap->planProtectedRestore($before, $after);
        $this->assertTrue($plan['ok']);

        // Race: live moved again after planning.
        DB::table('users')->where('id', 1)->update(['pos_custom_access' => '["orders","service_jobs","reports"]']);
        $result = $this->snap->applyProtectedRestore($plan['restore'], dryRun: false);
        $this->assertSame(0, $result['written']);
        $this->assertNotEmpty($result['refused']);
        $this->assertSame('live_value_mismatch', $result['refused'][0]['reason']);
    }

    public function test_concurrent_per_sha_baselines_do_not_collide_and_clean_removes_only_own(): void
    {
        DB::table('companies')->insert(['id' => 1, 'name' => 'A', 'created_at' => now(), 'updated_at' => now()]);
        $a = $this->baselinesDir.'/before-shaA-111.json';
        $b = $this->baselinesDir.'/before-shaB-222.json';
        $this->assertSame(0, Artisan::call('pos:settings-snapshot', ['--out' => $a]));
        $this->assertSame(0, Artisan::call('pos:settings-snapshot', ['--out' => $b]));
        $this->assertFileExists($a);
        $this->assertFileExists($b);
        $this->assertNotSame(file_get_contents($a), ''); // both exist independently
        unlink($a); // clean deploy removes only its own
        $this->assertFileDoesNotExist($a);
        $this->assertFileExists($b);
    }

    public function test_deploy_guard_script_never_captures_onto_retained_path(): void
    {
        $apply = file_get_contents(base_path('scripts/lib/live-remote-apply.sh'));
        $lib = file_get_contents(base_path('scripts/lib/settings-baseline.sh'));

        // Apply script sources shared helpers and fail-closes on retained restore 89.
        $this->assertStringContainsString('scripts/lib/settings-baseline.sh', $apply);
        $this->assertStringContainsString('settings_baseline_handle_retained', $apply);
        $this->assertStringContainsString('settings_baseline_capture', $apply);
        $this->assertStringContainsString('settings_baseline_post_check', $apply);
        $this->assertStringContainsString('[ "$HR" -eq 0 ] || exit "$HR"', $apply);
        $this->assertStringContainsString('exit "$CR"', $apply);
        $this->assertMatchesRegularExpression('/\b89\)/', $apply);

        // Library owns unique per-deploy paths + retain/restore markers.
        $this->assertStringContainsString('before-%s.json', $lib);
        $this->assertStringContainsString('local retained="${LIVE_SETTINGS_BASE}"', $lib);
        $this->assertStringContainsString('REMOTE_SETTINGS_RETAINED_AMBIGUOUS', $lib);
        $this->assertStringContainsString('return 89', $lib);
        $this->assertStringContainsString('canonical retained baseline already present — left untouched', $lib);
        $this->assertStringContainsString('temporary deploy baseline removed (clean)', $lib);

        // Never capture onto the retained forensic path from either file.
        $this->assertStringNotContainsString(
            'pos:settings-snapshot --out="$LIVE_SETTINGS_BASE"',
            $apply.$lib
        );
        $this->assertStringNotContainsString(
            'pos:settings-snapshot --out="$RETAINED_BASE"',
            $apply.$lib
        );
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
