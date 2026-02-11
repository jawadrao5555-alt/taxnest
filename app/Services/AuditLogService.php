<?php

namespace App\Services;

use App\Models\AuditLog;

class AuditLogService
{
    public static function log($action, $entityType, $entityId = null, $oldValues = null, $newValues = null)
    {
        $hash = hash('sha256', implode('|', [
            $action, $entityType, $entityId,
            json_encode($newValues), now()->toIso8601String()
        ]));

        AuditLog::create([
            'company_id' => auth()->user()?->company_id,
            'user_id' => auth()->id(),
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
