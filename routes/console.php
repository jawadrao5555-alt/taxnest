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

Schedule::job(new NightlyComplianceCronJob)->daily()->at('02:00');
Schedule::job(new CheckFbrTokenExpiryJob)->daily()->at('06:00');
Schedule::job(new SyncPosOfflineInvoicesJob)->everyTwoMinutes();
Schedule::job(new SyncFbrPosOfflineInvoicesJob)->everyTwoMinutes();
Schedule::command('pos:clean-zombie-tables')->everyFifteenMinutes();
Schedule::job(new CheckTrialExpiryJob)->dailyAt('03:00');
Schedule::command('trial:reminders')->dailyAt('08:00');
// Auto-close prior POS trading days for companies that opted into 24h auto day-close.
Schedule::command('pos:auto-dayclose')->hourly()->withoutOverlapping();
