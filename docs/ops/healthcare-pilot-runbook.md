# Healthcare pilot — operations runbook

**Scope:** the first hospital running Nest ERPS (healthcare) on the Islamabad origin.
**Audience:** whoever is holding the pager during the pilot. Read it before launch, not during an incident.

A hospital is not a shop. A shop that goes down loses a sale; a hospital that goes down loses a
patient's record of what they were given and what they owe. Everything below is written so that the
recovery path is known **before** it is needed, and so that each step can actually be executed
rather than merely believed.

---

## 0. The one command to run first

```bash
php artisan health:pilot-readiness --company=<pilot company id>
```

Read-only, safe on the live server at any hour. It reports the conditions whose absence is
otherwise **silent**: an un-run migration, an unseeded chart of accounts, a `sync` queue, a
hospital with no active owner login, a language file that drifted. `FAIL` blocks launch. `WARN` is a
decision somebody has to confirm out loud, not something to scroll past.

The schema rows are per module — core, OPD, pharmacy, inpatient, accounts, HR — and **every**
module's tables are required on the host whether or not the pilot hospital has that module switched
on today. A hospital can turn a module on any morning; discovering then that its tables never
shipped is exactly what this row exists to prevent. A missing table is named in the row, it is never
skipped.

Run it:

- after every deploy that touches healthcare,
- the morning of go-live,
- and as the first step of any "the system is behaving strangely" report.

---

## 1. Deploying

Deploys go through the existing toolchain — healthcare does not get its own path:

```bash
bash scripts/check-live-deploy.sh      # is live actually behind, or is this a cache problem?
bash scripts/deploy-live.sh            # push, pull, migrate, rebuild caches, reload FPM, restart queue
```

Two things about this host that repeatedly catch people out:

- **A push to GitHub deploys nothing.** There is no hook on the origin. Code reaches live only via
  `deploy-live.sh` (or a manual pull *followed by a cache rebuild* — a pull without one leaves new
  routes against an old route cache and every page 500s).
- **The queue is a supervised service, so a deploy must restart it.** `deploy-live.sh` does this.
  A queue worker that was not restarted keeps running the *old* code indefinitely, which during a
  pilot shows up as "the import finished but nothing changed".

### Healthcare migrations

All healthcare migrations are additive and idempotent (`hasTable` / `hasColumn` guarded), so
`php artisan migrate --force` is safe to re-run. After migrating, re-run the readiness command —
that is how you prove the migration actually landed rather than being marked "Ran" against a table
that does not exist.

---

## 2. Background work

Two moving parts, neither of which exists by default on a bare host and **neither of which fails
loudly**:

| What | Why the hospital notices | How to verify |
|---|---|---|
| Queue worker (supervised service) | Bulk setup imports and long reports never finish | `php artisan queue:monitor` / readiness `queue backlog` + `failed jobs` rows |
| `schedule:run` cron (one entry, as the app user) | **Inpatient bed-day charges stop accruing** — a discharge bill comes out short | readiness `scheduler cron` + `scheduled bed-day charges` rows |

The bed-day poster is the one scheduled job with direct money consequences for a hospital. If the
cron is missing, an admitted patient's stay silently stops billing from the day the cron died.

Both readiness rows read a **timestamp the scheduler itself wrote**, not a list of what the code
could run. `schedule:list` will happily show the entry on a host with no cron at all, which is
precisely the case that has to be caught. The nightly run stamps its own time, and it is wrapped so
that only the scheduler can write that stamp — running the command by hand does not make the check
go green. A missing or 48-hour-old stamp is a FAIL, not a warning.

On a freshly built host both rows fail until cron has fired once. That is correct: the pilot is not
ready until it has. Install the cron, wait for one nightly run, then re-check.

Recovery for a stuck queue:

```bash
php artisan queue:restart          # ask workers to finish the current job and exit
sudo systemctl restart <queue service>
php artisan queue:failed           # anything here is work the hospital thinks happened
php artisan queue:retry all        # only after reading why it failed
```

