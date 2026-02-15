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
use Illuminate\Support\Facades\Cache;

class SendInvoiceToFbrJob implements ShouldQueue
{
    use Queueable;

    public $tries = 3;
    public $backoff = [30, 90, 180];

    public function __construct(public int $invoiceId, public ?string $fbrEnvironment = null)
    {
    }

    public static function safeDispatch(int $invoiceId, ?string $fbrEnvironment = null): bool
    {
        $lockKey = "fbr_dispatch_lock:{$invoiceId}";
        if (!Cache::add($lockKey, true, 120)) {
            Log::info("SendInvoiceToFbrJob: Duplicate dispatch prevented for invoice #{$invoiceId}");
            return false;
        }
        static::dispatch($invoiceId, $fbrEnvironment);
        return true;
    }

    public function handle(): void
    {
        $startTime = microtime(true);

        $invoice = \Illuminate\Support\Facades\DB::transaction(function () {
            $inv = Invoice::where('id', $this->invoiceId)->lockForUpdate()->first();
            if (!$inv) {
                return null;
            }
            return $inv;
        });

        if (!$invoice) {
            Log::error("SendInvoiceToFbrJob: Invoice #{$this->invoiceId} not found.");
            $this->releaseLock();
            return;
        }

        $invoice->load(['company', 'items']);

        Log::info("SendInvoiceToFbrJob: Starting invoice #{$invoice->id}, attempt {$this->attempts()}/{$this->tries}");

        if (in_array($invoice->status, ['locked', 'pending_verification'])) {
            Log::info("SendInvoiceToFbrJob: Invoice #{$invoice->id} status is '{$invoice->status}', skipping.");
            $this->releaseLock();
            return;
        }

        if (!$invoice->is_fbr_processing) {
            Log::warning("SendInvoiceToFbrJob: Invoice #{$invoice->id} is not in processing state. Skipping.");
            $this->releaseLock();
            return;
        }

        if ($this->fbrEnvironment && in_array($this->fbrEnvironment, ['sandbox', 'production'])) {
            $invoice->company->fbr_environment = $this->fbrEnvironment;
        }

        $environment = $invoice->company->fbr_environment ?? 'sandbox';
        $fbrService = new FbrService();
        $response = $fbrService->submitInvoice($invoice, $this->attempts() - 1);

        $executionMs = round((microtime(true) - $startTime) * 1000);
        Log::info("SendInvoiceToFbrJob: Invoice #{$invoice->id} completed in {$executionMs}ms, result: {$response['status']}");

        $failureCategory = $this->categorizeFailure($response);

        $this->updateFbrLog($invoice, $response, $executionMs, $environment, $failureCategory);

        if ($response['status'] === 'success') {
            $invoice->status = 'locked';
            $invoice->is_fbr_processing = false;
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
            $this->releaseLock();
            return;
        }

        if ($response['status'] === 'pending_verification') {
            $invoice->status = 'pending_verification';
            $invoice->fbr_status = 'pending_verification';
            $invoice->is_fbr_processing = false;
            $invoice->save();

            InvoiceActivityService::log(
                $invoice->id,
                $invoice->company_id,
                'pending_verification',
                [
                    'reason' => 'FBR response ambiguous',
                    'attempt' => $this->attempts(),
                    'failure_type' => $response['failure_type'] ?? 'ambiguous_response',
                    'execution_ms' => $executionMs,
                ]
            );

            Log::warning("SendInvoiceToFbrJob: Invoice #{$invoice->id} marked pending_verification - FBR response ambiguous.");
            $this->releaseLock();
            return;
        }

        Log::warning("FBR submission failed for invoice #{$invoice->id}, attempt {$this->attempts()}, type: " . ($response['failure_type'] ?? 'unknown') . ", execution: {$executionMs}ms");

        $this->captureHsRejections($invoice, $response);

        if ($this->attempts() >= $this->tries) {
            $invoice->status = 'failed';
            $invoice->fbr_status = 'failed';
            $invoice->is_fbr_processing = false;
            $invoice->save();

            InvoiceActivityService::log(
                $invoice->id,
                $invoice->company_id,
                'fbr_failed',
                ['attempt' => $this->attempts(), 'failure_type' => $response['failure_type'] ?? 'unknown', 'errors' => $response['errors'] ?? [], 'execution_ms' => $executionMs]
            );

            ComplianceScoreService::recalculate($invoice->company_id);
            $this->releaseLock();
        } else {
            InvoiceActivityService::log(
                $invoice->id,
                $invoice->company_id,
                'retry',
                ['attempt' => $this->attempts(), 'failure_type' => $response['failure_type'] ?? 'unknown', 'execution_ms' => $executionMs]
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
            $invoice->is_fbr_processing = false;
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

        $this->releaseLock();
        Log::error("FBR submission permanently failed for invoice #{$this->invoiceId}: " . $exception->getMessage());
    }

    private function releaseLock(): void
    {
        Cache::forget("fbr_dispatch_lock:{$this->invoiceId}");
    }

    private function categorizeFailure(array $response): ?string
    {
        if ($response['status'] === 'success') return null;

        $failureType = $response['failure_type'] ?? '';

        return match(true) {
            str_contains($failureType, 'auth') || str_contains($failureType, 'token') => 'authentication',
            str_contains($failureType, 'timeout') || str_contains($failureType, 'connection') => 'network',
            str_contains($failureType, 'validation') || str_contains($failureType, 'payload') => 'validation',
            str_contains($failureType, 'rate_limit') => 'rate_limit',
            str_contains($failureType, 'server') || str_contains($failureType, '500') => 'server_error',
            str_contains($failureType, 'duplicate') => 'duplicate',
            $failureType === 'exception' => 'exception',
            default => 'unknown',
        };
    }

    private function updateFbrLog(Invoice $invoice, array $response, int $executionMs, string $environment, ?string $failureCategory): void
    {
        try {
            $latestLog = FbrLog::where('invoice_id', $invoice->id)
                ->orderBy('created_at', 'desc')
                ->first();

            if ($latestLog) {
                $latestLog->update([
                    'submission_latency_ms' => $executionMs,
                    'environment_used' => $environment,
                    'failure_category' => $failureCategory,
                    'retry_count' => $this->attempts() - 1,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning("Failed to update FBR log metrics for invoice #{$invoice->id}: " . $e->getMessage());
        }
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
