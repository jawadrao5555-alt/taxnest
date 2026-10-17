<?php

namespace App\Services;

use App\Models\FbrPosTransaction;
use Illuminate\Support\Facades\Schema;

final class FbrPosAgentClaim
{
    public static function acquire(FbrPosTransaction $transaction, ?string $deviceUid = null): ?string
    {
        $token = bin2hex(random_bytes(32));
        $update = ['fbr_submission_hash' => $token, 'fbr_status' => 'pending', 'updated_at' => now()];
        if (Schema::hasColumn('fbr_pos_transactions', 'fbr_agent_claimed_at')) $update['fbr_agent_claimed_at'] = now();
        $query = FbrPosTransaction::where('id', $transaction->id)->where('company_id', $transaction->company_id)
            ->whereIn('fbr_status', ['pending', 'failed', 'offline'])
            ->whereNull('fbr_invoice_number')->whereNull('fbr_submission_hash')
            ->where(fn ($q) => $q->whereNull('invoice_mode')->orWhere('invoice_mode', '!=', 'local'));
        if (Schema::hasColumn('fbr_pos_transactions', 'fbr_device_uid_snapshot')) {
            $query->where(function ($q) use ($deviceUid) {
                $q->whereNull('fbr_device_uid_snapshot');
                if ($deviceUid) $q->orWhere('fbr_device_uid_snapshot', $deviceUid);
            });
        }
        $won = $query->update($update);
        return $won === 1 ? $token : null;
    }

    public static function quarantineExpired(int $companyId): void
    {
        if (!Schema::hasColumn('fbr_pos_transactions', 'fbr_agent_claimed_at')) return;
        // A claim was handed to an agent; expiry cannot prove no IMS POST.
        // Do not release it to another agent. Genuine late acceptance still
        // reconciles through the serialized callback writer.
        FbrPosTransaction::where('company_id', $companyId)
            ->whereIn('fbr_status', ['pending', 'failed', 'offline'])
            ->whereNull('fbr_invoice_number')->whereNotNull('fbr_submission_hash')
            ->where(function($q) {
                $q->where('fbr_agent_claimed_at', '<=', now()->subMinutes(3))
                  ->orWhere(fn($q) => $q->whereNull('fbr_agent_claimed_at')->where('updated_at', '<=', now()->subMinutes(3)));
            })
            ->update(['fbr_status' => 'verification_pending', 'updated_at' => now()]);
    }

    public static function active(FbrPosTransaction $transaction): bool
    {
        return !empty($transaction->fbr_submission_hash);
    }

    public static function prepareRetry(FbrPosTransaction $transaction): bool
    {
        // Re-check on the UPDATE, not only on a stale controller/job instance.
        // A concurrently delivered agent claim must never be cleared by retry.
        $query = FbrPosTransaction::where('id', $transaction->id)->where('company_id', $transaction->company_id)
            ->whereNull('fbr_invoice_number')->whereIn('fbr_status', ['failed', 'offline', 'pending', 'config_error'])
            ->where(fn ($q) => $q->whereNull('invoice_mode')->orWhere('invoice_mode', '!=', 'local'));
        $query->whereNull('fbr_submission_hash');
        $won = $query->update(['fbr_submission_hash' => null, 'fbr_status' => 'pending', 'updated_at' => now()]);
        if ($won) $transaction->refresh();
        return $won === 1;
    }
}
