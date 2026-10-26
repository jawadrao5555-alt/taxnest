<?php

namespace Tests\Feature\LiveOps;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

class LiveOpsPrinterTest extends LiveOpsTestCase
{
    public function test_explicit_printer_day_includes_older_jobs_and_reports_coverage_without_mutation(): void
    {
        $id = $this->makeCompany('Historical Print Co');
        $other = $this->makeCompany('Other Print Co');
        foreach (range(1, 60) as $i) {
            DB::table('pos_print_jobs')->insert(['company_id' => $id, 'type' => 'kot',
                'target_printer' => 'XP-80', 'status' => 'done',
                'created_at' => '2026-10-25 '.($i <= 35 ? '19:00:00' : '22:00:00'),
                'updated_at' => '2026-10-25 22:00:00']);
        }
        foreach ([[$other, '2026-10-25 19:00:00'], [$id, '2026-10-24 19:00:00']] as [$cid, $at]) {
            DB::table('pos_print_jobs')->insert(['company_id' => $cid, 'type' => 'kot',
                'target_printer' => 'OUTSIDE', 'status' => 'failed', 'created_at' => $at, 'updated_at' => $at]);
        }
        $before = DB::table('pos_print_jobs')->orderBy('id')->get()->toJson();
        $svc = app(\App\Services\LiveOps\LiveOpsDiagnosticsService::class);
        $current = $svc->run('PRINTER_HEALTH', ['company_id' => $id]);
        $this->assertCount(25, $current['data']['recent_jobs']);
        $report = $svc->run('PRINTER_HEALTH', ['company_id' => $id,
            'date_from' => '2026-10-25', 'date_to' => '2026-10-25']);
        $this->assertCount(60, $report['data']['recent_jobs']);
        $this->assertSame(60, $report['data']['job_coverage']['total_matching']);
        $this->assertFalse($report['data']['job_coverage']['truncated']);
        $this->assertStringNotContainsString('OUTSIDE', json_encode($report['data']));
        $this->assertSame(35, collect($report['data']['recent_jobs'])->filter(
            fn ($j) => str_contains($j['created_at'], '19:00:00'))->count());
        $this->assertSame($before, DB::table('pos_print_jobs')->orderBy('id')->get()->toJson());
    }

    public function test_printer_history_limit_declares_omitted_jobs(): void
    {
        config(['live_ops.limits.max_print_history_jobs' => 2]);
        $id = $this->makeCompany('Capped Print Co');
        foreach (range(1, 3) as $i) DB::table('pos_print_jobs')->insert([
            'company_id' => $id, 'type' => 'kot', 'status' => 'done',
            'created_at' => '2026-10-25 19:00:00', 'updated_at' => '2026-10-25 19:00:00']);
        $report = app(\App\Services\LiveOps\LiveOpsDiagnosticsService::class)->run('PRINTER_HEALTH',
            ['company_id' => $id, 'date_from' => '2026-10-25', 'date_to' => '2026-10-25']);
        $this->assertCount(2, $report['data']['recent_jobs']);
        $this->assertSame(3, $report['data']['job_coverage']['total_matching']);
        $this->assertSame(1, $report['data']['job_coverage']['omitted']);
        $this->assertTrue($report['data']['job_coverage']['truncated']);
    }

    public function test_enqueue_test_print_known_printer_when_online(): void
    {
        $id = $this->makeCompany('Print Co');
        $svc = app(\App\Services\LiveOps\LiveOpsRemediationService::class);
        $row = $svc->propose([
            'action' => 'ENQUEUE_TEST_PRINT',
            'company_id' => $id,
            'parameters' => ['printer' => 'XP-80'],
            'idempotency_key' => 'tp-1',
        ]);
        $svc->approve($row->action_id, [
            'owner_approval_phrase' => \App\Services\LiveOps\LiveOpsRemediationService::APPROVAL_PHRASE,
        ]);
        $done = $svc->execute($row->action_id);
        $this->assertTrue($done->execution_result['ok'] ?? false);
        $this->assertDatabaseHas('pos_print_jobs', [
            'company_id' => $id,
            'type' => 'test',
            'target_printer' => 'XP-80',
            'status' => 'pending',
        ]);
    }

    public function test_test_print_fails_when_agent_offline(): void
    {
        $id = $this->makeCompany('Offline Print', [
            'agent_last_seen' => now()->subMinutes(10),
        ]);
        $svc = app(\App\Services\LiveOps\LiveOpsRemediationService::class);
        $row = $svc->propose([
            'action' => 'ENQUEUE_TEST_PRINT',
            'company_id' => $id,
            'parameters' => ['printer' => 'XP-80'],
            'idempotency_key' => 'tp-off',
        ]);
        $svc->approve($row->action_id, [
            'owner_approval_phrase' => \App\Services\LiveOps\LiveOpsRemediationService::APPROVAL_PHRASE,
        ]);
        $done = $svc->execute($row->action_id);
        $this->assertFalse($done->execution_result['ok'] ?? true);
        $this->assertSame('agent_offline', $done->execution_result['error'] ?? null);
    }

    public function test_printer_health_redacts_and_scopes(): void
    {
        $a = $this->makeCompany('A');
        $b = $this->makeCompany('B');
        DB::table('pos_print_jobs')->insert([
            'company_id' => $b,
            'type' => 'bill',
            'target_printer' => 'SecretB',
            'status' => 'failed',
            'error' => 'api_key=should-not-leak',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $report = app(\App\Services\LiveOps\LiveOpsDiagnosticsService::class)->run('PRINTER_HEALTH', [
            'company_id' => $a,
        ]);
        $json = json_encode($report);
        $this->assertStringNotContainsString('SecretB', $json);
        $this->assertStringNotContainsString('should-not-leak', $json);
        $this->assertArrayHasKey('kot_chain', $report['data']);
        $this->assertSame(0, $report['data']['kot_chain']['kot_jobs']);
    }
}
