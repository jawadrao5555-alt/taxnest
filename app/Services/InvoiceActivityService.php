<?php

namespace App\Services;

use App\Models\InvoiceActivityLog;

class InvoiceActivityService
{
    public static function log(int $invoiceId, int $companyId, string $action, ?array $changes = null, ?string $ip = null): void
    {
        $userId = auth()->id();

        InvoiceActivityLog::create([
            'invoice_id' => $invoiceId,
            'company_id' => $companyId,
            'user_id' => $userId,
            'action' => $action,
            'changes_json' => $changes,
            'ip_address' => $ip ?? request()->ip(),
            'created_at' => now(),
        ]);
    }
}
