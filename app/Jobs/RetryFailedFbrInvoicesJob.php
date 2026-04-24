<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\FbrService;
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
        $invoice = Invoice::with('items', 'company')->find($invoiceId);
        if (!$invoice) {
            Log::warning("RetryFailedFbrInvoicesJob: invoice #{$invoiceId} not found");
            return;
        }

        if ($invoice->fbr_invoice_number || $invoice->status === 'locked') {
            Log::info("RetryFailedFbrInvoicesJob: invoice #{$invoiceId} already locked, skip");
            return;
        }

        if (($invoice->retry_count ?? 0) >= self::MAX_RETRIES) {
            Log::warning("RetryFailedFbrInvoicesJob: invoice #{$invoiceId} exhausted (retry_count={$invoice->retry_count})");
            return;
        }

        $invoice->fbr_submission_hash = null;
        $invoice->status = 'draft';
        $invoice->fbr_status = null;
        $invoice->is_fbr_processing = false;
        $invoice->retry_count = ($invoice->retry_count ?? 0) + 1;
        $invoice->last_retry_at = now();
        $invoice->save();

        try {
            $fbr = new FbrService();
            $result = $fbr->submitInvoice($invoice, 0);

            if (($result['status'] ?? null) === 'success') {
                Log::info("RetryFailedFbrInvoicesJob: invoice #{$invoiceId} SUCCESS on retry {$invoice->retry_count}");
                return;
            }

            $invoice->fbr_status = 'failed';
            $invoice->save();

            $errors = is_array($result['errors'] ?? null)
                ? implode('; ', array_slice($result['errors'], 0, 3))
                : ($result['failure_type'] ?? 'unknown');
            Log::warning("RetryFailedFbrInvoicesJob: invoice #{$invoiceId} retry {$invoice->retry_count} failed: {$errors}");
        } catch (\Throwable $e) {
            $invoice->fbr_status = 'failed';
            $invoice->save();
            Log::error("RetryFailedFbrInvoicesJob: invoice #{$invoiceId} exception: " . $e->getMessage());
        }
    }
}
