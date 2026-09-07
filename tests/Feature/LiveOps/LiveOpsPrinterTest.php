<?php

namespace Tests\Feature\LiveOps;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

class LiveOpsPrinterTest extends LiveOpsTestCase
{
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
    }
}
