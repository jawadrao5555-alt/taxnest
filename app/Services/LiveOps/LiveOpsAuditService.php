<?php

namespace App\Services\LiveOps;

use App\Models\AdminAuditLog;
use App\Models\LiveOpsAuditEvent;
use Illuminate\Support\Facades\Schema;

class LiveOpsAuditService
{
    public function __construct(private LiveOpsRedactor $redactor)
    {
    }

    public function record(
        string $eventType,
        ?string $requester = null,
        ?int $companyId = null,
        ?string $operation = null,
        ?string $actionId = null,
        ?string $reportId = null,
        ?array $params = null,
        ?string $artifactDigest = null,
        ?string $resultStatus = null,
        ?array $metadata = null,
        ?int $adminId = null,
    ): ?LiveOpsAuditEvent {
        if (!Schema::hasTable('live_ops_audit_events')) {
            return null;
        }

        $meta = $this->redactor->redact($metadata ?? []);
        $paramsHash = $params !== null ? $this->redactor->paramsHash($params) : null;

        $event = LiveOpsAuditEvent::create([
            'event_type' => $eventType,
            'requester' => $requester ? mb_substr($requester, 0, 120) : null,
            'admin_id' => $adminId,
            'company_id' => $companyId,
            'operation' => $operation,
            'action_id' => $actionId,
            'report_id' => $reportId,
            'params_hash' => $paramsHash,
            'artifact_digest' => $artifactDigest,
            'result_status' => $resultStatus,
            'metadata' => $meta,
        ]);

        if ($adminId && Schema::hasTable('admin_audit_logs')) {
            try {
                AdminAuditLog::log($adminId, 'live_ops.'.$eventType, 'company', $companyId, [
                    'operation' => $operation,
                    'action_id' => $actionId,
                    'report_id' => $reportId,
                    'result_status' => $resultStatus,
                    'params_hash' => $paramsHash,
                ]);
            } catch (\Throwable $e) {
                // Never fail the ops path on secondary audit write.
            }
        }

        return $event;
    }
}
