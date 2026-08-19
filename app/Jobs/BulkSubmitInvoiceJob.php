<?php

namespace App\Jobs;

use App\Http\Controllers\InvoiceController;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\AuditLogService;
use App\Services\HybridComplianceScorer;
use App\Services\InvoiceActivityService;
use App\Services\RiskIntelligenceEngine;
use App\Services\ScheduleEngine;
use App\Services\VendorRiskEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Task 1245: bulk-submit one draft invoice to FBR as part of a batch.
 *
 * One job per invoice so a slow/failed FBR call never blocks the rest of the
 * batch. Mirrors the guard sequence of InvoiceController@submit (smart mode)
 * and reuses submitToFbrSync() — the same shared path the DI push API uses —
 * so no validation/side effect is bypassed. Per-invoice results are collected
 * in a cache-backed batch record that the invoices list polls.
 */
class BulkSubmitInvoiceJob implements ShouldQueue
{
    use Queueable;

    public $tries = 1;
    public $timeout = 180;

    public function __construct(
        public int $invoiceId,
        public string $batchKey,
        public ?int $userId = null,
    ) {
    }

    public function handle(): void
    {
        try {
            $this->process();
        } catch (\Throwable $e) {
            Log::error("BulkSubmitInvoiceJob: invoice #{$this->invoiceId} exception: " . $e->getMessage());
            self::recordResult($this->batchKey, $this->invoiceId, 'failed', 'Unexpected error: ' . $e->getMessage());
        }
    }

    public function failed(?\Throwable $e = null): void
    {
        self::recordResult($this->batchKey, $this->invoiceId, 'failed', 'Job failed: ' . ($e ? $e->getMessage() : 'unknown error'));
    }

    protected function process(): void
    {
        $invoice = Invoice::withoutGlobalScopes()->find($this->invoiceId);
        if (!$invoice) {
            self::recordResult($this->batchKey, $this->invoiceId, 'skipped', 'Invoice not found (may have been deleted).');
            return;
        }

        // Same early rejects as the single-invoice submit path.
        if (in_array($invoice->status, ['locked', 'pending_verification']) || $invoice->is_fbr_processing) {
            $msg = match (true) {
                $invoice->status === 'locked' => 'Already submitted to FBR.',
                $invoice->status === 'pending_verification' => 'Pending FBR verification.',
                default => 'Already being processed.',
            };
            self::recordResult($this->batchKey, $this->invoiceId, 'skipped', $msg, $invoice);
            return;
        }

        if (!empty($invoice->fbr_invoice_number)) {
            self::recordResult($this->batchKey, $this->invoiceId, 'skipped', 'Already has FBR number: ' . $invoice->fbr_invoice_number, $invoice);
            return;
        }

        $subscription = Subscription::where('company_id', $invoice->company_id)
            ->where('active', true)
            ->first();
        if ($subscription && ($subscription->isExpired() || ($subscription->trial_ends_at && $subscription->isTrialExpired()))) {
            // Stop cleanly: invoice stays draft with a clear message.
            self::recordResult($this->batchKey, $this->invoiceId, 'skipped', 'Subscription expired — invoice left as draft.', $invoice);
            return;
        }

        $invoice->load('items', 'company');

        // Submission-time item validation (same as InvoiceController@submit).
        $itemsForValidation = $invoice->items->map(function ($item) {
            return [
                'schedule_type' => $item->schedule_type ?? 'standard',
                'tax_rate' => $item->tax_rate,
                'sro_schedule_no' => $item->sro_schedule_no,
                'serial_no' => $item->serial_no,
                'mrp' => $item->mrp,
                'price' => $item->price,
                'quantity' => $item->quantity,
                'tax' => $item->tax,
            ];
        })->toArray();

        $standardTaxRate = $invoice->company ? $invoice->company->getStandardTaxRateValue() : 18.0;
        $submissionCheck = ScheduleEngine::validateForSubmission($itemsForValidation, $standardTaxRate);
        if (!$submissionCheck['valid']) {
            $errorMsg = trim($submissionCheck['message'] . ' ' . implode(' | ', $submissionCheck['errors']));
            // Invoice stays draft — same as the single-submit path, which
            // rejects before flipping any state.
            self::recordResult($this->batchKey, $this->invoiceId, 'failed', $errorMsg, $invoice);
            return;
        }

        $riskAnalysis = RiskIntelligenceEngine::analyzeForPreSubmission($invoice);
        $isInternalCompany = $invoice->company && $invoice->company->is_internal_account;
        if ($riskAnalysis['should_block'] && !$isInternalCompany) {
            $riskMessages = array_map(fn ($r) => $r['message'], $riskAnalysis['risks']);
            InvoiceActivityService::log($invoice->id, $invoice->company_id, 'intelligence_warning', [
                'risk_score' => $riskAnalysis['risk_score'],
                'risk_level' => $riskAnalysis['risk_level'],
                'risks' => $riskMessages,
                'note' => 'Proceeding with submission despite risk warning',
            ]);
        }

        IntelligenceProcessingJob::dispatch($invoice->id);

        $scoreResult = HybridComplianceScorer::score($invoice);
        if ($scoreResult['risk_level'] === 'CRITICAL') {
            InvoiceActivityService::log($invoice->id, $invoice->company_id, 'compliance_warning', [
                'reason' => 'CRITICAL risk level (proceeding with submission)',
                'score' => $scoreResult['final_score'],
                'rule_flags' => $scoreResult['rule_result']['flags'],
            ]);
        }

        // Compare-and-swap under row lock — the hard guarantee against a
        // double submit (bulk clicked twice, or bulk racing a manual submit).
        $locked = DB::transaction(function () use ($invoice) {
            $lockedInvoice = Invoice::withoutGlobalScopes()->where('id', $invoice->id)->lockForUpdate()->first();
            if (!$lockedInvoice || !in_array($lockedInvoice->status, ['draft', 'failed']) || $lockedInvoice->is_fbr_processing || !empty($lockedInvoice->fbr_invoice_number)) {
                return false;
            }
            $lockedInvoice->status = 'draft';
            $lockedInvoice->is_fbr_processing = true;
            $lockedInvoice->submitted_at = now();
            $lockedInvoice->submission_mode = 'bulk';
            $lockedInvoice->save();
            return true;
        });

        if (!$locked) {
            self::recordResult($this->batchKey, $this->invoiceId, 'skipped', 'No longer in a submittable state (submitted by another request).', $invoice->fresh());
            return;
        }

        $invoice->refresh();

        InvoiceActivityService::log($invoice->id, $invoice->company_id, 'submitted', [
            'mode' => 'bulk',
            'compliance_score' => $scoreResult['final_score'],
            'risk_level' => $scoreResult['risk_level'],
            'bulk_batch' => $this->batchKey,
            'submitted_by' => $this->userId,
        ]);

        AuditLogService::log('invoice_submitted', 'Invoice', $invoice->id, null, [
            'mode' => 'bulk',
            'compliance_score' => $scoreResult['final_score'],
            'risk_level' => $scoreResult['risk_level'],
        ], $invoice->company_id);

        if ($invoice->buyer_ntn) {
            $vendorResult = VendorRiskEngine::calculateVendorScore($invoice->company_id, $invoice->buyer_ntn);
            VendorRiskEngine::persistVendorProfile($invoice->company_id, $invoice->buyer_ntn, $invoice->buyer_name, $vendorResult);
        }

        // Shared submit path — FBR log, ledger entry, integrity hash,
        // compliance recalcs all happen inside.
        $result = app(InvoiceController::class)->submitToFbrSync($invoice);

        $fresh = $invoice->fresh();
        if ($result['status'] === 'success') {
            self::recordResult($this->batchKey, $this->invoiceId, 'success', 'FBR: ' . ($result['fbr_invoice_number'] ?? ''), $fresh);
        } elseif ($result['status'] === 'pending_verification') {
            self::recordResult($this->batchKey, $this->invoiceId, 'pending', 'Ambiguous FBR response — verify on FBR portal.', $fresh);
        } else {
            $errors = !empty($result['errors']) ? implode(' | ', array_slice($result['errors'], 0, 3)) : 'FBR submission failed';
            self::recordResult($this->batchKey, $this->invoiceId, 'failed', $errors, $fresh);
        }
    }

