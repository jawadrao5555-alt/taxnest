<?php

namespace App\Jobs;

use App\Models\MedicineCatalogueSync;
use App\Services\Pharmacy\MedicineCatalogueSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One time-boxed slice of the DRAP catalogue crawl (Task 1579).
 *
 * The DB queue's retry_after is 90s, so a job that ran the whole ~1-hour crawl
 * would be handed to a second worker while still running. Instead each job
 * walks pages for ~55s, commits its cursor into medicine_catalogue_syncs, and
 * re-queues itself. A deploy that restarts the queue service simply loses the
 * current slice; the next start()/schedule resumes from the saved cursor.
 *
 * Runs on the `bulk` queue — the live worker already serves zip,default,bulk.
 */
class SyncMedicineCatalogueJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'bulk';

    public int $timeout = 80;

    public int $tries = 1;

    public function __construct(public int $syncId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(MedicineCatalogueSyncService $service): void
    {
        $run = MedicineCatalogueSync::find($this->syncId);
        if (!$run || !$run->isActive()) {
            return;
        }
        // Another worker already owns a fresher slice of this run (double
        // dispatch after a stall) — the cursor tells: if progress moved within
        // the last few seconds, let that one continue.
        $finished = $service->runSlice($run, MedicineCatalogueSyncService::SLICE_SECONDS);
        if (!$finished) {
            self::dispatch($this->syncId)->delay(now()->addSeconds(2));
        }
    }

    public function failed(?\Throwable $e): void
    {
        $run = MedicineCatalogueSync::find($this->syncId);
        if ($run && $run->isActive()) {
            $run->forceFill([
                'state' => 'failed',
                'last_error' => mb_substr('job failed: ' . ($e?->getMessage() ?? 'unknown'), 0, 1000),
                'completed_at' => now(),
                'last_progress_at' => now(),
            ])->save();
        }
        Log::error('[medicine-catalogue] sync job failed', ['sync_id' => $this->syncId, 'error' => $e?->getMessage()]);
    }
}
