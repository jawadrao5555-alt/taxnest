<?php

namespace App\Console\Commands;

use App\Models\InvoiceImportBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * DB hygiene for DI bulk invoice imports.
 *
 * Each batch keeps its full parsed rows (rows_json, up to 10,000 rows) and
 * result_json in longText columns — a single 10k-row batch can be several MB.
 * After the retention window the heavy JSON is NULLed (summary counts,
 * filename, status and timestamps stay, so the import-history page keeps
 * working); the error-report download for a pruned batch returns a friendly
 * "expired" message instead of an empty spreadsheet.
 *
 * Idempotent — safe to re-run any number of times (prod runs it via the
 * schedule:run cron; already-pruned rows are excluded by the NULL checks).
 */
class PruneInvoiceImportBatches extends Command
{
    protected $signature = 'import-batches:prune {--days=30 : Prune batches finished more than this many days ago}';

    protected $description = 'Clear heavy rows_json/result_json from old completed/failed invoice import batches (summary counts are kept)';

    public function handle(): int
    {
        if (!Schema::hasTable('invoice_import_batches')) {
            $this->info('invoice_import_batches table missing — nothing to prune.');
            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $hasPrunedAt = Schema::hasColumn('invoice_import_batches', 'pruned_at');

        // Terminal batches (completed/failed) older than the window, judged by
        // finished_at when set, else updated_at. Also covers batches uploaded
        // but never processed (stuck in validated/queued/processing) — those
        // hold full rows_json forever if the user abandoned them.
        $query = InvoiceImportBatch::query()
            ->where(function ($q) {
                $q->whereNotNull('rows_json')->orWhereNotNull('result_json');
            })
            ->where(function ($q) use ($cutoff) {
                $q->where(function ($terminal) use ($cutoff) {
                    $terminal->whereIn('status', ['completed', 'failed'])
                        ->where(function ($age) use ($cutoff) {
                            $age->where('finished_at', '<', $cutoff)
                                ->orWhere(function ($fallback) use ($cutoff) {
                                    $fallback->whereNull('finished_at')->where('updated_at', '<', $cutoff);
                                });
                        });
                })->orWhere(function ($abandoned) use ($cutoff) {
                    $abandoned->whereIn('status', ['validated', 'queued', 'processing'])
                        ->where('updated_at', '<', $cutoff);
                });
            });

        $pruned = 0;
        // Chunk by id so a huge backlog can't build one massive UPDATE.
        $query->orderBy('id')->select('id')->chunkById(200, function ($batches) use (&$pruned, $hasPrunedAt) {
            $ids = $batches->pluck('id')->all();
            $update = ['rows_json' => null, 'result_json' => null, 'updated_at' => now()];
            if ($hasPrunedAt) {
                $update['pruned_at'] = now();
            }
            $pruned += InvoiceImportBatch::whereIn('id', $ids)->update($update);
        });

        $this->info("Pruned heavy JSON from {$pruned} import batch(es) older than {$days} day(s).");
        return self::SUCCESS;
    }
}
