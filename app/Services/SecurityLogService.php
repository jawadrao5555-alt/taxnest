<?php

namespace App\Services;

use App\Models\SecurityLog;

class SecurityLogService
{
    public static function failedLogin(\Illuminate\Auth\Events\Failed $event): void
    {
        SecurityLog::create([
            'user_id' => null,
            'action' => 'failed_login',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'metadata' => [
                'guard' => $event->guard,
                'email' => $event->credentials['email'] ?? 'unknown',
            ],
            'created_at' => now(),
        ]);
    }

    /** Login identities are guard-bound; admin IDs are not company user IDs. */
    public static function login(\Illuminate\Auth\Events\Login $event): void
    {
        $companyUser = $event->user instanceof \App\Models\User;
        SecurityLog::create([
            'user_id' => $companyUser ? $event->user->getAuthIdentifier() : null,
            'action' => 'login',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'metadata' => [
                'guard' => $event->guard,
                'actor_type' => $companyUser ? 'company_user' : ($event->user instanceof \App\Models\AdminUser ? 'saas_admin' : 'other'),
                'actor_id' => $event->user->getAuthIdentifier(),
            ],
            'created_at' => now(),
        ]);
    }

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
