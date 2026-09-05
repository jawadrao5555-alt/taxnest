# Rolling back a live deploy

The live origin — host, key, app path, PHP binary, service names — is defined in
exactly one file, `scripts/lib/live-host.sh`. Source it rather than typing an
address; the retired host is still powered on and will happily accept a `git
reset` and report success while every shop keeps using the real server.

```bash
source scripts/lib/live-host.sh
ssh "${LIVE_SSH_OPTS[@]}" "$LIVE_SSH_HOST"
```

## Before you roll back, decide which kind of problem you have

A rollback is two separate decisions, and conflating them is how a bad hour
becomes a bad week:

| Problem | Roll back the CODE | Restore the DATABASE |
|---|---|---|
| A screen is broken / a page 500s | yes | no |
| A migration added something wrong but no shop has written to it | yes | no |
| Rows were destroyed or overwritten | yes | yes — and accept losing every bill rung since the backup |

**Restoring the database is never the first move.** Shops ring bills
continuously; a restore silently deletes everything that happened after the
dump. Code first, and only restore data if data is genuinely damaged.

## 1. Roll the code back

Every deploy records where live was standing before it ran. Read that marker
rather than guessing a commit:

```bash
cat ~/.taxnest-last-deploy      # EPOCH|COMMIT_SHA — the commit this deploy REPLACED
```

Then, on the live server:

```bash
cd /var/www/taxnest
git rev-parse HEAD                       # write this down first
git reset --hard <TARGET_COMMIT>
composer install --no-dev --optimize-autoloader
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo systemctl reload php-fpm            # web OPcache still holds the OLD code until this runs
sudo systemctl restart taxnest-queue     # a supervised worker runs its boot-time code FOREVER otherwise
```

Then prove it, from the workspace — never from the deploy output:

```bash
bash scripts/check-live-deploy.sh
```

### The two steps people skip

- **The PHP-FPM reload.** Without it the website keeps serving the code that was
  compiled into shared memory. The page looks unchanged and you conclude the
  rollback failed. Prove the master re-executed (fresh worker PIDs, or the
  service log recording the reload *after* you asked for it). Do **not** demand
  that zero pre-reload workers survive — a graceful reload lets a busy worker
  finish, and long-polling endpoints hold one for a while.
- **The queue restart.** The website and the background worker are two different
  copies of the code. Skip this and bills, exports and regulator filings keep
  executing the release you just rolled away from.

## 2. Migrations

Roll a migration back only when it is genuinely in the way. A new table that
nobody has written to is harmless; dropping it is not.

```bash
cd /var/www/taxnest
php artisan migrate:rollback --step=1 --force
```

The healthcare batch's migrations are reversible — each `down()` drops the
tables it created, in dependency order. One deliberate exception: the site
geofence columns are **not** dropped, because a coordinate somebody physically
surveyed is not worth losing to a rollback and every reader guards on
`hasColumn`.

Beware the reverse trap: an older release running against a NEWER schema is
usually fine (extra columns are ignored), but a newer release against an older
schema is not. If you roll code back, leave the schema alone unless it is the
thing that is broken.

## 3. Restoring the database (last resort)

Pre-deploy backups live in `~/backups` on the live server.

```bash
ls -lt ~/backups | head
```

Restore into a **scratch database first** and look at it. Restoring straight
over the live database turns a recoverable situation into an unrecoverable one.

```bash
cd /var/www/taxnest
DB=$(grep '^DB_DATABASE=' .env | cut -d= -f2 | tr -d '"')
U=$(grep  '^DB_USERNAME=' .env | cut -d= -f2 | tr -d '"')
P=$(grep  '^DB_PASSWORD=' .env | cut -d= -f2- | tr -d '"')

CNF=$(mktemp); chmod 600 "$CNF"
printf '[client]\nhost=127.0.0.1\nport=3306\nuser=%s\npassword="%s"\n' "$U" "$P" > "$CNF"

sudo mysql -e "CREATE DATABASE taxnest_restore_probe CHARACTER SET utf8mb4;"
sudo mysql -e "GRANT ALL ON taxnest_restore_probe.* TO '$U'@'localhost';"
zcat ~/backups/<FILE>.sql.gz | mysql --defaults-file="$CNF" taxnest_restore_probe
```

Use `--defaults-file`, never `--defaults-extra-file`: a stray `database=` line in
a user's `~/.my.cnf` silently voids extra-file credentials.

Compare row counts per table against live before you trust it. Counts that are
a few rows short on `pos_transactions` are normal — those are bills rung after
the dump, not a broken backup. Check the timestamps before concluding anything.

### Proving a backup, which is the only thing that makes it a backup

A dump that has never been restored is a file, not a backup. The pre-deploy
backup for this release was restored into a scratch database and verified:
212 tables in, 212 tables out, no missing tables, and per-table row counts
matching except for a single `pos_transactions` row created one second *after*
the dump completed.

