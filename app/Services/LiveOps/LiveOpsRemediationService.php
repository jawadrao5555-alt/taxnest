<?php

namespace App\Services\LiveOps;

use App\Models\Company;
use App\Models\LiveOpsRemediationRequest;
use App\Models\PosAgentDevice;
use App\Models\PosPrintJob;
use App\Models\PosTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Owner-directed allow-listed remediation. Diagnosis never auto-executes.
 * High-risk actions are rejected.
 */
class LiveOpsRemediationService
{
    public const APPROVAL_PHRASE = 'OWNER_APPROVES_LIVE_OPS_FIX';

    public function __construct(
        private LiveOpsRedactor $redactor,
        private LiveOpsAuditService $audit,
        private LiveOpsAgentCommandService $commands,
        private LiveOpsDiagnosticsService $diagnostics,
    ) {
    }

    public function riskFor(string $action): string
    {
        $action = strtoupper($action);
        foreach (['low', 'medium', 'high_denied'] as $tier) {
            if (in_array($action, config("live_ops.remediation_actions.{$tier}", []), true)) {
                return $tier === 'high_denied' ? 'high' : $tier;
            }
        }

        return 'unknown';
    }

    public function propose(array $input): LiveOpsRemediationRequest
    {
        if (!Schema::hasTable('live_ops_remediation_requests')) {
            throw new \RuntimeException('live_ops_remediation_requests table missing — run migrations');
        }

        $action = strtoupper(trim((string) ($input['action'] ?? '')));
        $risk = $this->riskFor($action);
        if ($risk === 'high' || $risk === 'unknown') {
            throw new \InvalidArgumentException("Action '{$action}' is denied for Live Ops remediation (risk={$risk})");
        }

        $companyId = (int) ($input['company_id'] ?? 0);
        if ($companyId < 1) {
            throw new \InvalidArgumentException('company_id is required');
        }
        $this->assertPosCompany($companyId);

        $parameters = is_array($input['parameters'] ?? null) ? $input['parameters'] : [];
        $parameters = $this->redactor->redact($parameters);
        $hash = $this->redactor->paramsHash($parameters);
        $idempotency = trim((string) ($input['idempotency_key'] ?? ''));
        if ($idempotency === '') {
            $idempotency = 'auto-'.hash('sha256', $action.'|'.$companyId.'|'.$hash.'|'.now()->format('YmdHi'));
        }
        $idempotency = mb_substr($idempotency, 0, 80);

        $existing = LiveOpsRemediationRequest::where('idempotency_key', $idempotency)->first();
        if ($existing) {
            return $existing;
        }

        $ttlHours = (int) config('live_ops.limits.remediation_ttl_hours', 24);
        $row = LiveOpsRemediationRequest::create([
            'action_id' => (string) Str::ulid(),
            'action' => $action,
            'risk' => $risk,
            'company_id' => $companyId,
            'parameters' => $parameters,
            'parameters_hash' => $hash,
            'idempotency_key' => $idempotency,
            'requester' => isset($input['requester']) ? mb_substr((string) $input['requester'], 0, 120) : null,
            'proposal' => isset($input['proposal']) ? (string) $input['proposal'] : null,
            'evidence' => $this->redactor->redact(is_array($input['evidence'] ?? null) ? $input['evidence'] : []),
            'status' => 'proposed',
            'expires_at' => now()->addHours($ttlHours),
        ]);

        $this->audit->record(
            eventType: 'remediation.proposed',
            requester: $row->requester,
            companyId: $companyId,
            operation: $action,
            actionId: $row->action_id,
            params: $parameters,
            resultStatus: 'proposed',
            metadata: ['risk' => $risk],
            adminId: isset($input['admin_id']) ? (int) $input['admin_id'] : null,
        );

        return $row;
    }

