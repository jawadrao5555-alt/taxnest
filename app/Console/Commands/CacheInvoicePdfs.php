<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\InvoicePdfCacheService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Renders invoice PDFs ahead of anyone asking for them.
 *
 * A bulk download is only slow because of rendering. Doing it quietly in the
 * background, a little at a time, means the shop's archive is already sitting
 * on disk when it presses the button — which turns an hour-long wait into a
 * download that starts straight away.
 */
class CacheInvoicePdfs extends Command
{
    protected $signature = 'invoices:cache-pdfs
        {--seconds=45 : how long this run may spend rendering}
        {--company= : only this company}';

    protected $description = 'Render invoice PDFs into the cache so bulk downloads start instantly';

    public function handle(): int
    {
        // Filing comes first. Rendering competes for the same limited CPU, so
        // stand aside completely while a submission run is in progress.
        if (DB::table('jobs')->where('queue', 'bulk')->exists()) {
            $this->info('Bulk filing in progress — leaving the CPU to it.');
            return self::SUCCESS;
        }

        $deadline = time() + max(5, (int) $this->option('seconds'));
        $company = $this->option('company');
        $rendered = 0;
        $failed = 0;
        $lastId = 0;
        $reasons = [];
        $firstFailedId = null;

        while (time() < $deadline) {
            // Cheap pass first: ids and timestamps are all it takes to know
            // whether a cached PDF is still current.
            $rows = Invoice::withoutGlobalScopes()
                ->select(['id', 'company_id', 'created_at', 'updated_at'])
                ->when($company, fn ($q) => $q->where('company_id', (int) $company))
                // Drafts are still being edited; caching them would mostly
                // cache work that is about to go stale.
                ->where('status', '!=', 'draft')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->take(300)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            $lastId = (int) $rows->last()->id;

            $todo = $rows
                ->filter(fn ($row) => InvoicePdfCacheService::currentPath($row) === null
                    && !InvoicePdfCacheService::recentlyFailed((int) $row->company_id, (int) $row->id))
                ->pluck('id');

            if ($todo->isEmpty()) {
                continue;
            }

            $invoices = Invoice::withoutGlobalScopes()
                ->with(['items', 'company', 'branch'])
                ->whereIn('id', $todo)
                ->get();

            foreach ($invoices as $invoice) {
                if (time() >= $deadline) {
                    break 2;
                }

                try {
                    InvoicePdfCacheService::ensure($invoice);
                    $rendered++;
                } catch (\Throwable $e) {
                    $failed++;
                    // Do not write a line per invoice. When the cause is
                    // environmental — a missing extension, a full disk — every
                    // invoice on the platform fails with the identical
                    // sentence, and the log becomes the second problem: this
                    // exact loop once wrote 529,562 copies of one message into
                    // a 105 MB file. One example per run says the same thing.
                    $reasons[$e->getMessage()] = ($reasons[$e->getMessage()] ?? 0) + 1;
                    InvoicePdfCacheService::markFailed($invoice);
                    $firstFailedId ??= (int) $invoice->id;
                }
            }
        }

        if ($failed > 0) {
            Log::warning('Invoice PDF cache: some invoices could not be rendered', [
                'failed' => $failed,
                'rendered' => $rendered,
                'first_invoice_id' => $firstFailedId,
                // Distinct causes only, with how many invoices hit each.
                'reasons' => $reasons,
            ]);
        }

        $this->info("Rendered {$rendered} invoice PDFs" . ($failed ? ", {$failed} failed" : '') . '.');

        return self::SUCCESS;
    }
}
