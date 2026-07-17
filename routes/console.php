<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\NightlyComplianceCronJob;
use App\Jobs\CheckFbrTokenExpiryJob;
use App\Jobs\SyncPosOfflineInvoicesJob;
use App\Jobs\SyncFbrPosOfflineInvoicesJob;
use App\Jobs\CheckTrialExpiryJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scheduler heartbeat: records the last time the cron actually fired so the
// admin System Control page can show whether background jobs run on prod.
Schedule::call(function () {
    \App\Models\SystemSetting::set(
        'scheduler_last_heartbeat',
        now()->toDateTimeString(),
        'Last time the Laravel scheduler (schedule:run cron) executed.'
    );
})->everyFifteenMinutes()->name('scheduler-heartbeat');

Schedule::job(new NightlyComplianceCronJob)->daily()->at('02:00');
Schedule::job(new CheckFbrTokenExpiryJob)->daily()->at('06:00');
Schedule::job(new SyncPosOfflineInvoicesJob)->everyTwoMinutes();
Schedule::job(new SyncFbrPosOfflineInvoicesJob)->everyTwoMinutes();
Schedule::command('pos:clean-zombie-tables')->everyFifteenMinutes();
Schedule::job(new CheckTrialExpiryJob)->dailyAt('03:00');
Schedule::command('trial:reminders')->dailyAt('08:00');
// Storage hygiene: delete receipt FILES of proofs verified/rejected >12 months
// ago (DB rows kept for audit, file_pruned_at flagged). Rows stay reviewable.
Schedule::command('payment-proofs:prune')->dailyAt('04:30');
// Auto-close prior POS trading days for companies that opted into midnight auto day-close
// (midnight-based, 1 full day grace — a day closes at the second midnight after it).
Schedule::command('pos:auto-dayclose')->hourly()->withoutOverlapping();