    public function approve(string $actionId, array $input): LiveOpsRemediationRequest
    {
        $row = LiveOpsRemediationRequest::where('action_id', $actionId)->firstOrFail();
        if ($row->isExpired()) {
            $row->update(['status' => 'expired']);
            throw new \InvalidArgumentException('Remediation request expired');
        }
        if (!in_array($row->status, ['proposed', 'approved'], true)) {
            throw new \InvalidArgumentException("Cannot approve status={$row->status}");
        }

        $phrase = trim((string) ($input['owner_approval_phrase'] ?? ''));
        if ($phrase !== self::APPROVAL_PHRASE) {
            throw new \InvalidArgumentException('Explicit owner approval phrase required: '.self::APPROVAL_PHRASE);
        }

        // Re-check deny list at approval time
        if ($this->riskFor($row->action) === 'high') {
            throw new \InvalidArgumentException('High-risk action cannot be approved via Live Ops');
        }

        $row->update([
            'status' => 'approved',
            'approved_by' => isset($input['approved_by']) ? mb_substr((string) $input['approved_by'], 0, 120) : 'owner',
            'approved_at' => now(),
            'owner_approval_phrase' => self::APPROVAL_PHRASE,
        ]);

        $this->audit->record(
            eventType: 'remediation.approved',
            requester: $row->approved_by,
            companyId: $row->company_id,
            operation: $row->action,
            actionId: $row->action_id,
            params: $row->parameters,
            resultStatus: 'approved',
            adminId: isset($input['admin_id']) ? (int) $input['admin_id'] : null,
        );

        return $row->fresh();
    }

    public function reject(string $actionId, array $input = []): LiveOpsRemediationRequest
    {
        $row = LiveOpsRemediationRequest::where('action_id', $actionId)->firstOrFail();
        $row->update(['status' => 'rejected']);
        $this->audit->record(
            eventType: 'remediation.rejected',
            requester: $input['rejected_by'] ?? $row->requester,
            companyId: $row->company_id,
            operation: $row->action,
            actionId: $row->action_id,
            resultStatus: 'rejected',
            adminId: isset($input['admin_id']) ? (int) $input['admin_id'] : null,
        );

        return $row->fresh();
    }

    /**
     * Execute only when status=approved. Idempotent on already-executed rows.
     */
    public function execute(string $actionId, array $input = []): LiveOpsRemediationRequest
    {
        $row = LiveOpsRemediationRequest::where('action_id', $actionId)->firstOrFail();

        if (in_array($row->status, ['executed', 'failed'], true) && !empty($row->execution_result)) {
            return $row; // idempotent replay
        }

        if ($row->isExpired()) {
            $row->update(['status' => 'expired']);
            throw new \InvalidArgumentException('Remediation request expired');
        }
        if ($row->status !== 'approved') {
            throw new \InvalidArgumentException('Remediation must be approved before execution (got '.$row->status.')');
        }
        if ($this->riskFor($row->action) === 'high') {
            throw new \InvalidArgumentException('High-risk action blocked at execution');
        }

        try {
            $result = $this->dispatch($row);
            $verify = $this->verify($row, $result);
            $row->update([
                'status' => !empty($result['ok']) ? 'executed' : 'failed',
                'execution_result' => $this->redactor->redact($result),
                'verification_result' => $this->redactor->redact($verify),
                'executed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $row->update([
                'status' => 'failed',
                'execution_result' => ['ok' => false, 'error' => $this->redactor->redactString($e->getMessage())],
                'executed_at' => now(),
            ]);
            $this->audit->record(
                eventType: 'remediation.failed',
                requester: $input['executor'] ?? 'runner',
                companyId: $row->company_id,
                operation: $row->action,
                actionId: $row->action_id,
                resultStatus: 'failed',
                metadata: ['error' => $this->redactor->redactString($e->getMessage())],
            );
            throw $e;
        }

        $this->audit->record(
            eventType: 'remediation.executed',
            requester: $input['executor'] ?? 'runner',
            companyId: $row->company_id,
            operation: $row->action,
            actionId: $row->action_id,
            params: $row->parameters,
            resultStatus: $row->status,
            metadata: [
                'execution' => $row->execution_result,
                'verification' => $row->verification_result,
            ],
        );

        return $row->fresh();
    }

    private function dispatch(LiveOpsRemediationRequest $row): array
    {
        return match ($row->action) {
            'ENQUEUE_TEST_PRINT' => $this->enqueueTestPrint($row),
            'FORCE_AGENT_UPDATE_ADVERTISE' => $this->forceAgentUpdate($row),
            'REFRESH_OPERATIONAL_STATE' => $this->refreshOperationalState($row),
            'RETRY_ONE_PRA_INVOICE' => $this->retryOnePra($row),
            'REBIND_ASSIGNED_PRINTER' => $this->rebindPrinter($row),
            'ENQUEUE_AGENT_COMMAND' => $this->enqueueAgentCommand($row),
            default => throw new \InvalidArgumentException('Unsupported action '.$row->action),
        };
    }

