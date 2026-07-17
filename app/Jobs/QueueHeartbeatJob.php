<?php

namespace App\Jobs;

use App\Models\SystemSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queue-processed heartbeat: this job is dispatched to the DATABASE QUEUE by
 * the scheduler. It only records its timestamp when a queue worker actually
 * picks it up and runs it — so a fresh timestamp proves the worker is alive,
 * even when the scheduler (cron) heartbeat looks green.
 */
class QueueHeartbeatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public function handle(): void
    {
        SystemSetting::set(
            'queue_last_heartbeat',
            now()->toDateTimeString(),
            'Last time a database queue worker processed the heartbeat job.'
        );
    }
}
