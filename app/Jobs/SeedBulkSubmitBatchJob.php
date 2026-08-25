<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\InvoiceBulkSubmission;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Queues the per-invoice submit jobs for one bulk run.
 *
 * Why this is a job and not a loop in the controller: a run can be several
 * thousand invoices, and every dispatch is a row inserted into the jobs table.
 * Doing that inside the web request meant the shop had to keep the page open
 * long enough for it to finish — and it capped the feature at 1,000 invoices.
 * Now the request only creates the batch row and returns; everything else
 * happens on the queue, so the browser (or the phone app) can be closed the
 * moment the button is clicked.
 *
 * The job dispatches at most CHUNK invoices and then re-queues ITSELF for the
 * next chunk, tracking a cursor on the batch row. Each run therefore finishes
 * in a couple of seconds — comfortably inside the queue's retry_after window,
 * so a slow run can never be re-reserved and dispatch the same invoice twice.
 */
class SeedBulkSubmitBatchJob implements ShouldQueue
{
    use Queueable;

    /** Invoices queued per run before this job re-queues itself. */
    public const CHUNK = 500;

    public $tries = 3;
    public $timeout = 120;

    public function __construct(public int $batchId)
    {
        // Own queue: a 6,000-invoice run must not park emails, POS sync and
        // every other background job behind it for hours.
        $this->onQueue(BulkSubmitInvoiceJob::QUEUE);
    }

    public function handle(): void
    {
        $batch = InvoiceBulkSubmission::find($this->batchId);
        if (!$batch || !$batch->isActive()) {
            return;
        }

        if ($batch->cancel_requested) {
            $this->finishDispatching($batch);
            return;
        }

        if ($batch->state === 'queued') {
            $batch->state = 'dispatching';
            $batch->started_at = $batch->started_at ?? now();
        }

        $ids = $this->nextIds($batch);

        foreach ($ids as $id) {
            BulkSubmitInvoiceJob::dispatch((int) $id, $batch->id, $batch->user_id);
        }

        if (!empty($ids)) {
            $batch->cursor_id = (int) end($ids);
            $batch->dispatched = $batch->dispatched + count($ids);
        }
        $batch->last_progress_at = now();
        $batch->save();

        // More to go — hand the next chunk to a fresh job so this one stays short.
        if (count($ids) === self::CHUNK) {
            self::dispatch($batch->id);
            return;
        }

        $this->finishDispatching($batch);
    }

    public function failed(?\Throwable $e = null): void
    {
        Log::error("SeedBulkSubmitBatchJob: batch #{$this->batchId} failed to dispatch: " . ($e ? $e->getMessage() : 'unknown'));

        $batch = InvoiceBulkSubmission::find($this->batchId);
        if ($batch && $batch->isActive()) {
            // Do NOT rewrite the total down to what was queued: invoices that
            // were never dispatched would then be presented as "all done" and
            // the shop would stop chasing them. Interrupted is the honest
            // state — whatever was already queued keeps going, the untouched
            // invoices are still drafts, and the next click picks them up.
            $batch->state = 'stalled';
            $batch->completed_at = now();
            $batch->save();
        }
    }

    /**
     * Everything is queued: the dispatched count is now the authoritative total
     * (invoices submitted by hand between the click and here simply are not in
     * the run), and completion becomes possible.
     */
    protected function finishDispatching(InvoiceBulkSubmission $batch): void
    {
        $batch->total = $batch->dispatched;
        $batch->state = 'running';
        $batch->last_progress_at = now();
        $batch->save();

        BulkSubmitInvoiceJob::settleIfComplete($batch->id);
    }

    /** The next page of still-eligible invoice ids for this run. */
    protected function nextIds(InvoiceBulkSubmission $batch): array
    {
        $query = Invoice::withoutGlobalScopes()
            ->where('company_id', $batch->company_id)
            ->where('status', $batch->target_status)
            ->where('is_fbr_processing', false)
            ->whereNull('fbr_invoice_number')
            ->where('id', '>', $batch->cursor_id)
            ->where('id', '<=', $batch->max_invoice_id);

        if ($batch->scope === 'selected' && !empty($batch->invoice_ids)) {
            $query->whereIn('id', $batch->invoice_ids);
        }

        return $query->orderBy('id')->limit(self::CHUNK)->pluck('id')->all();
    }
}
