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

        $transaction->fbr_submission_hash = null;
        $transaction->save();

        $attempt = $this->attempts();
        Log::info("RetryFbrPosSubmissionJob: Transaction #{$this->transactionId} attempt {$attempt}/{$this->tries}");

        $fbr = new FbrService();
        $result = $fbr->submitFbrPosTransaction($transaction);

        if (($result['status'] ?? null) === 'success') {
            Log::info("RetryFbrPosSubmissionJob: Transaction #{$this->transactionId} submitted successfully on attempt {$attempt}");

            try {
                $svc = new \App\Services\PushNotificationService();
                $svc->sendToCompany(
                    $transaction->company_id,
                    'fbrpos',
                    'FBR Submission Successful',
                    "Invoice {$transaction->invoice_number} accepted. FBR #: " . ($result['fbr_invoice_number'] ?? '—'),
                    ['url' => route('fbrpos.show', $transaction->id)]
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

        $errors = implode('; ', $result['errors'] ?? ['Unknown error']);
        Log::warning("RetryFbrPosSubmissionJob: Transaction #{$this->transactionId} failed on attempt {$attempt}: {$errors}");

        if ($attempt >= $this->tries) {
            try {
                $svc = new \App\Services\PushNotificationService();
                $svc->sendToCompany(
                    $transaction->company_id,
                    'fbrpos',
                    'FBR Auto-Retry Exhausted',
                    "Invoice {$transaction->invoice_number} could not be submitted after 3 attempts. Manual retry required.",
                    ['url' => route('fbrpos.failQueue')]
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
