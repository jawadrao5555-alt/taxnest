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
 * Manual-dispatch retry job for a single FBR POS bill.
 *
 * Dispatched ONLY from explicit manual triggers (failQueueRetryOne,
 * failQueueRetryAll). Each dispatch = one "manual attempt": resets the
 * fbr_auto_retry_count to 0 at the start of handle() so the bill re-enters
 * the automated scheduler pool after this batch of up to $tries queue-retries.
 *
 * Queue retries (up to $tries=3 via Laravel's backoff) each increment
 * fbr_auto_retry_count on failure — they are automated attempts that count
 * toward the cap. A successful submission or a fresh manual dispatch resets it.
 */
class RetryFbrPosSubmissionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 90;
    public array $backoff = [10, 20, 30];

    public function __construct(public int $transactionId)
    {
    }

    public function handle(): void
    {
        $transaction = FbrPosTransaction::with(['items', 'company'])->find($this->transactionId);

        if (!$transaction) {
            Log::warning("RetryFbrPosSubmissionJob: Transaction #{$this->transactionId} not found");
            return;
        }

        if ($transaction->fbr_status === 'submitted' || !empty($transaction->fbr_invoice_number)) {
            Log::info("RetryFbrPosSubmissionJob: Transaction #{$this->transactionId} already submitted, skip");
            return;
        }

        if ($transaction->invoice_mode === 'local') {
            Log::info("RetryFbrPosSubmissionJob: Transaction #{$this->transactionId} is local-only, skip");
            return;
        }

        // config_error = permanent config failure (POSID / token missing).
        // Even a manual dispatch can't fix it — admin must update FBR Settings first.
        // The Fail Queue shows these bills with a "Fix Settings" prompt.
        if ($transaction->fbr_status === 'config_error') {
            Log::info("RetryFbrPosSubmissionJob: Transaction #{$this->transactionId} is config_error (POSID/token missing), skip — fix FBR Settings first");
            return;
        }

        // Manual dispatch = explicit human action → reset the automated retry counter
        // so this bill re-enters the scheduler pool after this job completes.
        // Only reset on attempt #1 (not on queue-backoff retries of the same job).
        $attempt = $this->attempts();
        if ($attempt === 1) {
            FbrPosTransaction::where('id', $this->transactionId)
                ->update(['fbr_auto_retry_count' => 0]);
        }

        $transaction->fbr_submission_hash = null;
        $transaction->save();

        Log::info("RetryFbrPosSubmissionJob: Transaction #{$this->transactionId} attempt {$attempt}/{$this->tries}");

        $fbr = new FbrService();
        $result = $fbr->submitFbrPosTransaction($transaction);

        if (($result['status'] ?? null) === 'success') {
            // Reset counter on success — the bill is done.
            FbrPosTransaction::where('id', $this->transactionId)
                ->update(['fbr_auto_retry_count' => 0]);
            Log::info("RetryFbrPosSubmissionJob: Transaction #{$this->transactionId} submitted successfully on attempt {$attempt}");

            try {
                $svc = new \App\Services\PushNotificationService();
                $svc->sendToCompany(
                    $transaction->company_id,
                    'FBR Submission Successful',
                    "Invoice {$transaction->invoice_number} accepted. FBR #: " . ($result['fbr_invoice_number'] ?? '—'),
                    ['url' => route('fbrpos.show', $transaction->id)],
                    'fbrpos' // scope: FBR POS subscribers only — never POS/DI devices
                );
            } catch (\Throwable $e) {
                Log::warning("Push notify failed: " . $e->getMessage());
            }
            return;
        }

        if (($result['status'] ?? null) === 'blocked') {
            Log::info("RetryFbrPosSubmissionJob: Transaction #{$this->transactionId} blocked (likely already submitted)");
            return;
        }

        if (($result['status'] ?? null) === 'queued_agent') {
            Log::info("RetryFbrPosSubmissionJob: Transaction #{$this->transactionId} queued for Desktop Sync Agent (Fiscal Device), skip");
            return;
        }

        // config_error set by FbrService (POSID/token missing) — this handles the
        // edge case where config was missing at job run time even though status
        // wasn't config_error before. Don't increment counter; status already terminal.
        if (($result['status'] ?? null) === 'config_error') {
            Log::info("RetryFbrPosSubmissionJob: Transaction #{$this->transactionId} hit config_error during submit (POSID/token still missing)");
            return;
        }

        $errors = implode('; ', $result['errors'] ?? ['Unknown error']);
        Log::warning("RetryFbrPosSubmissionJob: Transaction #{$this->transactionId} failed on attempt {$attempt}: {$errors}");

        // Queue-retry attempts count as automated failures → increment persistent counter.
        FbrPosTransaction::where('id', $this->transactionId)
            ->increment('fbr_auto_retry_count');

        if ($attempt >= $this->tries) {
            try {
                $svc = new \App\Services\PushNotificationService();
                $svc->sendToCompany(
                    $transaction->company_id,
                    'FBR Auto-Retry Exhausted',
                    "Invoice {$transaction->invoice_number} could not be submitted after {$this->tries} attempts. Manual retry required.",
                    ['url' => route('fbrpos.failQueue')],
                    'fbrpos' // scope: FBR POS subscribers only — never POS/DI devices
                );
            } catch (\Throwable $e) {
                Log::warning("Push notify failed: " . $e->getMessage());
            }
            $this->fail(new \Exception("FBR auto-retry exhausted: {$errors}"));
            return;
        }

        throw new \Exception("FBR submission failed (will retry): {$errors}");
    }

    public function failed(\Throwable $e): void
    {
        Log::error("RetryFbrPosSubmissionJob: Transaction #{$this->transactionId} permanently failed: " . $e->getMessage());
    }
}
