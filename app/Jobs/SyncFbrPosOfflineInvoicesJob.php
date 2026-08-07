<?php

namespace App\Jobs;

use App\Models\FbrPosTransaction;
use App\Services\FbrService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 🔄 Auto-Sync engine for FBR POS — mirrors SyncPosOfflineInvoicesJob (PRA).
 *
 * Picks up to 50 FBR POS bills that are still offline/pending/failed and
 * have not yet received an fbr_invoice_number, then re-submits them to FBR.
 *
 * Skips:
 *  - invoice_mode = 'local' (cashier explicitly chose not to submit)
 *  - already-submitted bills
 *  - companies with FBR reporting disabled or no token
 *  - config_error bills (permanent config failure — manual fix required)
 *  - bills at or beyond MAX_AUTO_RETRY attempts (surfaced in Fail Queue, not lost)
 *
 * Scheduled every 2 minutes from routes/console.php.
 *
 * Retry counter semantics:
 *  - fbr_auto_retry_count incremented on each failed automated attempt.
 *  - Resets to 0 on successful submission.
 *  - Bills with count >= MAX_AUTO_RETRY are skipped by the scheduler; they
 *    remain visible in the Fail Queue for manual intervention. A manual retry
 *    (via Fail Queue or F11 panel) resets the counter and re-enters the pool.
 */
class SyncFbrPosOfflineInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** After this many automated failures the scheduler stops attempting the bill. */
    const MAX_AUTO_RETRY = 5;

    public function handle(): void
    {
        $transactions = FbrPosTransaction::whereIn('fbr_status', ['offline', 'pending', 'failed'])
            ->whereNull('fbr_invoice_number')
            ->where(function ($q) {
                $q->whereNull('invoice_mode')->orWhere('invoice_mode', '!=', 'local');
            })
            // Persistent cap: skip bills that have exhausted their automated retry budget.
            // They stay in the Fail Queue and are recoverable via manual retry.
            ->where('fbr_auto_retry_count', '<', self::MAX_AUTO_RETRY)
            ->with(['company', 'items'])
            ->orderBy('created_at', 'asc')
            ->limit(50)
            ->get();

        if ($transactions->isEmpty()) {
            return;
        }

        $fbrService = new FbrService();

        foreach ($transactions as $transaction) {
            $company = $transaction->company;

            // Skip if company missing, FBR reporting disabled, or no dedicated IMS POS token.
            // FBR IMS POS submits ONLY with fbr_pos_token (no DI-token fallback), so a company
            // without it can never succeed — skip to avoid indefinite retry/log churn every tick.
            if (!$company) continue;
            if (isset($company->fbr_reporting_enabled) && !$company->fbr_reporting_enabled) continue;
            if (empty($company->fbr_pos_token)) continue;
            // No POS Registration ID => the pre-submit POSID guard will fail every attempt.
            // Skip here too, else each tick regenerates a guard-failure log row per bill.
            if (empty($company->fbr_pos_id)) continue;

            // Re-check inside loop — concurrent retry may have completed it
            if ($transaction->fbr_invoice_number) continue;

            // Atomic claim — flip from failed/offline → pending so concurrent
            // job/manual-retry doesn't double-submit. Only proceed if we won.
            $claimed = FbrPosTransaction::where('id', $transaction->id)
                ->whereNull('fbr_invoice_number')
                ->whereIn('fbr_status', ['offline', 'pending', 'failed'])
                ->where('fbr_auto_retry_count', '<', self::MAX_AUTO_RETRY)
                ->update(['fbr_status' => 'pending', 'fbr_submission_hash' => null]);

            if ($claimed === 0) continue;

            try {
                $transaction->refresh();
                $result = $fbrService->submitFbrPosTransaction($transaction);

                if (($result['status'] ?? '') === 'success') {
                    // Reset counter on success — the bill is done.
                    FbrPosTransaction::where('id', $transaction->id)
                        ->update(['fbr_auto_retry_count' => 0]);
                    Log::info('FBR POS Auto-Sync: submitted', [
                        'transaction_id' => $transaction->id,
                        'invoice_number' => $transaction->invoice_number,
                        'fbr_invoice_number' => $result['fbr_invoice_number'] ?? null,
                    ]);
                } else {
                    // Increment persistent attempt counter on every automated failure.
                    // config_error and other terminal statuses set by FbrService are left
                    // alone — the increment still applies so the scheduler won't retry
                    // them even if the status somehow reverts (belt-and-suspenders).
                    FbrPosTransaction::where('id', $transaction->id)
                        ->increment('fbr_auto_retry_count');
                    $newCount = $transaction->fbr_auto_retry_count + 1;
                    Log::warning('FBR POS Auto-Sync: submission failed', [
                        'transaction_id' => $transaction->id,
                        'invoice_number' => $transaction->invoice_number,
                        'errors' => $result['errors'] ?? ['Unknown'],
                        'fbr_auto_retry_count' => $newCount,
                        'cap' => self::MAX_AUTO_RETRY,
                        'scheduler_will_skip' => $newCount >= self::MAX_AUTO_RETRY,
                    ]);
                }
            } catch (\Throwable $e) {
                FbrPosTransaction::where('id', $transaction->id)
                    ->increment('fbr_auto_retry_count');
                Log::error('FBR POS Auto-Sync: exception', [
                    'transaction_id' => $transaction->id,
                    'error' => $e->getMessage(),
                    'fbr_auto_retry_count' => $transaction->fbr_auto_retry_count + 1,
                ]);
            }
        }
    }
}
