<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\FbrLog;
use App\Models\CustomerLedger;
use App\Services\FbrService;
use App\Services\InvoiceActivityService;
use App\Services\IntegrityHashService;
use App\Services\ComplianceScoreService;
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
        $response = $fbrService->submitInvoice($invoice, $this->attempts() - 1);

        if ($response['status'] === 'success') {
            $invoice->status = 'locked';
            $fbrNum = $response['fbr_invoice_number'] ?? null;
            if ($fbrNum) {
                $invoice->fbr_invoice_number = $fbrNum;
                $invoice->fbr_submission_date = now();
            }
            $invoice->integrity_hash = IntegrityHashService::generate($invoice);

            $qrData = json_encode([
                'ntn' => $invoice->company->ntn ?? '',
                'invoice_number' => $invoice->internal_invoice_number ?? $invoice->invoice_number,
                'fbr_invoice_id' => $fbrNum ?? $invoice->invoice_number,
                'date' => $invoice->created_at->format('Y-m-d'),
                'total' => $invoice->total_amount,
            ]);
            $invoice->qr_data = $qrData;
            $invoice->fbr_invoice_id = $fbrNum;
            $invoice->save();

            InvoiceActivityService::log(
                $invoice->id,
                $invoice->company_id,
                'locked',
                ['fbr_invoice_number' => $response['fbr_invoice_number'] ?? null]
            );

            $lastEntry = CustomerLedger::where('company_id', $invoice->company_id)
                ->where('customer_ntn', $invoice->buyer_ntn)
                ->orderBy('id', 'desc')
                ->first();
            $lastBalance = $lastEntry ? $lastEntry->balance_after : 0;
            $newBalance = $lastBalance + $invoice->total_amount;

            CustomerLedger::create([
                'company_id' => $invoice->company_id,
                'customer_name' => $invoice->buyer_name,
                'customer_ntn' => $invoice->buyer_ntn,
                'invoice_id' => $invoice->id,
                'debit' => $invoice->total_amount,
                'credit' => 0,
                'balance_after' => $newBalance,
                'type' => 'invoice',
                'notes' => 'Invoice ' . $invoice->invoice_number . ' locked',
            ]);

            $invoice->company->update(['last_successful_submission' => now()]);

            ComplianceScoreService::recalculate($invoice->company_id);
        } else {
            Log::warning("FBR submission failed for invoice #{$invoice->id}, attempt {$this->attempts()}, type: " . ($response['failure_type'] ?? 'unknown'));

            InvoiceActivityService::log(
                $invoice->id,
                $invoice->company_id,
                'retry',
                ['attempt' => $this->attempts(), 'failure_type' => $response['failure_type'] ?? 'unknown']
            );

            $this->release($this->backoff[$this->attempts() - 1] ?? 120);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $invoice = Invoice::find($this->invoiceId);
        if ($invoice) {
            $invoice->status = 'draft';
            $invoice->save();

            InvoiceActivityService::log(
                $invoice->id,
                $invoice->company_id,
                'fbr_failed',
                ['error' => $exception->getMessage()]
            );

            ComplianceScoreService::recalculate($invoice->company_id);
        }

        Log::error("FBR submission permanently failed for invoice #{$this->invoiceId}: " . $exception->getMessage());
    }
}
