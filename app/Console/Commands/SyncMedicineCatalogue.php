<?php

namespace App\Console\Commands;

use App\Models\MedicineCatalogueSync;
use App\Services\Pharmacy\MedicineCatalogueSyncService;
use Illuminate\Console\Command;

/**
 * catalogue:sync-drap — start (or resume) the DRAP medicine catalogue crawl.
 *
 *   php artisan catalogue:sync-drap            queue it (weekly schedule / admin button path)
 *   php artisan catalogue:sync-drap --sync     run inline until done (first live seed under nohup)
 *   php artisan catalogue:sync-drap --status   print the latest run
 *   php artisan catalogue:sync-drap --cancel   ask the active run to stop after its current page
 *
 * Source: DRAP Pharmaceutical Product Price Index (Government of Pakistan
 * public data) — https://e.dra.gov.pk/public/price
 */
class SyncMedicineCatalogue extends Command
{
    protected $signature = 'catalogue:sync-drap {--sync : Run inline in this process until finished} {--status : Show the latest run} {--cancel : Request cancellation of the active run} {--delay= : Seconds between pages (default 1)}';

    protected $description = 'Sync the global medicine catalogue from the DRAP price index (resumable, idempotent)';

    public function handle(MedicineCatalogueSyncService $service): int
    {
        if (!MedicineCatalogueSyncService::tablesReady()) {
            $this->error('medicine_catalogue tables missing — run migrations first.');

            return self::FAILURE;
        }

        if ($this->option('status')) {
            $run = MedicineCatalogueSync::latest('id')->first();
            $this->line($run ? json_encode($run->toStatusArray(count(MedicineCatalogueSyncService::phases())), JSON_PRETTY_PRINT) : 'no runs yet');

            return self::SUCCESS;
        }

        if ($this->option('cancel')) {
            $run = MedicineCatalogueSync::active();
            if (!$run) {
                $this->line('no active run');

                return self::SUCCESS;
            }
            $service->requestCancel($run);
            $this->info('cancel requested for run #' . $run->id);

            return self::SUCCESS;
        }

        $inline = (bool) $this->option('sync');
        $run = $service->start($inline ? 'cli' : 'schedule', null, !$inline);
        $this->info(($run->wasRecentlyCreated ? 'started' : 'continuing') . ' run #' . $run->id . ' (phase ' . $run->phase_index . ', page ' . $run->next_page . ')');

        if (!$inline) {
            return self::SUCCESS;
        }

        $delay = $this->option('delay') !== null ? (float) $this->option('delay') : null;
        $lastReport = 0;
        while (true) {
            $done = $service->runSlice($run, 300, $delay);
            $run->refresh();
            if ((int) $run->pages_done - $lastReport >= 20 || $done) {
                $lastReport = (int) $run->pages_done;
                $this->line(sprintf('[%s] %s pages=%d/%s rows=%d created=%d updated=%d prices=%d errors=%d',
                    now()->format('H:i:s'), $run->state, $run->pages_done, $run->total_pages ?? '?',
                    $run->rows_seen, $run->rows_created, $run->rows_updated, $run->price_changes, $run->errors_count));
            }
            if ($done) {
                break;
            }
        }
        $this->info('finished: ' . $run->state . ($run->last_error ? ' — ' . $run->last_error : ''));

        return $run->state === 'completed' ? self::SUCCESS : self::FAILURE;
    }
}
