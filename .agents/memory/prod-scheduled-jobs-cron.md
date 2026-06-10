---
name: PROD scheduled jobs need a cron
description: Why Laravel Schedule:: tasks silently never run on the owner's cPanel production host.
---

# Rule
Anything registered with `Schedule::...` in `routes/console.php` (trial reminders, trial-expiry job, FBR token-expiry checks, POS offline sync, cleanup jobs) ONLY fires if the production host runs `php artisan schedule:run` every minute via cron.

The owner deploys on shared cPanel MySQL and does NOT automatically have this cron. So a newly scheduled feature can pass all dev tests yet never execute on PROD.

**Why:** Laravel's scheduler is just a dispatcher invoked by the OS cron once per minute; with no cron there is no tick, so no scheduled command ever runs.
**How to apply:** whenever you add or rely on a `Schedule::` entry, explicitly remind the owner to add the cPanel cron line `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1` (and that `queue:work`/queued jobs need their own worker or cron).
