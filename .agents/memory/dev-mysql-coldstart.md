---
name: Dev MySQL cold-start 500s on landing
description: Why the dev preview intermittently 500s with "Connection refused 127.0.0.1:9000" and how the app is hardened against it.
---

# Recurring dev-preview "Connection refused" on landing page

**Symptom:** Dev preview (worf.replit.dev) landing page returns a 500
`Illuminate\Database\QueryException SQLSTATE[HY000] [2002] Connection refused
(Host: 127.0.0.1, Port: 9000, Database: taxnest_staging)` — trace goes through
`SystemSetting::get` from the public `whatsapp-support` blade component.

**Root cause (NOT a real outage):** When the Replit workspace sleeps and wakes
(e.g. overnight), all three workflows restart. The `Laravel Server` workflow
starts serving HTTP a few seconds before the `MySQL Staging` workflow finishes
booting mysqld. Any request landing in that warm-up window hits a not-yet-ready
DB. It self-heals within seconds once mysqld is up — verify with a fresh
`curl http://127.0.0.1:5000/` (expect HTTP 200) before doing anything heavier.

**Do not** treat this as a code regression, a dropped fix, or a deploy gap. The
LIVE cPanel site is unaffected (its MySQL is always-on).

**Workflow FAILED with NO output = stale lock files:** if the `MySQL Staging`
workflow status is FAILED with zero log output and restarts keep failing, delete
stale `.local/mysql_run/mysql.sock.lock`, `mysql.pid`, and `mysql.sock`, then
restart the workflow — mysqld refuses to boot silently over a leftover sock.lock
from an unclean workspace sleep.

**`ss -ltn` lies here:** in this sandbox `ss` shows NO listening sockets even
when the port is open. Confirm the DB with `/dev/tcp/127.0.0.1/9000` or a real
`mysql -h 127.0.0.1 -P 9000 -uroot -e 'SELECT 1'`, not with `ss | grep 9000`.

**Wake-window can also present as a DEAD POS sale screen (Jul 2026):** owner
loaded /pos/invoice/create right in the warm-up window → browser console flooded
with "Alpine Expression Error: X is not defined" for EVERY binding (console
buffer keeps only the tail, so it looks like just the receipt-popup section
died). Root x-data eval failed because the page/script arrived broken during
warm-up (truncated response). Server HTML verified clean (curl + node --check +
html5lib DOM balance) and a fresh Playwright login reproduced ZERO errors —
self-heals on refresh. Do NOT hunt for a Blade/Alpine regression before
re-testing with a fresh browser context; also check the "artifact crashed"
banner timestamps against workflow restart times first.

**Hardening applied:** `SystemSetting::get()` wraps its query in try/catch and
returns the `$default` on any `\Throwable`. So a transient DB blip during warm-up
just hides the optional WhatsApp/trial-lock widgets instead of 500-ing the whole
public page. **Why:** a public marketing page must never hard-crash over one
optional settings lookup — applies to PROD too, not only dev.
