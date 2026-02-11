<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\FbrLog;
use App\Services\FbrService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendInvoiceToFbrJob implements ShouldQueue
{
    use Queueable;

    public $tries = 3;
    public $backoff = [30, 60, 120];

    public function __construct(public int $invoiceId)
    {
    }

    public function handle(): void
    {
        $invoice = Invoice::with(['company', 'items'])->findOrFail($this->invoiceId);

        $fbrService = new FbrService();
        $response = $fbrService->submitInvoice($invoice);

        if ($response['status'] === 'success') {
            $invoice->status = 'locked';
            if (!empty($response['fbr_invoice_number'])) {
                $invoice->invoice_number = $response['fbr_invoice_number'];
            }
            $invoice->save();
        } else {
            Log::warning("FBR submission failed for invoice #{$invoice->id}, attempt {$this->attempts()}");
            $this->release($this->backoff[$this->attempts() - 1] ?? 120);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $invoice = Invoice::find($this->invoiceId);
        if ($invoice) {
            $invoice->status = 'draft';
            $invoice->save();
        }

        Log::error("FBR submission permanently failed for invoice #{$this->invoiceId}: " . $exception->getMessage());
    }
}