    // ── Cache-backed batch progress record ─────────────────────────────────

    public static function cacheKey(string $batchKey): string
    {
        return "bulk_submit_batch:{$batchKey}";
    }

    public static function runningLockKey(int $companyId): string
    {
        return "bulk_submit_running:{$companyId}";
    }

    public static function startBatch(string $batchKey, int $companyId, array $invoiceIds): void
    {
        Cache::put(self::cacheKey($batchKey), [
            'company_id' => $companyId,
            'total' => count($invoiceIds),
            'done' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'pending' => 0,
            'finished' => false,
            'started_at' => now()->toIso8601String(),
            'results' => [],
        ], now()->addHours(6));
    }

    /**
     * Atomically record one invoice's outcome in the batch record.
     *
     * Parallel queue workers can finish jobs of the same batch at the same
     * moment, so the read-modify-write of the batch record is guarded by a
     * per-batch distributed cache lock (file/database/redis/array stores all
     * support Cache::lock). Results are keyed by invoice id (write-once) and
     * every counter is recomputed from the results map, so the update is
     * idempotent — a retried/duplicate record can never double-count, and
     * `finished` flips exactly when all invoices have a result. The company
     * running lock is released exactly once, on the not-finished → finished
     * transition inside the same critical section.
     */
    public static function recordResult(string $batchKey, int $invoiceId, string $status, string $message, ?Invoice $invoice = null): void
    {
        $apply = function () use ($batchKey, $invoiceId, $status, $message, $invoice) {
            $key = self::cacheKey($batchKey);
            $batch = Cache::get($key);
            if (!$batch) {
                return;
            }

            $wasFinished = (bool) ($batch['finished'] ?? false);

            $results = $batch['results'] ?? [];
            $results[(string) $invoiceId] = [
                'invoice_id' => $invoiceId,
                'invoice_number' => $invoice ? ($invoice->internal_invoice_number ?? $invoice->invoice_number) : null,
                'fbr_invoice_number' => $invoice->fbr_invoice_number ?? null,
                'status' => $status,
                'message' => mb_substr($message, 0, 500),
            ];
            $batch['results'] = $results;

            // Recompute all counters from the keyed results — idempotent.
            $batch['done'] = count($results);
            foreach (['success', 'failed', 'skipped', 'pending'] as $bucket) {
                $batch[$bucket] = count(array_filter($results, fn ($r) => $r['status'] === $bucket));
            }
            $batch['finished'] = $batch['done'] >= $batch['total'];

            Cache::put($key, $batch, now()->addHours(6));

            if ($batch['finished'] && !$wasFinished) {
                Cache::forget(self::runningLockKey($batch['company_id']));
            }
        };

        try {
            Cache::lock('bulk_submit_batchlock:' . $batchKey, 15)->block(10, $apply);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            Log::warning("BulkSubmitInvoiceJob: batch lock timeout for {$batchKey}, invoice #{$invoiceId} — applying without lock.");
            $apply();
        }
    }
}
