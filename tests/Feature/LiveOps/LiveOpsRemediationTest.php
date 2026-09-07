<?php

namespace Tests\Feature\LiveOps;

use App\Models\LiveOpsRemediationRequest;
use App\Services\LiveOps\LiveOpsAgentCommandService;
use App\Services\LiveOps\LiveOpsRemediationService;
use Illuminate\Support\Facades\DB;

class LiveOpsRemediationTest extends LiveOpsTestCase
{
    public function test_high_risk_rejected_on_propose(): void
    {
        $id = $this->makeCompany('Shop');
        $this->expectException(\InvalidArgumentException::class);
        app(LiveOpsRemediationService::class)->propose([
            'action' => 'REGENERATE_AGENT_API_KEY',
            'company_id' => $id,
            'parameters' => [],
        ]);
    }

    public function test_execute_requires_approval(): void
    {
        $id = $this->makeCompany('Shop');
        $row = app(LiveOpsRemediationService::class)->propose([
            'action' => 'REFRESH_OPERATIONAL_STATE',
            'company_id' => $id,
            'idempotency_key' => 'idem-1',
        ]);
        $this->expectException(\InvalidArgumentException::class);
        app(LiveOpsRemediationService::class)->execute($row->action_id);
    }

    public function test_approval_phrase_enforced(): void
    {
        $id = $this->makeCompany('Shop');
        $row = app(LiveOpsRemediationService::class)->propose([
            'action' => 'REFRESH_OPERATIONAL_STATE',
            'company_id' => $id,
            'idempotency_key' => 'idem-2',
        ]);
        $this->expectException(\InvalidArgumentException::class);
        app(LiveOpsRemediationService::class)->approve($row->action_id, [
            'owner_approval_phrase' => 'please fix',
        ]);
    }

    public function test_approved_refresh_executes_and_audits(): void
    {
        $id = $this->makeCompany('Shop');
        $svc = app(LiveOpsRemediationService::class);
        $row = $svc->propose([
            'action' => 'REFRESH_OPERATIONAL_STATE',
            'company_id' => $id,
            'idempotency_key' => 'idem-3',
            'proposal' => 'refresh',
        ]);
        $svc->approve($row->action_id, [
            'owner_approval_phrase' => LiveOpsRemediationService::APPROVAL_PHRASE,
            'approved_by' => 'owner',
        ]);
        $done = $svc->execute($row->action_id, ['executor' => 'test']);
        $this->assertSame('executed', $done->status);
        $this->assertTrue($done->execution_result['ok'] ?? false);
        $this->assertNotNull($done->verification_result);
        $this->assertDatabaseHas('live_ops_audit_events', [
            'event_type' => 'remediation.executed',
            'action_id' => $done->action_id,
        ]);

        // idempotent re-execute
        $again = $svc->execute($row->action_id);
        $this->assertSame($done->action_id, $again->action_id);
    }

    public function test_retry_one_pra_company_bound(): void
    {
        $a = $this->makeCompany('A');
        $b = $this->makeCompany('B');
        $txnB = $this->makeTxn($b, [
            'pra_status' => 'failed',
            'pra_invoice_number' => null,
        ]);

        $svc = app(LiveOpsRemediationService::class);
        $row = $svc->propose([
            'action' => 'RETRY_ONE_PRA_INVOICE',
            'company_id' => $a,
            'parameters' => ['transaction_id' => $txnB],
            'idempotency_key' => 'retry-cross',
        ]);
        $svc->approve($row->action_id, [
            'owner_approval_phrase' => LiveOpsRemediationService::APPROVAL_PHRASE,
        ]);
        $done = $svc->execute($row->action_id);
        $this->assertFalse($done->execution_result['ok'] ?? true);
        $this->assertSame('failed', $done->status);
        $this->assertSame('failed', DB::table('pos_transactions')->where('id', $txnB)->value('pra_status'));
    }

