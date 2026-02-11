<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\NightlyComplianceCronJob;
use App\Jobs\CheckFbrTokenExpiryJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new NightlyComplianceCronJob)->daily()->at('02:00');
Schedule::job(new CheckFbrTokenExpiryJob)->daily()->at('06:00');
