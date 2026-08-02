<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\User;
use App\Http\Controllers\PosController;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * TASK 52 REGRESSION — POS RELOAD LOOP PREVENTION (Jul 2026)
 *
 * The sale screen's boot fingerprint used to hash raw companies.updated_at
 * ('set' key in PosController::posBootFingerprint). Agent heartbeats bumped
 * that timestamp every minute → every SW-cached sale screen looked stale →
 * endless reload loop ("NestPOS bar bar load ho raha hai", 30 Jul 2026).
 *
 * The fix is TWO-layered, and this test pins BOTH layers:
 *   1. AgentController::telemetryUpdate writes with timestamps=false.
 *   2. The fingerprint no longer depends on companies.updated_at at all —
 *      it hashes Company::posConfigRev(), an explicit whitelist of
 *      POS-relevant fields. Even a FUTURE writer that DOES bump updated_at
 *      (new telemetry, counters, sync fields) can never recreate the loop.
 */
class PosBootFingerprintStabilityTest extends TestCase
{
    private Company $company;
    private User $user;
    private string $agentKey = 'test-agent-key-task52';

    protected function setUp(): void
    {
        parent::setUp();

        // Task 117 baked planAllows('offline_enabled') into the fingerprint,
        // and planAllows caches per company id STATICALLY. Ids restart at 1
        // after dropAllTables, so without this flush the test only passes
        // when an EARLIER test class happens to have warmed the cache for
        // id 1 (order-dependent) — and crashes standalone on the missing
        // subscriptions table. Flush + real tables = deterministic.
        \App\Services\PosFeatureService::flushGateCaches();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ntn')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('company_status')->default('approved');
            $table->string('status')->nullable();
            // POS-relevant settings (whitelisted in posConfigRev)
            $table->string('pos_theme')->nullable();
            $table->boolean('pra_reporting_enabled')->default(true);
            $table->decimal('pos_tax_rate_cash', 8, 2)->nullable();
            $table->decimal('pos_tax_rate_card', 8, 2)->nullable();
            $table->text('pos_printer_settings')->nullable();
            $table->string('pra_pos_id')->nullable();
            $table->string('pra_environment')->nullable();
            // Agent auth + telemetry columns (the frequent writers)
            $table->string('agent_api_key')->nullable();
            $table->boolean('agent_enabled')->default(false);
            $table->timestamp('agent_last_seen')->nullable();
            $table->string('agent_version')->nullable();
            $table->boolean('agent_offline_mode')->default(false);
            $table->timestamp('agent_snapshot_at')->nullable();
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->string('fbr_connection_mode')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('pra_reporting_enabled')->nullable();
            $table->timestamps();
        });

        // Tables the fingerprint's catalog revision aggregates over.
        foreach (['pos_products', 'pos_services', 'pos_deals'] as $t) {
            Schema::create($t, function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->timestamps();
            });
        }

        Schema::create('pos_tax_rules', function (Blueprint $table) {
            $table->id();
            $table->string('payment_method');
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // planAllows (fingerprint 'set' hash, Task 117) resolves the active
        // subscription + plan. Left EMPTY: no subscription → gate false —
        // the fingerprint just needs the lookup to work, not to pass.
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->boolean('active')->default(true);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('override_type')->default('none');
            $table->timestamp('override_until')->nullable();
            $table->timestamp('override_granted_at')->nullable();
            $table->integer('free_invoice_limit')->nullable();
            $table->timestamps();
        });
        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->default('pos');
            $table->boolean('is_trial')->default(false);
            $table->boolean('offline_enabled')->default(true);
            $table->integer('invoice_limit')->nullable();
            $table->timestamps();
        });

        // Heartbeat's self-heal sweep operates on this table.
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->timestamps();
        });

        $this->company = Company::create([
            'name' => 'Fingerprint Test Shop',
            'company_status' => 'approved',
            'pra_reporting_enabled' => true,
        ]);
        // Non-fillable columns set directly.
        $this->company->forceFill([
            'agent_api_key' => $this->agentKey,
            'agent_enabled' => true,
            'pos_theme' => 'purple',
        ])->save();

        $this->user = User::forceCreate([
            'name' => 'Cashier',
            'email' => 'cashier@fp-test.pk',
            'password' => bcrypt('secret'),
            'company_id' => $this->company->id,
            'pos_role' => 'pos_cashier',
        ]);
    }

    /** Compute the real boot fingerprint via the private controller method. */
    private function fingerprint(): array
    {
        $company = Company::findOrFail($this->company->id); // fresh row
        $controller = app(PosController::class);
        $m = new \ReflectionMethod(PosController::class, 'posBootFingerprint');
        $m->setAccessible(true);
        return $m->invoke($controller, $company, $this->user->fresh());
    }

    public function test_agent_heartbeat_leaves_boot_fingerprint_unchanged(): void
    {
        $before = $this->fingerprint();

        $res = $this->postJson('/api/agent/heartbeat', [
            'version' => '9.9.9',
            'offline_mode' => true,
            'snapshot_saved_at' => now()->subMinutes(3)->toIso8601String(),
        ], ['Authorization' => 'Bearer ' . $this->agentKey]);
        $res->assertOk();

        // Telemetry really landed…
        $fresh = Company::findOrFail($this->company->id);
        $this->assertNotNull($fresh->agent_last_seen);
        $this->assertSame('9.9.9', $fresh->agent_version);

        // …but the fingerprint is byte-identical.
        $this->assertSame($before, $this->fingerprint());
    }

    public function test_report_printers_leaves_boot_fingerprint_unchanged(): void
    {
        $before = $this->fingerprint();

        $res = $this->postJson('/api/agent/printers', [
            'printers' => [
                ['name' => 'POS-80C', 'displayName' => 'Thermal 80mm', 'isDefault' => true],
                ['name' => 'Kitchen-1'],
            ],
        ], ['Authorization' => 'Bearer ' . $this->agentKey]);
        $res->assertOk();

        // Printer inventory really landed (available_printers + reported-at beat)…
        $fresh = Company::findOrFail($this->company->id);
        $this->assertCount(2, $fresh->printerSettings()['available_printers']);
        $this->assertNotNull($fresh->printerSettings()['printers_reported_at']);

        // …but the fingerprint is byte-identical: agent-reported telemetry
        // inside pos_printer_settings must never fake a "settings change".
        $this->assertSame($before, $this->fingerprint());
    }

    public function test_updated_at_bump_alone_never_changes_fingerprint(): void
    {
        // The core Task-52 guarantee: even a future writer that DOES bump
        // companies.updated_at (unlike telemetryUpdate) cannot recreate the
        // reload loop, because the fingerprint no longer reads updated_at.
        $before = $this->fingerprint();

        Company::where('id', $this->company->id)
            ->update(['updated_at' => now()->addHours(2)]);

        $this->assertSame($before, $this->fingerprint());
    }

    public function test_real_pos_settings_change_does_change_fingerprint(): void
    {
        // Sanity guard against over-fixing: a deliberate settings write must
        // still refresh cached sale screens.
        $before = $this->fingerprint();

        $this->company->forceFill(['pos_theme' => 'emerald'])->save();
        $afterTheme = $this->fingerprint();
        $this->assertNotSame($before['set'], $afterTheme['set']);

        // Cashier-chosen printer ROUTING (not telemetry) also counts.
        $this->company->forceFill(['pos_printer_settings' => [
            'silent_print_enabled' => true,
            'receipt_printer' => 'POS-80C',
        ]])->save();
        $this->assertNotSame($afterTheme['set'], $this->fingerprint()['set']);
    }
}