    public function test_retry_one_pra_success_for_own_failed(): void
    {
        $a = $this->makeCompany('A');
        $txn = $this->makeTxn($a, [
            'pra_status' => 'failed',
            'pra_invoice_number' => null,
            'pra_error_message' => 'timeout',
        ]);
        $svc = app(LiveOpsRemediationService::class);
        $row = $svc->propose([
            'action' => 'RETRY_ONE_PRA_INVOICE',
            'company_id' => $a,
            'parameters' => ['transaction_id' => $txn],
            'idempotency_key' => 'retry-ok',
        ]);
        $svc->approve($row->action_id, [
            'owner_approval_phrase' => LiveOpsRemediationService::APPROVAL_PHRASE,
        ]);
        $done = $svc->execute($row->action_id);
        $this->assertTrue($done->execution_result['ok'] ?? false);
        $this->assertSame('pending', DB::table('pos_transactions')->where('id', $txn)->value('pra_status'));
    }

    public function test_rebind_printer_only_known(): void
    {
        $id = $this->makeCompany('Print Shop');
        $svc = app(LiveOpsRemediationService::class);
        $bad = $svc->propose([
            'action' => 'REBIND_ASSIGNED_PRINTER',
            'company_id' => $id,
            'parameters' => ['printer' => 'UnknownPrinter'],
            'idempotency_key' => 'rebind-bad',
        ]);
        $svc->approve($bad->action_id, [
            'owner_approval_phrase' => LiveOpsRemediationService::APPROVAL_PHRASE,
        ]);
        $doneBad = $svc->execute($bad->action_id);
        $this->assertFalse($doneBad->execution_result['ok'] ?? true);

        $good = $svc->propose([
            'action' => 'REBIND_ASSIGNED_PRINTER',
            'company_id' => $id,
            'parameters' => ['printer' => 'XP-80'],
            'idempotency_key' => 'rebind-good',
        ]);
        $svc->approve($good->action_id, [
            'owner_approval_phrase' => LiveOpsRemediationService::APPROVAL_PHRASE,
        ]);
        $doneGood = $svc->execute($good->action_id);
        $this->assertTrue($doneGood->execution_result['ok'] ?? false);
    }

    public function test_enqueue_agent_command_via_remediation(): void
    {
        $id = $this->makeCompany('Cmd Shop');
        $svc = app(LiveOpsRemediationService::class);
        $row = $svc->propose([
            'action' => 'ENQUEUE_AGENT_COMMAND',
            'company_id' => $id,
            'parameters' => ['command_type' => 'RESYNC'],
            'idempotency_key' => 'cmd-1',
        ]);
        $svc->approve($row->action_id, [
            'owner_approval_phrase' => LiveOpsRemediationService::APPROVAL_PHRASE,
        ]);
        $done = $svc->execute($row->action_id);
        $this->assertTrue($done->execution_result['ok'] ?? false);
        $this->assertDatabaseHas('live_ops_agent_commands', [
            'company_id' => $id,
            'command_type' => 'RESYNC',
            'status' => 'pending',
        ]);
    }

    public function test_idempotency_returns_same_proposal(): void
    {
        $id = $this->makeCompany('Shop');
        $svc = app(LiveOpsRemediationService::class);
        $a = $svc->propose([
            'action' => 'FORCE_AGENT_UPDATE_ADVERTISE',
            'company_id' => $id,
            'idempotency_key' => 'same-key',
        ]);
        $b = $svc->propose([
            'action' => 'FORCE_AGENT_UPDATE_ADVERTISE',
            'company_id' => $id,
            'idempotency_key' => 'same-key',
        ]);
        $this->assertSame($a->action_id, $b->action_id);
        $this->assertSame(1, LiveOpsRemediationRequest::count());
    }

    public function test_arbitrary_agent_command_rejected(): void
    {
        $id = $this->makeCompany('Shop');
        $this->expectException(\InvalidArgumentException::class);
        app(LiveOpsAgentCommandService::class)->enqueue([
            'command_type' => 'SHELL',
            'company_id' => $id,
        ]);
    }
}
