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

// Queue heartbeat: dispatched to the database queue every five minutes; the
// timestamp is only written when a queue WORKER processes the job, so the
// admin System Control page can detect a dead worker even while cron is fine.
Schedule::job(new \App\Jobs\QueueHeartbeatJob)->everyFiveMinutes()->name('queue-heartbeat');

// Stale-heartbeat watchdog: if the queue worker dies while cron is still
// alive, email every admin (synchronously — the dead queue can't deliver it).
// Throttled to once per 12h; the admin-panel banner covers the rest.
Schedule::call(function () {
    try {
        $warn = \App\Services\HeartbeatHealth::warning();
        if (!$warn || !$warn['queue_stale'] || !\App\Services\HeartbeatHealth::shouldNotify()) {
            return;
        }

        $emails = \App\Models\AdminUser::whereNotNull('email')
            ->where('email', '!=', '')
            ->pluck('email')->unique()->values();
        if ($emails->isEmpty()) {
            return;
        }

        $lastBeat = $warn['queue_at'] ? $warn['queue_at']->format('Y-m-d H:i') . ' (' . $warn['queue_at']->diffForHumans() . ')' : 'never recorded';
        $body = "The queue worker on the live server appears to have stopped.\n\n"
            . "Last queue heartbeat: {$lastBeat}\n"
            . "Queued emails (consultant alerts, invoice shares) are silently piling up in the jobs table until the worker runs again.\n\n"
            . "Check the crontab on the live server (cPanel -> Cron Jobs): the queue-worker entry must exist alongside the every-minute schedule:run entry.\n"
            . 'System status: ' . route('saas.admin.system') . "\n\n"
            . 'TaxNest';

        \Illuminate\Support\Facades\Mail::raw($body, function ($m) use ($emails) {
            $m->to($emails->all())->subject('WARNING: TaxNest queue worker has stopped');
        });

        \App\Services\MailHealth::recordSuccess();
        \App\Services\HeartbeatHealth::markNotified();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::warning('Heartbeat watchdog email failed', ['error' => $e->getMessage()]);
        \App\Services\MailHealth::recordFailure('Queue-worker stale-heartbeat alert', $e);
    }
})->everyFifteenMinutes()->name('heartbeat-watchdog');

// Support-mailbox health probe: try an IMAP connect every 15 min so the admin
// banner appears/clears proactively even when nobody opens the Support Inbox.
// Only runs when SUPPORT_MAIL_PASSWORD is configured; the service records
// SupportMailHealth failure/success internally and this never throws.
Schedule::call(function () {
    try {
        $svc = app(\App\Services\SupportMailService::class);
        if ($svc->isConfigured()) {
            $svc->probeConnection();
        }
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::warning('Support mailbox health probe failed unexpectedly', ['error' => $e->getMessage()]);
    }
})->everyFifteenMinutes()->name('support-mail-health-probe');

// Support-mailbox prolonged-outage watchdog: if the support@ IMAP mailbox has
// been failing for 6h+ (banner alone only helps admins who open the panel),
// email every admin synchronously via the noreply SMTP (no queue dependency).
// Throttled to once per 12h; throttle resets when the mailbox recovers.
Schedule::call(function () {
    try {
        if (!\App\Services\SupportMailHealth::shouldNotify()) {
            return;
        }

        $failure = \App\Services\SupportMailHealth::current();
        if (!$failure) {
            return;
        }

        $emails = \App\Models\AdminUser::whereNotNull('email')
            ->where('email', '!=', '')
            ->pluck('email')->unique()->values();
        if ($emails->isEmpty()) {
            return;
        }

        $since = $failure['at']
            ? \Illuminate\Support\Carbon::parse($failure['at'])->format('Y-m-d H:i') . ' (' . ($failure['ago'] ?? '') . ')'
            : 'unknown';
        $body = "The support@ mailbox (IMAP) on the live server has been unreachable for several hours.\n\n"
            . "Failing since: {$since}\n"
            . "Consecutive failed checks: {$failure['count']}\n"
            . 'Last error: ' . ($failure['error'] !== '' ? $failure['error'] : 'n/a') . "\n\n"
            . "New customer support emails are NOT reaching the Support Inbox until this is fixed.\n"
            . "Most common cause: the cPanel mailbox password changed (update SUPPORT_MAIL_PASSWORD) or the mail server is down.\n"
            . 'Support inbox: ' . route('saas.admin.support-inbox') . "\n\n"
            . 'TaxNest';

        \Illuminate\Support\Facades\Mail::raw($body, function ($m) use ($emails) {
            $m->to($emails->all())->subject('WARNING: TaxNest support mailbox has been down for hours');
        });

        \App\Services\MailHealth::recordSuccess();
        \App\Services\SupportMailHealth::markNotified();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::warning('Support-mailbox watchdog email failed', ['error' => $e->getMessage()]);
        \App\Services\MailHealth::recordFailure('Support-mailbox outage alert', $e);
    }
})->everyFifteenMinutes()->name('support-mail-watchdog');

// Fix C: withoutOverlapping(120) — prevents a second queue:work from picking up a
// new dispatch while the job is still running (live cache store = database, which
// supports locks via cache_locks table, so no Redis needed).
// Fix D: moved from 02:00 to 02:30 to separate from the hourly pos:auto-dayclose
// (02:00) and CheckTrialExpiryJob (03:00), reducing concurrent connection load.
Schedule::job(new NightlyComplianceCronJob)->daily()->at('02:30')->withoutOverlapping(120);
Schedule::job(new CheckFbrTokenExpiryJob)->daily()->at('06:00');
Schedule::job(new SyncPosOfflineInvoicesJob)->everyTwoMinutes();
Schedule::job(new SyncFbrPosOfflineInvoicesJob)->everyTwoMinutes();
Schedule::command('pos:clean-zombie-tables')->everyFifteenMinutes();
Schedule::job(new CheckTrialExpiryJob)->dailyAt('03:00');
Schedule::command('trial:reminders')->dailyAt('08:00');
// Admin nudge: pending payment proofs whose auto-granted 10-day access ends
// within 2 days — verify before the reconciler locks a paying customer out.
Schedule::command('payment-proofs:expiry-reminders')->dailyAt('08:10');
// Storage hygiene: delete receipt FILES of proofs verified/rejected >12 months
// ago (DB rows kept for audit, file_pruned_at flagged). Rows stay reviewable.
Schedule::command('payment-proofs:prune')->dailyAt('04:30');
// DB hygiene: NULL the heavy rows_json/result_json of invoice import batches
// older than 30 days (summary counts stay for the import-history page).
Schedule::command('import-batches:prune')->dailyAt('04:45');
// Auto-close prior POS trading days for companies that opted into auto day-close
// (6 AM next-morning rule, owner 23 Jul 2026 — a day closes at 6:00 AM the next
// morning if nobody closed it manually; before 6 AM yesterday stays open).
Schedule::command('pos:auto-dayclose')->hourly()->withoutOverlapping();
// Cloudflare guard: Rocket Loader rewrites inline scripts and kills the POS
// sale screen's Alpine boot. Every 30 min: GET the live homepage; if the
// rocket-loader injection marker is found the command auto-turns it OFF via
// the Cloudflare API and emails admins (throttled inside the command so a
// lingering edge-cached marker can't spam one email per run).
Schedule::command('cloudflare:check-rocket-loader')->everyThirtyMinutes();