A failed job during a pilot is never "just retry it" — read what it was, because a half-applied
setup import or a half-posted bill has to be reconciled, not repeated.

---

## 3. Backup and — the part that matters — restore

A backup nobody has restored is a hypothesis.

**Daily backup:** `php artisan db:backup` (existing `DatabaseBackup` command). It must be running
before the pilot's first real patient, not after.

**Prove the backup weekly during the pilot** by restoring it somewhere that is not production and
checking three things:

1. The dump *restores at all* — our schema has TEXT unique indexes that only MariaDB accepts.
   Restoring into MySQL 8 silently loses tables. Match the live major version.
2. `health_patients`, `health_bills` and `health_journal_lines` row counts are within a day of live.
3. A single known bill's total equals the sum of its charges in the restored copy.

If a restore has never been performed, the pilot does not have a backup — it has a file.

**Patient attachments and imported workbooks** live on the local disk, not in the database. The
storage directory must be in the same backup rotation, or a restore brings back the ledger without
the scan that justified it.

---

## 4. Device integration health

Attendance devices and printers are the two device classes in the pilot.

- **Attendance punches** arrive with a `source_ref`; re-sending the same punch is deduplicated, so a
  device that catches up after being offline is safe. What is *not* safe is a device whose clock has
  drifted: a punch stamped an hour late lands in the wrong duty window and reads as a missed punch.
  Check device time whenever a day looks wrong before blaming the roster.
- After any bulk device re-sync, recompute the affected days rather than trusting the stored day
  rows — the day is a derived record.
- **Receipt and prescription printing** is browser-side. A print that comes out at the wrong width
  is a paper/driver setting on that counter's machine, not a server change; never force a body width
  to the paper width to "fix" it.

---

## 5. Observability during the pilot

Watch these, in this order, when something is reported:

1. `storage/logs/laravel.log` on live — the exception, with the company id in the request context.
2. The readiness command for the pilot company — most "it broke" reports during a pilot are a
   missing prerequisite, not a bug.
3. `php artisan queue:failed` — work the hospital believes completed.
4. The hospital's own audit run (owner screen) — it reconciles bills, stock and accounts and will
   name the mismatch faster than reading rows by hand.

Do not put throwaway `.php` debug scripts in `public/`. A file there is a public endpoint that
bypasses every route and guard, and it outlives the incident that created it.

---

## 6. Rollback

Rollback is **code-only**. See `deployment/ROLLBACK.md` for the mechanics.

The rule that matters for healthcare: **do not roll a migration back.** Healthcare migrations are
additive; rolling one back drops a column that already holds clinical or financial data written
since the deploy. If a deploy must be reversed, revert the *code* to the previous commit, redeploy,
and leave the schema ahead. An extra unused column costs nothing; a dropped one costs a patient's
record.

If a data problem — not a code problem — needs reversing, prefer the application's own reversal
paths (reverse a charge, cancel a bill, reverse a payment). Every one of them writes a
counter-entry that keeps the books balanced. A manual `DELETE` does not.

---

## 7. Go / no-go checklist for launch morning

- [ ] `health:pilot-readiness --company=<id>` reports no `FAIL`, and every `WARN` has a named owner.
- [ ] Last deploy verified: `check-live-deploy.sh` clean, live HEAD == intended commit.
- [ ] Queue service running and restarted after the last deploy.
- [ ] `schedule:run` cron present, as the app user, exactly once.
- [ ] A backup taken **today** and restored somewhere today.
- [ ] Storage directory writable, and in the backup rotation.
- [ ] Pilot hospital: modules on, departments/services/doctors/medicines imported, opening stock and
      opening balances posted and agreed with the hospital's own figures.
- [ ] One active owner login plus one per role, tested by the person who will use it.
- [ ] Printers proved on real paper for: OPD receipt, prescription, pharmacy label, discharge
      summary, patient statement.
- [ ] The parallel-run plan and the feedback route are agreed in writing (see
      `docs/qa/task-1555-healthcare-pilot-rollout.md`).
