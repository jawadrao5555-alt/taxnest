<?php

namespace Tests\Feature\LiveOps;

use App\Services\LiveOps\LiveOpsDiagnosticsService;
use App\Services\LiveOps\LiveOpsRedactor;
use Illuminate\Support\Facades\DB;

class LiveOpsDiagnosticsTest extends LiveOpsTestCase
{
    public function test_company_diagnostic_isolated_and_redacted(): void
    {
        $a = $this->makeCompany('Alpha Shop', [
            'agent_update_error' => 'token=supersecret Bearer abc.def',
        ]);
        $b = $this->makeCompany('Beta Shop');
        $this->makeTxn($a, ['total_amount' => 500, 'pra_status' => 'submitted']);
        $this->makeTxn($b, ['total_amount' => 9999, 'pra_status' => 'submitted']);
        DB::table('pos_transactions')->insert([
            'company_id' => $a,
            'status' => 'completed',
            'subtotal' => 50,
            'tax_amount' => 8,
            'total_amount' => 58,
            'pra_status' => 'failed',
            'pra_error_message' => 'password=leak Bearer xyz',
            'business_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $report = app(LiveOpsDiagnosticsService::class)->run('COMPANY_DIAGNOSTIC', [
            'company_id' => $a,
            'requester' => 'test',
        ]);

        $this->assertSame('COMPANY_DIAGNOSTIC', $report['operation']);
        $this->assertNotEmpty($report['artifact_digest']);
        $this->assertSame($a, $report['data']['company']['id']);
        $this->assertSame('Alpha Shop', $report['data']['company']['name']);
        $json = json_encode($report);
        $this->assertStringNotContainsString('Beta Shop', $json);
        $this->assertStringNotContainsString('9999', $json);
        $this->assertStringNotContainsString('supersecret', $json);
        $this->assertStringNotContainsString('password=leak', $json);
        $this->assertStringContainsString('[REDACTED]', $json);
        $this->assertDatabaseHas('live_ops_audit_events', [
            'event_type' => 'diagnostic.request',
            'company_id' => $a,
        ]);
    }

    public function test_billing_summary_aggregates_authoritative_totals(): void
    {
        $a = $this->makeCompany('Bill A');
        $b = $this->makeCompany('Bill B');
        $this->makeTxn($a, ['subtotal' => 100, 'tax_amount' => 16, 'total_amount' => 116]);
        $this->makeTxn($a, ['subtotal' => 200, 'tax_amount' => 32, 'total_amount' => 232]);
        $this->makeTxn($b, ['subtotal' => 50, 'tax_amount' => 8, 'total_amount' => 58]);

        $report = app(LiveOpsDiagnosticsService::class)->run('BILLING_BY_COMPANY', [
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ]);

        $this->assertSame(3, $report['data']['totals']['invoice_count']);
        $this->assertEquals(406.0, $report['data']['totals']['gross_total']);
        $this->assertCount(2, $report['data']['companies']);
    }

    public function test_cross_company_pra_health_cannot_see_other_company(): void
    {
        $a = $this->makeCompany('A');
        $b = $this->makeCompany('B');
        $this->makeTxn($b, [
            'pra_status' => 'failed',
            'pra_invoice_number' => null,
            'pra_error_message' => 'B-only-error-marker',
        ]);

        $report = app(LiveOpsDiagnosticsService::class)->run('PRA_HEALTH', [
            'company_id' => $a,
        ]);

        $json = json_encode($report);
        $this->assertStringNotContainsString('B-only-error-marker', $json);
        $this->assertSame(0, $report['data']['counts']['failed']);
    }

    public function test_rejects_unknown_operation_and_wide_date_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(LiveOpsDiagnosticsService::class)->run('DROP_TABLE', []);
    }

    public function test_date_range_limit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(LiveOpsDiagnosticsService::class)->run('BILLING_SUMMARY', [
            'date_from' => now()->subDays(40)->toDateString(),
            'date_to' => now()->toDateString(),
        ]);
    }

    public function test_fbr_company_out_of_scope(): void
    {
        $id = $this->makeCompany('FBR Shop', ['product_type' => 'fbrpos']);
        $this->expectException(\InvalidArgumentException::class);
        app(LiveOpsDiagnosticsService::class)->run('COMPANY_HEALTH', ['company_id' => $id]);
    }

    public function test_redactor_strips_secrets(): void
    {
        $r = app(LiveOpsRedactor::class);
        $out = $r->redact([
            'api_key' => 'abc',
            'note' => 'Bearer tokensecret',
            'nested' => ['password' => 'x'],
        ]);
        $this->assertSame('[REDACTED]', $out['api_key']);
        $this->assertSame('[REDACTED]', $out['nested']['password']);
        $this->assertStringContainsString('[REDACTED]', $out['note']);
    }

    public function test_problematic_companies_lists_offline_and_zero_billing(): void
    {
        $online = $this->makeCompany('Online Biller');
        $this->makeTxn($online);
        $this->makeCompany('Offline Zero', [
            'agent_last_seen' => now()->subHours(5),
        ]);

        $report = app(LiveOpsDiagnosticsService::class)->run('PROBLEMATIC_COMPANIES', [
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ]);

        $offlineNames = collect($report['data']['offline_agents'])->pluck('name')->all();
        $this->assertContains('Offline Zero', $offlineNames);
        $zeroNames = collect($report['data']['zero_billing'])->pluck('name')->all();
        $this->assertContains('Offline Zero', $zeroNames);
        $this->assertNotContains('Online Biller', $zeroNames);
    }
}
