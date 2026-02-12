<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\FbrLog;
use App\Models\CustomerLedger;
use App\Services\FbrService;
use App\Services\InvoiceActivityService;
use App\Services\IntegrityHashService;
use App\Services\ComplianceScoreService;
use App\Services\HsIntelligenceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendInvoiceToFbrJob implements ShouldQueue
{
    use Queueable;

    public $tries = 3;
    public $backoff = [30, 60, 120];

    public function __construct(public int $invoiceId, public ?string $fbrEnvironment = null)
    {
    }

    public function handle(): void
    {
        $invoice = Invoice::with(['company', 'items'])->findOrFail($this->invoiceId);

        if ($this->fbrEnvironment && in_array($this->fbrEnvironment, ['sandbox', 'production'])) {
            $invoice->company->fbr_environment = $this->fbrEnvironment;
        }

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

            $this->captureHsRejections($invoice, $response);

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
        $invoice = Invoice::with(['company', 'items'])->find($this->invoiceId);
        if ($invoice) {
            $invoice->status = 'draft';
            $invoice->save();

            $this->captureHsRejections($invoice, [
                'failure_type' => 'exception',
                'errors' => [$exception->getMessage()],
            ]);

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

    private function captureHsRejections($invoice, array $response): void
    {
        try {
            $environment = $invoice->company->fbr_environment ?? 'sandbox';
            $errorCode = $response['failure_type'] ?? null;
            $errors = $response['errors'] ?? [];
            $errorMessage = is_array($errors) ? implode('; ', array_slice($errors, 0, 3)) : (string)$errors;
            if (empty($errorMessage)) {
                $errorMessage = $response['failure_type'] ?? 'FBR submission failed';
            }

            foreach ($invoice->items as $item) {
                if (!empty($item->hs_code)) {
                    HsIntelligenceService::recordFbrRejection(
                        $item->hs_code,
                        $errorCode,
                        $errorMessage,
                        $item->schedule_type ?? 'standard',
                        $item->tax_rate ?? 18,
                        $item->sro_schedule_no ?? null,
                        $environment
                    );
                }
            }
        } catch (\Exception $e) {
            Log::warning("Failed to capture HS rejections for invoice #{$invoice->id}: " . $e->getMessage());
        }
    }
}
