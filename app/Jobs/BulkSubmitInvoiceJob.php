<?php

namespace App\Jobs;

use App\Http\Controllers\InvoiceController;
use App\Models\Invoice;
use App\Models\InvoiceBulkSubmission;
use App\Models\Subscription;
use App\Services\AuditLogService;
use App\Services\HybridComplianceScorer;
use App\Services\InvoiceActivityService;
use App\Services\RiskIntelligenceEngine;
use App\Services\ScheduleEngine;
use App\Services\VendorRiskEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Task 1245: bulk-submit one draft invoice to FBR as part of a batch.
 *
 * One job per invoice so a slow/failed FBR call never blocks the rest of the
 * batch. Mirrors the guard sequence of InvoiceController@submit (smart mode)
 * and reuses submitToFbrSync() — the same shared path the DI push API uses —
 * so no validation/side effect is bypassed. Per-invoice results are collected
 * on a durable invoice_bulk_submissions row that the invoices list polls —
 * so the run survives the browser closing, a restart or a deploy.
 */
class BulkSubmitInvoiceJob implements ShouldQueue
{
    use Queueable;

    /**
     * Own queue. A run of several thousand invoices takes hours; on the default
     * queue it would park every other background job (mail, POS sync, exports)
     * behind it for that whole time.
     */
    public const QUEUE = 'bulk';

    public $tries = 1;
    public $timeout = 180;

