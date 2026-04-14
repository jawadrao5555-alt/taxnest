<?php

namespace App\Services;

use App\Models\AuditLog;

class AuditLogService
{
    public static function log($action, $entityType, $entityId = null, $oldValues = null, $newValues = null, $companyId = null, $userId = null)
    {
        $hash = hash('sha256', implode('|', [
            $action, $entityType, $entityId,
            json_encode($newValues), now()->toIso8601String()
        ]));

        AuditLog::create([
            'company_id' => $companyId ?? auth()->user()?->company_id ?? app('currentCompanyId', null),
            'user_id' => $userId ?? auth()->id() ?? auth('pos')->id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'sha256_hash' => $hash,
            'created_at' => now(),
        ]);
    }
}
