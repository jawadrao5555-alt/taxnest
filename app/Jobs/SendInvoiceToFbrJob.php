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
use App\Services\HsUsagePatternService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendInvoiceToFbrJob implements ShouldQueue
{
    use Queueable;

    public $tries = 2;
    public $backoff = [30, 60];

    public function __construct(public int $invoiceId, public ?string $fbrEnvironment = null)
    {
    }

    public function handle(): void
    {
        $invoice = Invoice::with(['company', 'items'])->findOrFail($this->invoiceId);

        if ($invoice->status === 'locked') {
            Log::info("SendInvoiceToFbrJob: Invoice #{$invoice->id} already locked, skipping.");
            return;
        }

        if ($invoice->status === 'pending_verification') {
            Log::info("SendInvoiceToFbrJob: Invoice #{$invoice->id} pending verification, skipping.");
            return;
        }

        if ($invoice->status !== 'submitted') {
            Log::warning("SendInvoiceToFbrJob: Invoice #{$invoice->id} status is '{$invoice->status}', expected 'submitted'. Skipping.");
            return;
        }

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
            $invoice->fbr_status = 'success';
            $invoice->integrity_hash = IntegrityHashService::generate($invoice);

            $qrData = json_encode([
                'sellerNTNCNIC' => preg_replace('/[^0-9]/', '', $invoice->company->ntn ?? ''),
                'fbr_invoice_number' => $fbrNum ?? $invoice->invoice_number,
                'invoiceDate' => $invoice->invoice_date ?? $invoice->created_at->format('Y-m-d'),
                'totalValues' => $invoice->total_amount,
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
                'customer_ntn' => $invoice->buyer_ntn ?? '',
                'invoice_id' => $invoice->id,
                'debit' => $invoice->total_amount,
                'credit' => 0,
                'balance_after' => $newBalance,
                'type' => 'invoice',
                'notes' => 'Invoice ' . $invoice->invoice_number . ' locked',
            ]);

            $invoice->company->update(['last_successful_submission' => now()]);

            HsUsagePatternService::recordSuccess($invoice);

            ComplianceScoreService::recalculate($invoice->company_id);
            return;
        }

        if ($response['status'] === 'pending_verification') {
            $invoice->status = 'pending_verification';
            $invoice->fbr_status = 'pending_verification';
            $invoice->save();

            InvoiceActivityService::log(
                $invoice->id,
                $invoice->company_id,
                'pending_verification',
                [
                    'reason' => 'FBR response ambiguous',
                    'attempt' => $this->attempts(),
                    'failure_type' => $response['failure_type'] ?? 'ambiguous_response',
                ]
            );

            Log::warning("SendInvoiceToFbrJob: Invoice #{$invoice->id} marked pending_verification - FBR response ambiguous.");
            return;
        }

        Log::warning("FBR submission failed for invoice #{$invoice->id}, attempt {$this->attempts()}, type: " . ($response['failure_type'] ?? 'unknown'));

        $this->captureHsRejections($invoice, $response);

        if ($this->attempts() >= $this->tries) {
            $invoice->status = 'failed';
            $invoice->fbr_status = 'failed';
            $invoice->save();

            InvoiceActivityService::log(
                $invoice->id,
                $invoice->company_id,
                'fbr_failed',
                ['attempt' => $this->attempts(), 'failure_type' => $response['failure_type'] ?? 'unknown', 'errors' => $response['errors'] ?? []]
            );

            ComplianceScoreService::recalculate($invoice->company_id);
        } else {
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
            $invoice->status = 'failed';
            $invoice->fbr_status = 'failed';
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

                    HsUsagePatternService::recordRejection(
                        $item->hs_code,
                        $item->schedule_type ?? 'standard',
                        $item->tax_rate ?? 18
                    );
                }
            }
        } catch (\Exception $e) {
            Log::warning("Failed to capture HS rejections for invoice #{$invoice->id}: " . $e->getMessage());
        }
    }
}