Our schema has TEXT UNIQUE indexes that restore on **MariaDB** but not on stock
MySQL 8, which aborts at error 1170 and abandons the rest of the dump. The live
server runs MariaDB. Restoring one of our dumps onto a MySQL 8 box will look
like it worked and quietly lose tables.

Drop the scratch database when you are done:

```bash
sudo mysql -e "DROP DATABASE taxnest_restore_probe;"
```

## 4. Afterwards

Watch the log through the next busy period, not just for a minute:

```bash
bash scripts/live-error-triage.sh
```

Production is stricter than development in two ways that only ever show up
here: lazy relation loading throws, and integer columns come back from PDO as
strings. Both surface as exceptions in the log rather than as a broken-looking
page, so the log is the place to look.

---

# Release record — pharmacy and healthcare (5 Sep 2026)

The largest single jump live has taken in a while: FBR POS pharmacy mode, the
hospital pharmacy module, healthcare HR and attendance, patient/OPD core,
inpatient and operations, the healthcare foundation, and the business-type and
preset changes that touch every panel's signup.

It shipped in two runs. The first carried the batch itself; while it was being
verified, the Nest ERPS product-line rename and a healthcare patient-billing
core merged onto main, so a second run shipped those and everything was
re-proven against the final revision. The table below is the state that matters.

| | |
|---|---|
| Live commit after the deploy | `035b92c2` |
| **Rollback target** (what live was on before the second run) | `17075bf6` |
| Rollback target for the whole batch (before the first run) | `5651e0ce` |
| Backup | `~/backup-pre-task1572-r2-*.sql.gz`, 233 tables; the first run's backup was **restored into a scratch database** to prove the procedure |
| Settings baseline | taken by the deploy itself, immediately before migrating |

Rollback is section 1 above. The one command that matters:

```bash
cd /var/www/taxnest && git reset --hard 17075bf6   # or 5651e0ce to undo the whole batch
```

…followed by the composer install, the cache rebuild, **the PHP-FPM reload and
the queue restart**. Leave the schema alone: the new tables are additive and an
older release ignores them.

## What was proven on live, not assumed

Everything below was re-run against the final revision, `035b92c2`.

- **The commit** — read from the server itself, not from the deploy output.
- **The schema** — all 48 new tables and all 52 added columns from the first run,
  plus the eight `health_*` billing tables and the `erps_vertical` column from
  the second, queried directly on the live database (241 tables in total). A
  `migrate` status line was not accepted as evidence.
- **No shop's settings moved** — the deploy's own before/after comparison
  reported one added column and *no existing value changed*.
- **The pharmacy round** (`scripts/fbr-live-pharmacy-round.sh`, against the
  standing live FBR QA shop): the mode gate refuses the screens and hides the
  nav entry while off; medicine fields persist; three batches received with
  different expiries; the picker is shortest-expiry-first; the expired batch is
  flagged unsellable **and** refused at the counter; the short-dated batch warns
  but still sells; a loose sale takes 0.3 of a 10-unit pack; a return goes back
  onto the *original* batch and no other; quarantine sticks; a write-off with no
  reason and no responsible person is refused; a distributor claim is raised,
  printed and settled. Teardown returns the shop to a plain FBR shop.
- **Nobody else disturbed** — no live company is in pharmacy mode, every
  pharmacy row belongs to the QA shop, the PRA and FBR screen smokes pass, and
  Digital Invoice was checked with a read-only render probe rather than by
  logging into a real customer's account.
- **The healthcare panel** — proved to *boot*, not merely to be routable, by
  building a throwaway organisation inside a transaction, rendering the real
  screens through the real HTTP kernel, and rolling it back.
- **Containment** — self-registration is shut pre-pilot (browser gets the login
  page, an API client gets a 404), no other panel links to `/health`, and no
  healthcare company exists on live.
- **Background work** — the queue service came back and the `schedule:run` cron
  is present and firing.

## The probes are the deliverable

`scripts/health-live-render-probe.php` and `scripts/di-live-render-probe.php`
exist because two things on live cannot be checked by logging in: a panel with
no companies yet, and a panel whose every company is a real business. Both run
read-only inside a rolled-back transaction. Two traps they encode, which cost a
run each:

- Bootstrapping the **console** kernel leaves the `web` middleware group
  unregistered, and every route dies with *Target class [web] does not exist*.
  Bootstrap the HTTP kernel and dispatch through it.
- Live runs with `APP_DEBUG` off, so a thrown error arrives as an anonymous 500
  page and the probe learns nothing. Swap in an exception handler that
  re-throws.

And one that applies to every probe, not just these: **a check that prints
`FAIL` but still exits 0 is worse than no check at all.** The pharmacy round
originally computed its stock assertions in an embedded Python block that only
printed; the round would have reported PASS while the expiry, loose-sale and
return-to-original-batch maths was wrong. Every assertion now returns a non-zero
status and the shell propagates it into the run's verdict. Copy the scripts onto
the host into the repo's own `scripts/` directory — they resolve
`vendor/autoload.php` relative to themselves, so `/tmp` fails — and delete them
in the same command, so a throwaway probe never outlives its incident.