    private function verify(LiveOpsRemediationRequest $row, array $result): array
    {
        try {
            $diag = $this->diagnostics->run('COMPANY_HEALTH', [
                'company_id' => $row->company_id,
                'requester' => 'live-ops-verify',
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $this->redactor->redactString($e->getMessage())];
        }

        return [
            'ok' => !empty($result['ok']),
            'post_company_online' => $diag['data']['company']['agent_online'] ?? null,
            'report_id' => $diag['report_id'] ?? null,
            'notes' => $result['verify_notes'] ?? null,
        ];
    }

    private function assertPosCompany(int $companyId): Company
    {
        $c = Company::where('id', $companyId)
            ->whereIn('product_type', config('live_ops.product_types', ['pos']))
            ->first();
        if (!$c) {
            throw new \InvalidArgumentException("Company {$companyId} not in NestPOS PRA scope");
        }

        return $c;
    }

    private function enqueueTestPrint(LiveOpsRemediationRequest $row): array
    {
        $company = $this->assertPosCompany($row->company_id);
        $printer = trim((string) ($row->parameters['printer'] ?? ''));
        $deviceUid = trim((string) ($row->parameters['device_uid'] ?? ''));

        if ($printer === '') {
            $printer = (string) ($company->printerSettings()['receipt_printer'] ?? '');
        }
        if ($printer === '') {
            return ['ok' => false, 'error' => 'No printer specified or assigned'];
        }

        $known = collect($company->printerSettings()['available_printers'] ?? [])->pluck('name')->all();
        $deviceAware = \App\Http\Controllers\AgentController::deviceRoutingReady();

        if ($deviceUid !== '' && $deviceAware) {
            $device = PosAgentDevice::where('company_id', $company->id)->where('device_uid', $deviceUid)->first();
            if (!$device) {
                return ['ok' => false, 'error' => 'unknown_device'];
            }
            $own = collect($device->printers ?? [])->pluck('name')->all();
            if (!in_array($printer, $own, true)) {
                return ['ok' => false, 'error' => 'printer_not_reported_by_device'];
            }
            if (!$device->isOnline()) {
                return ['ok' => false, 'error' => 'device_offline', 'verify_notes' => 'Agent/device offline — test print queued only if online'];
            }
        } else {
            if (!in_array($printer, $known, true)) {
                return ['ok' => false, 'error' => 'printer_not_in_discovered_list'];
            }
            if (!$company->agentOnline()) {
                return ['ok' => false, 'error' => 'agent_offline'];
            }
            $deviceUid = '';
        }

        if (!Schema::hasTable('pos_print_jobs')) {
            return ['ok' => false, 'error' => 'pos_print_jobs missing'];
        }

        $payload = [
            'company_id' => $company->id,
            'type' => 'test',
            'target_printer' => $printer,
            'status' => 'pending',
            'created_by' => null,
        ];
        if ($deviceUid !== '' && $deviceAware) {
            $payload['device_uid'] = $deviceUid;
        }
        $job = PosPrintJob::create($payload);

        return [
            'ok' => true,
            'job_id' => $job->id,
            'printer' => $printer,
            'device_uid' => $deviceUid ?: null,
            'verify_notes' => 'Test print job enqueued; agent must claim while online',
        ];
    }

    private function forceAgentUpdate(LiveOpsRemediationRequest $row): array
    {
        $company = $this->assertPosCompany($row->company_id);
        if (!Schema::hasColumn('companies', 'agent_force_update_at')) {
            return ['ok' => false, 'error' => 'agent_force_update_at column missing'];
        }
        $company->timestamps = false;
        try {
            $company->update(['agent_force_update_at' => now()]);
        } finally {
            $company->timestamps = true;
        }

        return [
            'ok' => true,
            'agent_force_update_at' => now()->toIso8601String(),
            'verify_notes' => 'Next heartbeat will advertise force_update; agent applies when online',
        ];
    }

    private function refreshOperationalState(LiveOpsRemediationRequest $row): array
    {
        // Soft refresh: expire stale pending commands + return fresh health snapshot pointers.
        if (Schema::hasTable('live_ops_agent_commands')) {
            \App\Models\LiveOpsAgentCommand::where('company_id', $row->company_id)
                ->where('status', 'pending')
                ->where('expires_at', '<=', now())
                ->update(['status' => 'expired']);
        }

        return [
            'ok' => true,
            'verify_notes' => 'Operational state refresh completed (expired stale commands)',
        ];
    }

    private function retryOnePra(LiveOpsRemediationRequest $row): array
    {
        $company = $this->assertPosCompany($row->company_id);
        $txnId = (int) ($row->parameters['transaction_id'] ?? 0);
        if ($txnId < 1) {
            return ['ok' => false, 'error' => 'transaction_id required'];
        }

        $txn = PosTransaction::where('company_id', $company->id)->where('id', $txnId)->first();
        if (!$txn) {
            return ['ok' => false, 'error' => 'transaction_not_found_for_company'];
        }
        if (!empty(trim((string) ($txn->pra_invoice_number ?? '')))) {
            return ['ok' => false, 'error' => 'already_has_pra_invoice_number'];
        }
        if ($txn->pra_status === 'submitted') {
            return ['ok' => false, 'error' => 'already_submitted'];
        }
        if (!in_array($txn->pra_status, ['failed', 'pending', 'offline'], true)) {
            return ['ok' => false, 'error' => 'status_not_retryable:'.($txn->pra_status ?? 'null')];
        }

        // Preserve fiscal invariants: only re-queue for agent (Agent Sync) or leave pending.
        // Do NOT call PRA from cloud runner with production tokens here.
        $txn->update([
            'pra_status' => 'pending',
            'pra_response_code' => null,
            // Keep pra_error_message for history; agent/submit-result will overwrite.
        ]);

        return [
            'ok' => true,
            'transaction_id' => $txn->id,
            'new_pra_status' => 'pending',
            'verify_notes' => $company->agentHandlesPra()
                ? 'Re-queued for Desktop Agent poll (Agent Sync mode)'
                : 'Set pending; Direct Production shops may need shop-side retry if agent does not submit',
        ];
    }

    private function rebindPrinter(LiveOpsRemediationRequest $row): array
    {
        $company = $this->assertPosCompany($row->company_id);
        $printer = trim((string) ($row->parameters['printer'] ?? ''));
        $deviceUid = trim((string) ($row->parameters['device_uid'] ?? ''));
        if ($printer === '') {
            return ['ok' => false, 'error' => 'printer required'];
        }

        $deviceAware = \App\Http\Controllers\AgentController::deviceRoutingReady();
        if ($deviceUid !== '' && $deviceAware) {
            $device = PosAgentDevice::where('company_id', $company->id)->where('device_uid', $deviceUid)->first();
            if (!$device) {
                return ['ok' => false, 'error' => 'unknown_device'];
            }
            $own = collect($device->printers ?? [])->pluck('name')->all();
            if (!in_array($printer, $own, true)) {
                return ['ok' => false, 'error' => 'printer_not_reported_by_device'];
            }
            $device->update(['receipt_printer' => $printer]);
        } else {
            $known = collect($company->printerSettings()['available_printers'] ?? [])->pluck('name')->all();
            if (!in_array($printer, $known, true)) {
                return ['ok' => false, 'error' => 'printer_not_in_discovered_list'];
            }
        }

        // Only receipt_printer is rebound; merged onto the FRESH row under a lock
        // (Company::mergeJsonColumn) so an agent printers report or a panel save
        // landing meanwhile keeps its keys. Raw map, no reshaping — as before.
        $company->timestamps = false;
        try {
            $company->mergeJsonColumn('pos_printer_settings', function (array $settings) use ($printer): array {
                $settings['receipt_printer'] = $printer;
                return $settings;
            });
        } finally {
            $company->timestamps = true;
        }

        return [
            'ok' => true,
            'printer' => $printer,
            'device_uid' => $deviceUid ?: null,
            'verify_notes' => 'Rebound only to agent-reported printer name',
        ];
    }

    private function enqueueAgentCommand(LiveOpsRemediationRequest $row): array
    {
        $type = strtoupper(trim((string) ($row->parameters['command_type'] ?? '')));
        $cmd = $this->commands->enqueue([
            'command_type' => $type,
            'company_id' => $row->company_id,
            'device_uid' => $row->parameters['device_uid'] ?? null,
            'payload' => is_array($row->parameters['payload'] ?? null) ? $row->parameters['payload'] : [],
            'requested_by' => $row->requester ?? 'remediation',
            'remediation_action_id' => $row->action_id,
            'idempotency_key' => 'cmd-'.$row->idempotency_key,
        ]);

        return [
            'ok' => true,
            'command_id' => $cmd->command_id,
            'command_type' => $cmd->command_type,
            'expires_at' => optional($cmd->expires_at)?->toIso8601String(),
            'verify_notes' => 'Command pending until online agent ACKs; offline agents will leave it until expiry',
        ];
    }
}
