<?php

namespace App\Services;

use App\Models\SecurityLog;

class SecurityLogService
{
    public static function log(string $action, ?int $userId = null, ?array $metadata = null): void
    {
        SecurityLog::create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