    public function __construct(
        public int $invoiceId,
        public int $batchId,
        public ?int $userId = null,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(): void
    {
        try {
            $this->process();
        } catch (\Throwable $e) {
            Log::error("BulkSubmitInvoiceJob: invoice #{$this->invoiceId} exception: " . $e->getMessage());
            self::recordResult($this->batchId, $this->invoiceId, 'failed', 'Unexpected error: ' . $e->getMessage());
        }
    }

    public function failed(?\Throwable $e = null): void
    {
        self::recordResult($this->batchId, $this->invoiceId, 'failed', 'Job failed: ' . ($e ? $e->getMessage() : 'unknown error'));
    }

    protected function process(): void
    {
        // The shop pressed Stop: drain the remaining queue instead of submitting.
        if (self::runCancelled($this->batchId)) {
            self::recordResult($this->batchId, $this->invoiceId, 'skipped', 'Run stopped before this invoice was submitted.');
            return;
        }

        $invoice = Invoice::withoutGlobalScopes()->find($this->invoiceId);
        if (!$invoice) {
            self::recordResult($this->batchId, $this->invoiceId, 'skipped', 'Invoice not found (may have been deleted).');
            return;
        }

        // Same early rejects as the single-invoice submit path.
        if (in_array($invoice->status, ['locked', 'pending_verification']) || $invoice->is_fbr_processing) {
            $msg = match (true) {
                $invoice->status === 'locked' => 'Already submitted to FBR.',
                $invoice->status === 'pending_verification' => 'Pending FBR verification.',
                default => 'Already being processed.',
            };
            self::recordResult($this->batchId, $this->invoiceId, 'skipped', $msg, $invoice);
            return;
        }

        if (!empty($invoice->fbr_invoice_number)) {
            self::recordResult($this->batchId, $this->invoiceId, 'skipped', 'Already has FBR number: ' . $invoice->fbr_invoice_number, $invoice);
            return;
        }

        $subscription = Subscription::where('company_id', $invoice->company_id)
            ->where('active', true)
            ->first();
        if ($subscription && ($subscription->isExpired() || ($subscription->trial_ends_at && $subscription->isTrialExpired()))) {
            // Stop cleanly: invoice stays draft with a clear message.
            self::recordResult($this->batchId, $this->invoiceId, 'skipped', 'Subscription expired — invoice left as draft.', $invoice);
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
            self::recordResult($this->batchId, $this->invoiceId, 'failed', $errorMsg, $invoice);
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
            self::recordResult($this->batchId, $this->invoiceId, 'skipped', 'No longer in a submittable state (submitted by another request).', $invoice->fresh());
            return;
        }

        $invoice->refresh();

        InvoiceActivityService::log($invoice->id, $invoice->company_id, 'submitted', [
            'mode' => 'bulk',
            'compliance_score' => $scoreResult['final_score'],
            'risk_level' => $scoreResult['risk_level'],
            'bulk_batch' => $this->batchId,
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
            self::recordResult($this->batchId, $this->invoiceId, 'success', 'FBR: ' . ($result['fbr_invoice_number'] ?? ''), $fresh);
        } elseif ($result['status'] === 'pending_verification') {
            self::recordResult($this->batchId, $this->invoiceId, 'pending', 'Ambiguous FBR response — verify on FBR portal.', $fresh);
        } else {
            $errors = !empty($result['errors']) ? implode(' | ', array_slice($result['errors'], 0, 3)) : 'FBR submission failed';
            self::recordResult($this->batchId, $this->invoiceId, 'failed', $errors, $fresh);
        }
    }

    // ── Batch progress (DATABASE-backed) ───────────────────────────────────
    //
    // Progress used to live in one cache entry holding every per-invoice
    // result, read-modify-written under a lock on every completion. That is
    // what capped a run at 1,000 invoices, and on live (CACHE_STORE=database)
    // a deploy's `cache:clear` erased a running batch outright. Counters now
    // move with a single atomic UPDATE on a durable row.

    /** The result buckets — also the counter column names on the batch row. */
    public const RESULT_BUCKETS = ['success', 'failed', 'skipped', 'pending'];

    /** Has the shop asked to stop this run? (checked before each submission) */
    public static function runCancelled(int $batchId): bool
    {
        return (bool) DB::table('invoice_bulk_submissions')->where('id', $batchId)->value('cancel_requested');
    }

    /**
     * Record one invoice's outcome against the batch row.
     *
     * One UPDATE, so parallel workers can never lose an increment and there is
     * no lock that can time out. A worker killed mid-job may be retried by the
     * queue and counted twice; display clamps to the total and completion is a
     * >= test, so an overcount can never strand a run as "unfinished".
     */
    public static function recordResult(int $batchId, int $invoiceId, string $status, string $message, ?Invoice $invoice = null): void
    {
        $bucket = in_array($status, self::RESULT_BUCKETS, true) ? $status : 'skipped';

        $updated = DB::table('invoice_bulk_submissions')
            ->where('id', $batchId)
            ->update([
                'done' => DB::raw('done + 1'),
                $bucket => DB::raw($bucket . ' + 1'),
                'last_progress_at' => now(),
                'updated_at' => now(),
            ]);

        if (!$updated) {
            Log::warning("BulkSubmitInvoiceJob: batch #{$batchId} row missing while recording invoice #{$invoiceId}.");
            return;
        }

        // Only problems are kept — nobody reads 6,000 success lines, and the
        // row has to stay small enough to poll every few seconds.
        if ($bucket !== 'success') {
            self::appendFailure($batchId, $invoiceId, $bucket, $message, $invoice);
        }

        self::settleIfComplete($batchId);
    }

    protected static function appendFailure(int $batchId, int $invoiceId, string $bucket, string $message, ?Invoice $invoice = null): void
    {
        try {
            DB::transaction(function () use ($batchId, $invoiceId, $bucket, $message, $invoice) {
                $batch = InvoiceBulkSubmission::whereKey($batchId)->lockForUpdate()->first();
                if (!$batch) {
                    return;
                }
                $failures = $batch->failures ?? [];
                if (count($failures) >= InvoiceBulkSubmission::MAX_FAILURES_KEPT) {
                    return; // capped — the counters still tell the full story
                }
                $failures[] = [
                    'invoice_id' => $invoiceId,
                    'invoice_number' => $invoice ? ($invoice->internal_invoice_number ?? $invoice->invoice_number) : null,
                    'status' => $bucket,
                    'message' => mb_substr($message, 0, 300),
                ];
                $batch->failures = $failures;
                $batch->save();
            });
        } catch (\Throwable $e) {
            // A lost failure line must never cost the invoice its counter.
            Log::warning("BulkSubmitInvoiceJob: could not record failure detail for invoice #{$invoiceId}: " . $e->getMessage());
        }
    }

    /**
     * Close the run once every dispatched invoice has reported back.
     *
     * Only valid while the batch is 'running' — during 'dispatching' the total
     * is not final yet, so an early done == total must not end the run. The
     * conditional UPDATE means exactly one worker performs the transition.
     */
    public static function settleIfComplete(int $batchId): void
    {
        $row = DB::table('invoice_bulk_submissions')->where('id', $batchId)->first();
        if (!$row || $row->state !== 'running') {
            return;
        }
        if ((int) $row->done < (int) $row->total) {
            return;
        }

        DB::table('invoice_bulk_submissions')
            ->where('id', $batchId)
            ->where('state', 'running')
            ->update([
                'state' => $row->cancel_requested ? 'cancelled' : 'completed',
                'completed_at' => now(),
                'last_progress_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
