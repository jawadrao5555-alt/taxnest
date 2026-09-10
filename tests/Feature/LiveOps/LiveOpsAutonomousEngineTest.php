<?php

namespace Tests\Feature\LiveOps;

use App\Services\LiveOps\LiveOpsAutonomousEngine;
use App\Services\LiveOps\LiveOpsChangeRiskClassifier;
use App\Services\LiveOps\LiveOpsDiagnosticsService;
use App\Services\LiveOps\LiveOpsOwnerReportFormatter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class LiveOpsAutonomousEngineTest extends LiveOpsTestCase
{
    public function test_daily_report_owner_cards_include_billing_print_agent_pra(): void
    {
        $pm = $this->makeCompany('Pizza Master');
        $zfc = $this->makeCompany('ZFC Pizza Point');
        $this->makeTxn($pm, ['total_amount' => 245800, 'subtotal' => 212000, 'tax_amount' => 33800]);
        $this->makeTxn($zfc, ['total_amount' => 183400]);
        DB::table('pos_print_jobs')->insert([
            'company_id' => $zfc,
            'type' => 'bill',
            'status' => 'failed',
            'error' => 'spool timeout',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $out = app(LiveOpsAutonomousEngine::class)->handle('Aaj ki report do', [
            'requester' => 'owner-test',
        ]);

        $this->assertTrue($out['ok']);
        $this->assertSame('DIAGNOSED', $out['status']);
        $this->assertSame('DAILY_OPS', $out['operation']);
        $text = $out['owner_report']['text'];
        $this->assertStringContainsString('Pizza Master', $text);
        $this->assertStringContainsString('ZFC Pizza Point', $text);
        $this->assertStringContainsString('Billing:', $text);
        $this->assertStringContainsString('Agent:', $text);
        $this->assertStringContainsString('PRA:', $text);
        $this->assertStringContainsString('Printing:', $text);
        $this->assertDatabaseHas('live_ops_autonomous_requests', [
            'request_id' => $out['request_id'],
            'status' => 'DIAGNOSED',
        ]);
        $this->assertDatabaseHas('live_ops_audit_events', [
            'event_type' => 'autonomous.DIAGNOSED',
            'action_id' => $out['request_id'],
        ]);
    }

    public function test_healthy_company_check(): void
    {
        $this->makeCompany('Pizza Master');
        $this->makeTxn(DB::table('companies')->where('name', 'Pizza Master')->value('id'));

        $out = app(LiveOpsAutonomousEngine::class)->handle('Pizza Master check karo', [
            'requester' => 'owner-test',
        ]);

        $this->assertTrue($out['ok']);
        $this->assertSame('Pizza Master', $out['company_name']);
        $this->assertNotNull($out['company_id']);
        $this->assertStringContainsString('Pizza Master', $out['owner_report']['text']);
        $this->assertStringContainsString('ONLINE', $out['owner_report']['text']);
        $this->assertSame('no_obvious_fault_from_available_signals', $out['root_cause']);
    }

    public function test_unknown_company_fails_safely(): void
    {
        $this->makeCompany('Pizza Master');
        $out = app(LiveOpsAutonomousEngine::class)->handle('No Such Shop check karo');
        $this->assertSame('FAILED', $out['status']);
        $this->assertStringContainsString('No NestPOS company', $out['owner_report']['text']);
    }

    public function test_ambiguous_company_lists_matches(): void
    {
        $this->makeCompany('Pizza Master');
        $this->makeCompany('Pizza Point');
        $out = app(LiveOpsAutonomousEngine::class)->handle('Pizza check karo');
        $this->assertSame('BLOCKED', $out['status']);
        $this->assertStringContainsString('Pizza Master', $out['owner_report']['text']);
        $this->assertStringContainsString('Pizza Point', $out['owner_report']['text']);
        $this->assertNull($out['company_id']);
    }

    public function test_print_issue_solve_path_is_evidence_backed(): void
    {
        $zfc = $this->makeCompany('ZFC Pizza Point');
        DB::table('pos_print_jobs')->insert([
            'company_id' => $zfc,
            'type' => 'bill',
            'target_printer' => 'XP-80',
            'status' => 'failed',
            'error' => 'token=supersecret-print',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $out = app(LiveOpsAutonomousEngine::class)->handle('ZFC ka printing issue solve karo', [
            'requester' => 'owner-test',
        ]);

        $json = json_encode($out);
        $this->assertStringNotContainsString('supersecret-print', $json);
        $this->assertSame('printer_or_print_pipeline', $out['root_cause']);
        $this->assertStringContainsString('ZFC Pizza Point', $out['owner_report']['text']);
        $this->assertContains($out['status'], ['RESOLVED', 'DIAGNOSED', 'VERIFYING', 'BLOCKED']);
    }

    public function test_unauthorized_operation_rejected(): void
    {
        $out = app(LiveOpsAutonomousEngine::class)->handle('gh workflow run deploy-production.yml');
        $this->assertFalse($out['ok']);
        $this->assertSame('FAILED', $out['status']);
        $this->assertStringContainsString('rejected', mb_strtolower($out['error'] ?? ''));
    }

    public function test_secret_redaction_in_owner_path(): void
    {
        $id = $this->makeCompany('Redact Shop', [
            'agent_update_error' => 'password=leak Bearer abc.def',
        ]);
        $this->makeTxn($id, [
            'pra_status' => 'failed',
            'pra_error_message' => 'api_key=should-not-leak',
        ]);

        $out = app(LiveOpsAutonomousEngine::class)->handle('Redact Shop check karo');
        $json = json_encode($out);
        $this->assertStringNotContainsString('should-not-leak', $json);
        $this->assertStringNotContainsString('password=leak', $json);
        $this->assertStringContainsString('[REDACTED]', json_encode(
            app(LiveOpsDiagnosticsService::class)->run('COMPANY_DIAGNOSTIC', [
                'company_name' => 'Redact Shop',
            ])
        ));
    }

    public function test_tenant_isolation_on_named_check(): void
    {
        $a = $this->makeCompany('Shop A');
        $b = $this->makeCompany('Shop B');
        $this->makeTxn($b, ['pra_status' => 'failed', 'pra_error_message' => 'B-only-error-marker']);

        $out = app(LiveOpsAutonomousEngine::class)->handle('Shop A check karo');
        $json = json_encode($out);
        $this->assertStringNotContainsString('B-only-error-marker', $json);
        $this->assertSame($a, $out['company_id']);
        $this->assertNotSame($b, $out['company_id']);
    }

    public function test_high_risk_paths_are_blocked_from_auto_deploy(): void
    {
        $c = app(LiveOpsChangeRiskClassifier::class);
        $high = $c->classifyPaths([
            'database/migrations/2026_01_01_000000_drop_companies.php',
            'app/Services/PosTaxMath.php',
        ]);
        $this->assertSame(LiveOpsChangeRiskClassifier::HIGH_RISK_BLOCKED, $high['class']);

        $safe = $c->classifyPaths([
            'public/js/pos-print-attempt.js',
            'pra-agent/src/printer-poll-policy.js',
        ]);
        $this->assertSame(LiveOpsChangeRiskClassifier::AUTO_DEPLOY, $safe['class']);
    }

    public function test_owner_report_formatter_matches_business_card_shape(): void
    {
        $fmt = app(LiveOpsOwnerReportFormatter::class);
        $text = $fmt->renderCard([
            'name' => 'Pizza Master',
            'billing_amount' => 245800,
            'bill_count' => 126,
            'printer' => 'PASS',
            'agent' => 'ONLINE',
            'pra' => 'PASS',
            'issues_line' => 'None',
        ]);
        $this->assertStringContainsString('Pizza Master', $text);
        $this->assertStringContainsString('Billing: Rs. 245,800', $text);
        $this->assertStringContainsString('Bills: 126', $text);
        $this->assertStringContainsString('Printing: PASS', $text);
        $this->assertStringContainsString('Agent: ONLINE', $text);
        $this->assertStringContainsString('PRA: PASS', $text);
        $this->assertStringContainsString('Issues: None', $text);
    }

    public function test_runner_owner_command_requires_token_and_returns_request_id(): void
    {
        Config::set('live_ops.runner_token', str_repeat('c', 40));
        $this->makeCompany('Fleet Shop');

        $this->postJson('/api/live-ops/v1/owner-command', [
            'text' => 'Aaj ki report do',
        ])->assertStatus(401);

        $this->postJson('/api/live-ops/v1/owner-command', [
            'text' => 'Aaj ki report do',
            'requester' => 'actions',
        ], [
            'X-Live-Ops-Token' => str_repeat('c', 40),
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('result.operation', 'DAILY_OPS')
            ->assertJsonStructure(['result' => ['request_id', 'status', 'owner_report' => ['text']]]);
    }

    public function test_max_iterations_bound_is_three(): void
    {
        $this->assertSame(3, LiveOpsAutonomousEngine::MAX_ITERATIONS);
        $this->assertSame(3, (int) config('live_ops.autonomous.max_fix_iterations'));
    }

    public function test_daily_ops_includes_owner_company_cards(): void
    {
        $id = $this->makeCompany('Card Shop');
        $this->makeTxn($id, ['total_amount' => 1000]);
        $report = app(LiveOpsDiagnosticsService::class)->run('DAILY_OPS', [
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ]);
        $this->assertNotEmpty($report['data']['owner_company_cards']);
        $names = collect($report['data']['owner_company_cards'])->pluck('name')->all();
        $this->assertContains('Card Shop', $names);
        $card = collect($report['data']['owner_company_cards'])->firstWhere('name', 'Card Shop');
        $this->assertSame(1000.0, (float) $card['billing_amount']);
        $this->assertSame('ONLINE', $card['agent']);
    }
}
