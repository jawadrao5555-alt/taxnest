<?php

namespace App\Services\LiveOps;

use App\Models\Company;
use App\Models\LiveOpsAgentCommand;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Narrow pending-command channel for Desktop Agent (allow-listed types only).
 */
class LiveOpsAgentCommandService
{
    public function __construct(
        private LiveOpsRedactor $redactor,
        private LiveOpsAuditService $audit,
    ) {
    }

    public function enqueue(array $input): LiveOpsAgentCommand
    {
        if (!Schema::hasTable('live_ops_agent_commands')) {
            throw new \RuntimeException('live_ops_agent_commands table missing');
        }

        $type = strtoupper(trim((string) ($input['command_type'] ?? '')));
        $allowed = config('live_ops.agent_commands', []);
        if (!in_array($type, $allowed, true)) {
            throw new \InvalidArgumentException("Command type '{$type}' is not allow-listed");
        }

        $companyId = (int) ($input['company_id'] ?? 0);
        if ($companyId < 1) {
            throw new \InvalidArgumentException('company_id required');
        }
        $company = Company::where('id', $companyId)
            ->whereIn('product_type', config('live_ops.product_types', ['pos']))
            ->first();
        if (!$company) {
            throw new \InvalidArgumentException('Company not in NestPOS PRA scope');
        }

        $deviceUid = isset($input['device_uid']) ? trim((string) $input['device_uid']) : null;
        if ($deviceUid === '') {
            $deviceUid = null;
        }
        if ($deviceUid !== null && (strlen($deviceUid) > 64 || !preg_match('/^[A-Za-z0-9._-]+$/', $deviceUid))) {
            throw new \InvalidArgumentException('Invalid device_uid');
        }

        $payload = $this->redactor->redact(is_array($input['payload'] ?? null) ? $input['payload'] : []);
        // Strip any attempt to smuggle shell/sql
        unset($payload['shell'], $payload['sql'], $payload['artisan'], $payload['command']);

        $idempotency = trim((string) ($input['idempotency_key'] ?? ''));
        if ($idempotency === '') {
            $idempotency = 'cmd-'.hash('sha256', $type.'|'.$companyId.'|'.($deviceUid ?? '').'|'.json_encode($payload).'|'.now()->format('YmdHi'));
        }
        $idempotency = mb_substr($idempotency, 0, 80);

        $existing = LiveOpsAgentCommand::where('idempotency_key', $idempotency)->first();
        if ($existing) {
            return $existing;
        }

        $ttl = (int) config('live_ops.limits.agent_command_ttl_minutes', 30);
        $row = LiveOpsAgentCommand::create([
            'command_id' => (string) Str::ulid(),
            'command_type' => $type,
            'company_id' => $companyId,
            'device_uid' => $deviceUid,
            'payload' => $payload,
            'idempotency_key' => $idempotency,
            'status' => 'pending',
            'expires_at' => now()->addMinutes($ttl),
            'requested_by' => isset($input['requested_by']) ? mb_substr((string) $input['requested_by'], 0, 120) : null,
            'remediation_action_id' => $input['remediation_action_id'] ?? null,
        ]);

        $this->audit->record(
            eventType: 'agent_command.enqueued',
            requester: $row->requested_by,
            companyId: $companyId,
            operation: $type,
            actionId: $row->remediation_action_id,
            params: ['command_id' => $row->command_id, 'device_uid' => $deviceUid],
            resultStatus: 'pending',
        );

        return $row;
    }

    /**
     * Pending commands for heartbeat delivery (company-bound; optional device filter).
     */
    public function pendingForAgent(int $companyId, ?string $deviceUid = null, int $limit = 5): array
    {
        if (!Schema::hasTable('live_ops_agent_commands')) {
            return [];
        }

        // Expire lazily
        LiveOpsAgentCommand::where('company_id', $companyId)
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);

        $q = LiveOpsAgentCommand::where('company_id', $companyId)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->orderBy('id')
            ->limit($limit);

        if ($deviceUid) {
            $q->where(function ($w) use ($deviceUid) {
                $w->whereNull('device_uid')->orWhere('device_uid', $deviceUid);
            });
        } else {
            $q->whereNull('device_uid');
        }

        return $q->get()->map(fn (LiveOpsAgentCommand $c) => [
            'command_id' => $c->command_id,
            'type' => $c->command_type,
            'payload' => $c->payload ?? [],
            'expires_at' => optional($c->expires_at)?->toIso8601String(),
            'idempotency_key' => $c->idempotency_key,
        ])->all();
    }

    public function ack(int $companyId, string $commandId): LiveOpsAgentCommand
    {
        $cmd = $this->findForCompany($companyId, $commandId);
        if ($cmd->status === 'pending') {
            $cmd->update(['status' => 'acked', 'acked_at' => now()]);
        }

        return $cmd->fresh();
    }

    public function complete(int $companyId, string $commandId, array $result, bool $ok = true): LiveOpsAgentCommand
    {
        $cmd = $this->findForCompany($companyId, $commandId);
        $safe = $this->redactor->redact($result);
        $cmd->update([
            'status' => $ok ? 'succeeded' : 'failed',
            'completed_at' => now(),
            'acked_at' => $cmd->acked_at ?? now(),
            'result' => $safe,
        ]);

        $this->audit->record(
            eventType: 'agent_command.completed',
            companyId: $companyId,
            operation: $cmd->command_type,
            actionId: $cmd->remediation_action_id,
            params: ['command_id' => $commandId],
            resultStatus: $cmd->status,
            metadata: ['result' => $safe],
        );

        return $cmd->fresh();
    }

    private function findForCompany(int $companyId, string $commandId): LiveOpsAgentCommand
    {
        $cmd = LiveOpsAgentCommand::where('command_id', $commandId)
            ->where('company_id', $companyId)
            ->first();
        if (!$cmd) {
            throw new \InvalidArgumentException('Command not found for this company');
        }
        if ($cmd->isExpired() && $cmd->status === 'pending') {
            $cmd->update(['status' => 'expired']);
            throw new \InvalidArgumentException('Command expired');
        }

        return $cmd;
    }
}
