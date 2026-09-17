<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;

/**
 * Task #1209 — self-update telemetry lifecycle on the agent heartbeat.
 *
 * A stuck update attempt (e.g. the v1.9.0 fixed-Temp-dir EPERM trap) is
 * recorded via update_target/stage/error fields. After a SUCCESSFUL update
 * the new agent never re-sends the old attempt's telemetry, so the heartbeat
 * must clear the stale columns once the reported version has reached (or
 * passed) the stored target — otherwise the shop looks stuck forever in
 * saas-admin. A shop still below the target must keep its telemetry.
 */
class AgentHeartbeatUpdateTelemetryClearTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->boolean('agent_enabled')->default(true);
            $table->boolean('agent_submits_pra')->default(false);
            $table->string('agent_api_key')->nullable();
            $table->string('agent_version')->nullable();
            $table->timestamp('agent_last_seen')->nullable();
            $table->string('agent_update_target')->nullable();
            $table->string('agent_update_stage')->nullable();
            $table->text('agent_update_error')->nullable();
            $table->timestamp('agent_update_at')->nullable();
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->string('fbr_connection_mode')->nullable();
            $table->text('pos_printer_settings')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('pra_invoice_number')->nullable();
            $table->string('pra_status')->nullable();
            $table->timestamps();
        });

        // agentUpdateInfo reads the cached GitHub latest-release info —
        // pre-seed the cache so no real HTTP call happens in tests.
        Cache::put('taxnest_agent_latest_release', ['tag' => null, 'assets' => [], 'available' => false, 'reason' => 'release_unavailable'], 600);
    }

    private function makeCompany(array $attrs = []): Company
    {
        return Company::query()->forceCreate(array_merge([
            'name' => 'EPERM Shop',
            'product_type' => 'pos',
            'agent_enabled' => true,
            'agent_api_key' => 'test-agent-key-1209',
            'agent_version' => '1.9.0',
            'agent_update_target' => '1.9.1',
            'agent_update_stage' => 'download',
            'agent_update_error' => 'EPERM, Permission denied: taxnest-agent-update',
            'agent_update_at' => now()->subHours(5),
        ], $attrs));
    }

    private function beat(string $version): void
    {
        $this->postJson('/api/agent/heartbeat', ['version' => $version], [
            'Authorization' => 'Bearer test-agent-key-1209',
        ])->assertOk();
    }

    public function test_reaching_target_version_clears_stale_update_telemetry(): void
    {
        $company = $this->makeCompany();

        $this->beat('1.9.1');

        $company->refresh();
        $this->assertSame('1.9.1', $company->agent_version);
        $this->assertNull($company->agent_update_target);
        $this->assertNull($company->agent_update_stage);
        $this->assertNull($company->agent_update_error);
        $this->assertNull($company->agent_update_at);
    }

    public function test_passing_target_version_also_clears(): void
    {
        $company = $this->makeCompany();

        $this->beat('1.10.0');

        $company->refresh();
        $this->assertNull($company->agent_update_target);
        $this->assertNull($company->agent_update_error);
    }

    public function test_still_below_target_keeps_telemetry(): void
    {
        $company = $this->makeCompany();

        $this->beat('1.9.0');

        $company->refresh();
        $this->assertSame('1.9.1', $company->agent_update_target);
        $this->assertSame('download', $company->agent_update_stage);
        $this->assertNotNull($company->agent_update_error);
        $this->assertNotNull($company->agent_update_at);
    }

    public function test_fresh_failure_report_still_writes_telemetry(): void
    {
        $company = $this->makeCompany([
            'agent_update_target' => null,
            'agent_update_stage' => null,
            'agent_update_error' => null,
            'agent_update_at' => null,
        ]);

        $this->postJson('/api/agent/heartbeat', [
            'version' => '1.9.0',
            'update_target' => '1.9.1',
            'update_stage' => 'download',
            'update_error' => 'EPERM, Permission denied',
            'update_attempted_at' => now()->toISOString(),
        ], ['Authorization' => 'Bearer test-agent-key-1209'])->assertOk();

        $company->refresh();
        $this->assertSame('1.9.1', $company->agent_update_target);
        $this->assertSame('download', $company->agent_update_stage);
    }

    public function test_pending_invoice_poll_does_not_write_a_fresh_last_seen(): void
    {
        $company = $this->makeCompany([
            'agent_submits_pra' => false,
            'agent_last_seen' => now()->subSeconds(5),
        ]);
        $request = Request::create('/api/agent/pending-invoices', 'GET');
        $request->attributes->set('agent_company', $company);

        (new \App\Http\Controllers\AgentController())->pendingInvoices($request);

        $company->refresh();
        $this->assertEqualsWithDelta(
            now()->subSeconds(5)->timestamp,
            $company->agent_last_seen->timestamp,
            2,
            'A frequent poll must not rewrite a fresh last-seen timestamp'
        );
    }

    public function test_pending_invoice_poll_refreshes_an_old_last_seen(): void
    {
        $company = $this->makeCompany([
            'agent_submits_pra' => false,
            'agent_last_seen' => now()->subMinute(),
        ]);
        $oldTimestamp = $company->agent_last_seen->timestamp;
        $request = Request::create('/api/agent/pending-invoices', 'GET');
        $request->attributes->set('agent_company', $company);

        (new \App\Http\Controllers\AgentController())->pendingInvoices($request);

        $company->refresh();
        $this->assertGreaterThan($oldTimestamp, $company->agent_last_seen->timestamp);
    }

    public function test_heartbeat_stores_bounded_diagnostics_without_overwriting_printer_configuration(): void
    {
        $company = $this->makeCompany([
            'pos_printer_settings' => [
                'receipt_printer' => 'Counter thermal',
                'silent_print_enabled' => true,
            ],
        ]);

        $this->postJson('/api/agent/heartbeat', [
            'version' => '1.9.0',
            'agent_diagnostics' => [
                'process_online' => false,
                'printer' => ['printing_enabled' => true, 'printers_reported' => 999, 'healthy' => false],
                'fiscal_connectivity' => ['state' => 'reachable', 'checked_at' => '2026-08-01T11:58:00Z'],
                'queue' => ['pending_callbacks' => 100001, 'last_sync_at' => '2026-08-01T11:57:00Z'],
            ],
        ], ['Authorization' => 'Bearer test-agent-key-1209'])->assertOk();

        $settings = (array) $company->fresh()->pos_printer_settings;
        $this->assertSame('Counter thermal', $settings['receipt_printer']);
        $this->assertTrue($settings['silent_print_enabled']);
        $this->assertTrue($settings['agent_diagnostics']['process_online'], 'an authenticated heartbeat proves process liveness');
        $this->assertSame(100, $settings['agent_diagnostics']['printer']['printers_reported']);
        $this->assertSame(100000, $settings['agent_diagnostics']['queue']['pending_callbacks']);
        $this->assertSame('reachable', $settings['agent_diagnostics']['fiscal_connectivity']['state']);
    }

    public function test_old_agent_heartbeat_does_not_create_or_rewrite_diagnostics(): void
    {
        $company = $this->makeCompany([
            'pos_printer_settings' => ['receipt_printer' => 'Existing thermal'],
        ]);
        $this->beat('1.9.0');

        $settings = (array) $company->fresh()->pos_printer_settings;
        $this->assertSame(['receipt_printer' => 'Existing thermal'], $settings);
    }
}
