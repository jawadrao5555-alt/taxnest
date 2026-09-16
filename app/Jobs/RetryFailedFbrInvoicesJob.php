<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\FbrLog;
use App\Http\Controllers\InvoiceController;
use App\Services\DiFiscalSubmissionState;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RetryFailedFbrInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public const MAX_RETRIES = 3;
    public const RETRY_DELAY_MINUTES = 5;

    public function __construct(public ?int $invoiceId = null)
    {
    }

    public function handle(): void
    {
        if (!config('features.enable_fbr_retry_system', false)) {
            Log::info('RetryFailedFbrInvoicesJob: feature flag OFF, skipping');
            return;
        }

        if ($this->invoiceId !== null) {
            $this->retrySingle($this->invoiceId);
            return;
        }

        $this->retryBatch();
    }

    private function retryBatch(): void
    {
        $cutoff = now()->subMinutes(self::RETRY_DELAY_MINUTES);

        $invoices = Invoice::where('fbr_status', 'failed')
            ->where(function ($q) {
                $q->whereNull('retry_count')->orWhere('retry_count', '<', self::MAX_RETRIES);
            })
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('last_retry_at')->orWhere('last_retry_at', '<=', $cutoff);
            })
            ->whereNull('fbr_invoice_number')
            ->where('status', '!=', 'locked')
            ->limit(50)
            ->get();

        Log::info("RetryFailedFbrInvoicesJob: batch picked {$invoices->count()} invoices");

        foreach ($invoices as $inv) {
            $this->retrySingle($inv->id);
        }
    }

    private function retrySingle(int $invoiceId): void
    {
        $invoice = Invoice::withoutGlobalScopes()->with('items', 'company')->find($invoiceId);
        if (!$invoice) {
            Log::warning("RetryFailedFbrInvoicesJob: invoice #{$invoiceId} not found");
            return;
        }

        if ($invoice->fbr_invoice_number || in_array($invoice->status, ['locked', 'pending_verification'], true)) {
            Log::info("RetryFailedFbrInvoicesJob: invoice #{$invoiceId} already locked, skip");
            return;
        }

        if (($invoice->retry_count ?? 0) >= self::MAX_RETRIES) {
            Log::warning("RetryFailedFbrInvoicesJob: invoice #{$invoiceId} exhausted (retry_count={$invoice->retry_count})");
            return;
        }

        // Automatic replay is safe only after an explicit regulator rejection.
        // Any timeout, malformed acknowledgement or callback loss is retained
        // as pending_verification and deliberately never reaches this worker.
        $lastOutcome = FbrLog::where('invoice_id', $invoiceId)->latest('id')->first();
        if (!$lastOutcome || $lastOutcome->status !== 'failed'
            || !in_array($lastOutcome->failure_type, ['validation_error', 'payload_error', 'pre_validation', 'schema_error'], true)) {
            Log::warning('RetryFailedFbrInvoicesJob: automatic replay refused without explicit rejection', [
                'invoice_id' => $invoiceId,
                'log_status' => $lastOutcome->status ?? null,
                'failure_type' => $lastOutcome->failure_type ?? null,
            ]);
            return;
        }

        $claimed = DiFiscalSubmissionState::reserve(
            $invoiceId,
            'automatic_retry',
            $invoice->company?->fbr_environment ?? 'sandbox'
        );
        if (!$claimed) {
            Log::info("RetryFailedFbrInvoicesJob: invoice #{$invoiceId} was claimed by another submission");
            return;
        }
        $claimed->retry_count = ($claimed->retry_count ?? 0) + 1;
        $claimed->last_retry_at = now();
        $claimed->save();

        try {
            $result = app(InvoiceController::class)->submitToFbrSync($claimed);

            if (($result['status'] ?? null) === 'success') {
                Log::info("RetryFailedFbrInvoicesJob: invoice #{$invoiceId} SUCCESS on retry {$claimed->retry_count}");
                return;
            }

            Log::warning('RetryFailedFbrInvoicesJob: regulator did not accept automatic retry', [
                'invoice_id' => $invoiceId,
                'retry_count' => $claimed->retry_count,
                'result_status' => $result['status'] ?? 'unknown',
                'failure_type' => $result['failure_type'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // The send boundary may have been crossed. Do not clear the claim
            // or turn this into a replayable failed invoice.
            $current = Invoice::withoutGlobalScopes()->find($invoiceId);
            if ($current && $current->is_fbr_processing) {
                DiFiscalSubmissionState::verificationRequired(
                    $current,
                    $current->company?->fbr_environment ?? 'sandbox',
                    'retry_worker_callback_loss'
                );
                $current->save();
            }
            Log::error('RetryFailedFbrInvoicesJob: retry outcome unknown', [
                'invoice_id' => $invoiceId,
                'exception_class' => get_class($e),
            ]);
        }
    }
}
