---
name: PROD scheduled jobs need a cron
description: Why Laravel Schedule:: tasks silently never run on the owner's cPanel production host.
---

# Rule
Anything registered with `Schedule::...` in `routes/console.php` (trial reminders, trial-expiry job, FBR token-expiry checks, POS offline sync, cleanup jobs) ONLY fires if the production host runs `php artisan schedule:run` every minute via cron.

The owner deploys on shared cPanel MySQL and does NOT automatically have this cron. So a newly scheduled feature can pass all dev tests yet never execute on PROD.

**Why:** Laravel's scheduler is just a dispatcher invoked by the OS cron once per minute; with no cron there is no tick, so no scheduled command ever runs.
**How to apply:** whenever you add or rely on a `Schedule::` entry, explicitly remind the owner to add the cPanel cron line `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1` (and that `queue:work`/queued jobs need their own worker or cron).

# STATUS: cron INSTALLED on live (21 Jul 2026, via SSH `crontab -`)
Two lines, both every minute:
1. `/usr/local/bin/ea-php84 /home/taxnestc/public_html/artisan schedule:run >> /dev/null 2>&1`
2. `/usr/local/bin/ea-php84 -d disable_functions= /home/taxnestc/public_html/artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1`

# Live-host gotchas learned during install
- **pcntl functions are in `disable_functions` on the live CLI** (pcntl_signal etc.) → bare `queue:work` dies with "Call to undefined function pcntl_signal()" because the extension IS loaded (supportsAsyncSignals passes) but the function is disabled. Fix: `-d disable_functions=` on the queue:work invocation ONLY (verified working).
- **Never update the live crontab incrementally** (`crontab -l | grep -v X; echo new | crontab -`) — a line silently vanished doing this. Always install the FULL crontab atomically: `printf '%s\n%s\n' "line1" "line2" | crontab -`, then verify with `crontab -l | wc -l`.
- **Verify health via SystemSetting heartbeats**: `queue_last_heartbeat` (QueueHeartbeatJob, dispatched every 5 min by the scheduler, written only when a WORKER processes it) proves BOTH cron lines in one check; `scheduler_last_heartbeat` (every 15 min) proves schedule:run alone. Admin System Control page reads both.
- Queue backlog drained same day: 33 jobs stuck since 8 May 2026 (32 analytics + 1 FBR retry) all processed, 0 failed.
