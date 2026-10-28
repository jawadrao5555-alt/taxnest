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

    public function test_invoice_diagnostic_proves_saved_card_save_math_without_pii(): void
    {
        $pizza = $this->makeCompany('Pizza Fixture');
        $other = $this->makeCompany('Other Fixture');
        $bill = $this->makeTxn($pizza, [
            'invoice_number' => 'P2240',
            'payment_method' => 'card',
            'subtotal' => 1379.31,
            'discount_amount' => 0,
            'tax_rate' => 8,
            'tax_amount' => 110.34,
            'total_amount' => 1490,
            'tax_inclusive' => true,
            'tax_menu_rate' => 16,
        ]);
        $this->makeTxn($other, [
            'invoice_number' => 'P2240',
            'subtotal' => 9999,
            'total_amount' => 9999,
        ]);
        DB::table('pos_transaction_items')->insert([
            ['transaction_id' => $bill, 'subtotal' => 700, 'is_tax_exempt' => false, 'created_at' => now(), 'updated_at' => now()],
            ['transaction_id' => $bill, 'subtotal' => 900, 'is_tax_exempt' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $report = app(LiveOpsDiagnosticsService::class)->run('INVOICE_DIAGNOSTIC', [
            'company_id' => $pizza,
            'invoice_reference' => 'P2240',
            'requester' => 'test',
        ]);

        $this->assertSame('INVOICE_DIAGNOSTIC', $report['operation']);
        $this->assertSame('inclusive_card_save', $report['data']['pricing_snapshot']['inferred_mode']);
        $this->assertSame(1600.0, $report['data']['saved_calculation']['menu_total_from_lines']);
        $this->assertSame(110.34, $report['data']['saved_calculation']['tax_amount']);
        $this->assertSame(1490.0, $report['data']['saved_calculation']['total_amount']);
        $this->assertTrue($report['data']['recalculation']['matches_saved_snapshot']);
        $this->assertTrue($report['data']['finding']['card_save_confirmed_from_saved_snapshot']);
        $this->assertFalse($report['data']['privacy']['customer_details_returned']);
        $this->assertStringNotContainsString('9999', json_encode($report));
    }

    public static function unavailableInvoiceEvidence(): array
    {
        return [
            'legacy discount column absent' => ['discount_amount'],
            'legacy exemption column absent' => ['is_tax_exempt'],
            'no saved lines' => ['empty_lines'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unavailableInvoiceEvidence')]
    public function test_invoice_diagnostic_cannot_confirm_from_unavailable_saved_evidence(string $missing): void
    {
        $company = $this->makeCompany('Legacy invoice evidence');
        $bill = $this->makeTxn($company, [
            'invoice_number' => 'LEGACY-P2240', 'payment_method' => 'card',
            'subtotal' => $missing === 'empty_lines' ? 0 : 1379.31,
            'discount_amount' => 0, 'tax_rate' => 8,
            'tax_amount' => $missing === 'empty_lines' ? 0 : 110.34,
            'total_amount' => $missing === 'empty_lines' ? 0 : 1490,
            'tax_inclusive' => true, 'tax_menu_rate' => 16,
        ]);
        if ($missing !== 'empty_lines') {
            DB::table('pos_transaction_items')->insert([
                'transaction_id' => $bill, 'subtotal' => 1600, 'is_tax_exempt' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            \Illuminate\Support\Facades\Schema::table($missing === 'is_tax_exempt' ? 'pos_transaction_items' : 'pos_transactions', function ($table) use ($missing) {
                $table->dropColumn($missing);
            });
        }
        $before = DB::table('pos_transactions')->where('id', $bill)->first();
        $data = app(\App\Services\LiveOps\LiveOpsInvoiceDiagnostic::class)->run($company, 'LEGACY-P2240');
        $this->assertFalse($data['finding']['card_save_confirmed_from_saved_snapshot']);
        $this->assertNull($data['recalculation']);
        $this->assertNotEmpty($data['evidence_gaps']);
        $this->assertEquals($before, DB::table('pos_transactions')->where('id', $bill)->first());
    }

    public function test_invoice_diagnostic_rejects_ambiguous_reference_within_same_company(): void
    {
        $company = $this->makeCompany('Duplicate reference fixture');
        $this->makeTxn($company, ['invoice_number' => 'DUP-P2240']);
        $this->makeTxn($company, ['invoice_number' => 'DUP-P2240', 'total_amount' => 9999]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('multiple saved invoices');
        app(\App\Services\LiveOps\LiveOpsInvoiceDiagnostic::class)->run($company, 'DUP-P2240');
    }

    public function test_invoice_diagnostic_requires_exact_company_scoped_reference(): void
    {
        $company = $this->makeCompany('Invoice Scope');
        $this->expectException(\InvalidArgumentException::class);
        app(LiveOpsDiagnosticsService::class)->run('INVOICE_DIAGNOSTIC', [
            'company_id' => $company,
            'invoice_reference' => '../P2240',
        ]);
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

    public function test_error_summary_global_without_company_and_redacts(): void
    {
        $a = $this->makeCompany('Err A');
        $b = $this->makeCompany('Err B');
        $this->makeTxn($a, [
            'pra_status' => 'failed',
            'pra_error_message' => 'token=supersecret-a',
        ]);
        $this->makeTxn($b, [
            'pra_status' => 'failed',
            'pra_error_message' => 'password=leak-b',
        ]);

        $report = app(LiveOpsDiagnosticsService::class)->run('ERROR_SUMMARY', [
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
            'requester' => 'test',
        ]);

        $this->assertNull($report['scope']['company_id']);
        $this->assertSame('global', $report['data']['scope']);
        $this->assertGreaterThanOrEqual(2, $report['data']['pra_failed_count']);
        $json = json_encode($report);
        $this->assertStringContainsString('Err A', $json);
        $this->assertStringContainsString('Err B', $json);
        $this->assertStringNotContainsString('supersecret-a', $json);
        $this->assertStringNotContainsString('password=leak-b', $json);
        $this->assertStringContainsString('[REDACTED]', $json);
    }

    public function test_error_summary_company_scoped_still_isolated(): void
    {
        $a = $this->makeCompany('Iso A');
        $b = $this->makeCompany('Iso B');
        $this->makeTxn($b, [
            'pra_status' => 'failed',
            'pra_error_message' => 'B-only-error-marker',
        ]);

        $report = app(LiveOpsDiagnosticsService::class)->run('ERROR_SUMMARY', [
            'company_id' => $a,
        ]);

        $json = json_encode($report);
        $this->assertStringNotContainsString('B-only-error-marker', $json);
        $this->assertStringNotContainsString('Iso B', $json);
        $this->assertArrayNotHasKey('scope', $report['data']);
    }

    public function test_pra_health_global_and_company_still_require_isolation(): void
    {
        $a = $this->makeCompany('Pra A');
        $b = $this->makeCompany('Pra B');
        $this->makeTxn($a, ['pra_status' => 'submitted']);
        $this->makeTxn($b, ['pra_status' => 'failed', 'pra_error_message' => 'B-pra-fail']);
        $this->makeTxn($b, ['pra_status' => 'submitted', 'pra_invoice_number' => 'DUP-1']);
        $this->makeTxn($b, ['pra_status' => 'submitted', 'pra_invoice_number' => 'DUP-1']);

        $fleet = app(LiveOpsDiagnosticsService::class)->run('PRA_HEALTH', [
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ]);
        $this->assertSame('global', $fleet['data']['scope']);
        $this->assertSame(1, $fleet['data']['counts']['failed']);
        $this->assertNotEmpty($fleet['data']['duplicate_invoice_numbers']);

        $scoped = app(LiveOpsDiagnosticsService::class)->run('PRA_HEALTH', [
            'company_id' => $a,
        ]);
        $this->assertSame(0, $scoped['data']['counts']['failed']);
        $this->assertStringNotContainsString('B-pra-fail', json_encode($scoped));
    }

    public function test_company_health_still_requires_company(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(LiveOpsDiagnosticsService::class)->run('COMPANY_HEALTH', []);
    }

    public function test_server_health_and_daily_ops_are_global_read_only(): void
    {
        $a = $this->makeCompany('Daily A');
        $b = $this->makeCompany('Daily Zero', ['agent_last_seen' => now()->subHours(5)]);
        $this->makeTxn($a, ['total_amount' => 200, 'pra_status' => 'submitted']);
        DB::table('failed_jobs')->insert([
            'uuid' => 'test-uuid',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{"job":"secret-token-should-not-leak"}',
            'exception' => 'RuntimeException: password=should-redact',
            'failed_at' => now(),
        ]);
        DB::table('security_logs')->insert([
            'action' => 'failed_login',
            'ip_address' => '203.0.113.9',
            'user_agent' => 'secret-ua',
            'created_at' => now(),
        ]);
        $txnBefore = DB::table('pos_transactions')->count();
        $failedBefore = DB::table('failed_jobs')->count();

        $server = app(LiveOpsDiagnosticsService::class)->run('SERVER_HEALTH', []);
        $this->assertArrayHasKey('server', $server['data']);
        $this->assertArrayHasKey('database', $server['data']);
        $this->assertTrue($server['data']['database']['connection_ok']);
        $this->assertSame('unknown', $server['data']['performance']['availability']);
        $this->assertContains($server['data']['overall']['status'], ['GREEN', 'ATTENTION', 'CRITICAL']);
        $this->assertNotEmpty($server['data']['overall']['summary']);

        $daily = app(LiveOpsDiagnosticsService::class)->run('DAILY_OPS', [
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
            'requester' => 'test',
        ]);

        $this->assertContains($daily['data']['overall']['status'], ['GREEN', 'ATTENTION', 'CRITICAL']);
        $this->assertArrayHasKey('server_health', $daily['data']);
        $this->assertArrayHasKey('application_health', $daily['data']);
        $this->assertArrayHasKey('database_health', $daily['data']);
        $this->assertArrayHasKey('queue_worker_health', $daily['data']);
        $this->assertArrayHasKey('scheduler_health', $daily['data']);
        $this->assertArrayHasKey('websocket_agent_health', $daily['data']);
        $this->assertArrayHasKey('company_activity', $daily['data']);
        $this->assertArrayHasKey('billing_by_company', $daily['data']);
        $this->assertArrayHasKey('zero_billing_companies', $daily['data']);
        $this->assertArrayHasKey('problematic_companies', $daily['data']);
        $this->assertArrayHasKey('pra_transaction_health', $daily['data']);
        $this->assertArrayHasKey('errors', $daily['data']);
        $this->assertArrayHasKey('performance', $daily['data']);
        $this->assertArrayHasKey('security_auth', $daily['data']);
        $this->assertArrayHasKey('unknown_observability_gaps', $daily['data']);
        $this->assertArrayHasKey('recommended_owner_actions', $daily['data']);
        $this->assertSame(200.0, $daily['data']['billing_by_company']['totals']['gross_total']);
        $zeroNames = collect($daily['data']['zero_billing_companies'])->pluck('name')->all();
        $this->assertContains('Daily Zero', $zeroNames);
        $this->assertSame(1, $daily['data']['queue_worker_health']['failed_jobs_count']);
        $this->assertSame(1, $daily['data']['security_auth']['failed_login_count']);

        $json = json_encode($daily);
        $this->assertStringNotContainsString('secret-token-should-not-leak', $json);
        $this->assertStringNotContainsString('password=should-redact', $json);
        $this->assertStringNotContainsString('203.0.113.9', $json);
        $this->assertStringNotContainsString('secret-ua', $json);
        $this->assertSame($txnBefore, DB::table('pos_transactions')->count());
        $this->assertSame($failedBefore, DB::table('failed_jobs')->count());
        $this->assertDatabaseHas('live_ops_audit_events', [
            'event_type' => 'diagnostic.request',
            'operation' => 'DAILY_OPS',
        ]);
        $this->assertDatabaseHas('live_ops_diagnostic_reports', [
            'operation' => 'DAILY_OPS',
        ]);
    }

    public function test_agent_fleet_counts_include_offline_companies_outside_detail_limit(): void
    {
        config(['live_ops.limits.max_companies_in_report' => 1]);
        $recent = $this->makeCompany('Synthetic Recent Shop');
        $old = $this->makeCompany('Synthetic Old Shop', ['agent_last_seen' => now()->subHours(6)]);
        $this->makeCompany('Disabled Shop', ['agent_enabled' => false]);
        $this->makeCompany('Other Product', ['product_type' => 'fbrpos']);
        $before = DB::table('companies')->orderBy('id')->get()->toJson();

        $report = app(LiveOpsDiagnosticsService::class)->run('AGENT_HEALTH', []);
        $this->assertSame(['enabled' => 2, 'online' => 1, 'long_offline' => 1], $report['data']['counts']);
        $this->assertStringContainsString('2 enabled; 1 online; 1 long offline', $report['summary_text']);
        $this->assertCount(1, $report['data']['agents']);
        $this->assertSame($recent, $report['data']['agents'][0]['company_id']);
        $this->assertSame(['total' => 2, 'returned' => 1, 'truncated' => true], $report['data']['coverage']);
        $this->assertSame($before, DB::table('companies')->orderBy('id')->get()->toJson());
        $this->assertSame(0, DB::table('live_ops_agent_commands')->count());

        $scoped = app(LiveOpsDiagnosticsService::class)->run('AGENT_HEALTH', ['company_id' => $old]);
        $this->assertSame($old, $scoped['data']['company']['id']);
        $this->assertFalse($scoped['data']['company']['agent_online']);
        $this->assertStringNotContainsString('Synthetic Recent Shop', json_encode($scoped['data']));
    }

    public function test_agent_fleet_empty_and_unlimited_detail_cases_have_explicit_coverage(): void
    {
        $empty = app(LiveOpsDiagnosticsService::class)->run('AGENT_HEALTH', []);
        $this->assertSame(['enabled' => 0, 'online' => 0, 'long_offline' => 0], $empty['data']['counts']);
        $this->assertSame(['total' => 0, 'returned' => 0, 'truncated' => false], $empty['data']['coverage']);
        $this->makeCompany('Legacy Fresh Shop');
        $this->makeCompany('Never Connected Shop', ['agent_last_seen' => null]);
        $this->makeCompany('Popup-only Shop', ['agent_last_seen' => now()->subHours(6), 'pos_printer_settings' => json_encode(['silent_print_enabled' => false])]);
        $report = app(LiveOpsDiagnosticsService::class)->run('AGENT_HEALTH', []);
        $this->assertSame(['enabled' => 3, 'online' => 1, 'long_offline' => 1], $report['data']['counts']);
        $this->assertSame(['total' => 3, 'returned' => 3, 'truncated' => false], $report['data']['coverage']);
    }

    public function test_agent_health_fleet_still_works_without_company(): void
    {
        $this->makeCompany('Agent Fleet');
        $report = app(LiveOpsDiagnosticsService::class)->run('AGENT_HEALTH', []);
        $this->assertNotEmpty($report['data']['agents']);
        $this->assertSame(1, $report['data']['counts']['enabled']);
    }
}
