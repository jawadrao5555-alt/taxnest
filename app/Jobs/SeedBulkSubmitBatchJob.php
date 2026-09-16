<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\InvoiceBulkSubmission;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
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
        // Recover rows committed by a predecessor that died after advancing
        // the cursor but before it could enqueue the child jobs.
        if (\Illuminate\Support\Facades\Schema::hasTable('invoice_bulk_submission_outbox')) {
            $this->dispatchOutbox();
        }
        $claim = $this->claimNextChunk();
        if ($claim === null) {
            return;
        }

        [$batch, $ids, $finishedDispatching] = $claim;

        // The rows were written with the cursor transaction. Dispatching can
        // be repeated after a crash; the outbox unique key plus the bulk
        // invoice claim make it harmless and prevent a lost invoice.
        if (\Illuminate\Support\Facades\Schema::hasTable('invoice_bulk_submission_outbox')) {
            $this->dispatchOutbox();
        } else {
            // Pre-migration compatibility only; RC production requires the
            // transactional outbox migration before bulk dispatch is enabled.
            foreach ($ids as $id) {
                BulkSubmitInvoiceJob::dispatch((int) $id, $batch->id, $batch->user_id);
            }
        }

        if (!$finishedDispatching) {
            self::dispatch($batch->id);
            return;
        }

        BulkSubmitInvoiceJob::settleIfComplete($batch->id);
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
     * Claim the next cursor range and advance it in one short transaction.
     * Network submission happens later and is never performed while this lock
     * is held.
     *
     * @return array{0: InvoiceBulkSubmission, 1: array<int>, 2: bool}|null
     */
    protected function claimNextChunk(): ?array
    {
        return DB::transaction(function () {
            $batch = InvoiceBulkSubmission::withoutGlobalScopes()->whereKey($this->batchId)->lockForUpdate()->first();
            if (!$batch || !$batch->isActive()) {
                return null;
            }
            if ($batch->cancel_requested) {
                $batch->state = 'cancelled';
                $batch->completed_at = now();
                $batch->last_progress_at = now();
                $batch->save();
                return null;
            }
            if ($batch->state === 'queued') {
                $batch->state = 'dispatching';
                $batch->started_at = $batch->started_at ?? now();
            }

            $ids = $this->nextIds($batch);
            if (!empty($ids)) {
                $batch->cursor_id = (int) end($ids);
                $batch->dispatched += count($ids);
                if (\Illuminate\Support\Facades\Schema::hasTable('invoice_bulk_submission_outbox')) {
                    DB::table('invoice_bulk_submission_outbox')->insertOrIgnore(array_map(
                        fn (int $id) => [
                            'batch_id' => $batch->id,
                            'invoice_id' => $id,
                            'user_id' => $batch->user_id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                        $ids
                    ));
                }
            }
            $finished = count($ids) < self::CHUNK;
            if ($finished) {
                // Preserve the original requested total if a dispatch failure
                // occurs; this normal terminal transition only occurs after all
                // eligible rows in the frozen range were claimed.
                $batch->total = $batch->dispatched;
                $batch->state = 'running';
            }
            $batch->last_progress_at = now();
            $batch->save();

            return [$batch, $ids, $finished];
        });
    }

    /** Deliver committed outbox rows; marking is deliberately after dispatch. */
    protected function dispatchOutbox(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('invoice_bulk_submission_outbox')) {
            return;
        }
        DB::table('invoice_bulk_submission_outbox')
            ->where('batch_id', $this->batchId)
            ->whereNull('dispatched_at')
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($rows) {
                foreach ($rows as $row) {
                    BulkSubmitInvoiceJob::dispatch((int) $row->invoice_id, (int) $row->batch_id, $row->user_id ? (int) $row->user_id : null);
                    DB::table('invoice_bulk_submission_outbox')
                        ->where('id', $row->id)
                        ->whereNull('dispatched_at')
                        ->update(['dispatched_at' => now(), 'updated_at' => now()]);
                }
            });
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
