<?php

namespace App\Jobs;

use App\Models\InvoiceZipExport;
use App\Services\InvoiceZipBuilderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Renders invoice PDFs for a ZIP export ahead of the process packing it.
 *
 * Rendering is nearly all of a bulk export's work — 176 ms an invoice, which
 * is three hours of CPU for a large distributor — and every PDF is
 * independent of every other. So several of these run at once, each taking
 * the invoice ids that fall in its own slot, and write into the same staging
 * directory the chunk loop reads from.
 *
 * They hold no lease and write no state. If one dies, or the queue never runs
 * them at all, the chunk loop simply renders those invoices itself: the
 * helpers can only make the export faster, never wrong.
 */
class PrerenderInvoicePdfsJob implements ShouldQueue
{
    use Queueable;

    /** A failed pre-render costs nothing: the chunk loop covers it. */
    public $tries = 1;

    public $timeout = 300;

    public function __construct(
        public int $exportId,
        public int $slot,
        public int $slots
    ) {
        // Same queue as the build itself, so ZIP work never competes with FBR
        // filing for the bulk workers.
        $this->onQueue('zip');
    }

    public function handle(): void
    {
        $export = InvoiceZipExport::find($this->exportId);

        if (!$export || !$export->isActive()) {
            return;
        }

        // The invoice set is frozen by initialize(); until that has run there
        // is nothing to render against.
        if ($export->status === 'pending') {
            self::dispatch($this->exportId, $this->slot, $this->slots)->delay(now()->addSeconds(5));
            return;
        }

        $rendered = InvoiceZipBuilderService::prerenderSlice(
            $export,
            $this->slot,
            $this->slots,
            time() + 240
        );

        // Nothing rendered means this slot is fully staged — stop. Otherwise
        // keep going in a fresh job so no attempt outlives its timeout.
        if ($rendered > 0 && $export->fresh()?->isActive()) {
            self::dispatch($this->exportId, $this->slot, $this->slots);
        }
    }
}
