# Safe server migration — HostCry (Phoenix, USA) → new VPS

Moving TaxNest without losing a byte, and being able to *prove* it.

The whole design rests on one idea: **do not trust the copy, measure it.**
Every file is hashed on both servers and compared. Every database table is
compared by row count *and* `CHECKSUM TABLE`. If anything disagrees, the
cutover refuses to continue and the old server keeps serving.

---

## Safety properties

| | |
|---|---|
| **The old server's app and data are read-only** | Nothing inside the app root or the database is ever written. Outside it, the scripts stage helpers in `~/migration-tools` and a `0600` credentials file that is deleted on exit; during the final window only `artisan down` and a paused crontab, both reversed automatically. |
| **Copying happens while the site is live** | `--single-transaction` on 180 InnoDB tables means no locks. Shops keep billing throughout the days-long first pass. |
| **Everything is repeatable** | Re-run any script. `rsync` moves only what changed; the database reload is idempotent. |
| **Nothing routes through this workspace** | The new server pulls directly from the old one over SSH, under `nohup`. A dropped connection or a tool timeout cannot corrupt a transfer. |
| **Verification is independent** | Manifests are generated fresh on both hosts and diffed here, rather than trusting rsync's own report. |
| **Rollback is one DNS record** | Point Cloudflare back at `66.29.138.229`. Under a minute, for as long as the old plan stays paid. |
| **Credentials never hit `ps`** | The DB password goes into a `0600` defaults file on the live host, and is deleted on exit. |

---

## What moves

Home is 6.0 GB, but 2.4 GB of that is dead weight (`repositories/` stale
clones, `.composer`, `.cagefs`, old logs) that is deliberately left behind.
Real payload is about **1 GB plus a 91 MB database**.

| Item | Size | Files | Carried by git? |
|---|---|---|---|
| App code | — | — | ✅ `git clone` |
| MySQL `taxnestc_db` (180 InnoDB tables) | 91 MB | — | ❌ |
| `.env` | 4 KB | 1 | ❌ copy by hand |
| `public/downloads` (APKs, agent zip) | 248 MB | 45 | ❌ |
| `public/videos` | 305 MB | 42 | ❌ |
| `public/annex-invoices` | 9.5 MB | 11 | ❌ |
| `storage/app/private` (invoice PDFs, audit packs, proofs) | 350 MB | 6,241 | ❌ |
| `storage/app/public` (logos, product & AI images) | 5.3 MB | 18 | ❌ |
| `storage/app/{firebase,import-holds,mpdf}` | 1.6 MB | 11 | ❌ |
| Mail (3 boxes) | 3.4 MB | — | ❌ move to Zoho/Brevo |

**Not copied on purpose:** `storage/framework/{cache,sessions,views}`,
`storage/logs`, `storage/app/tmp-bulk-pdf`, `vendor/`, `node_modules/`.
All rebuilt on the new server.

Sessions, cache and queue all live in the **database** (`SESSION_DRIVER`,
`CACHE_STORE`, `QUEUE_CONNECTION` are all `database`), so they travel with the
dump — **nobody gets logged out at cutover.**

---

## Setup, once

1. Create `.local/migration.env` (gitignored):

   ```bash
   DST_HOST=203.0.113.10
   DST_USER=root
   DST_APP=/var/www/taxnest
   DST_PHP=/usr/bin/php
   DST_DB_NAME=taxnest
   DST_DB_USER=taxnest
   DST_KEY=/home/runner/workspace/.local/ssh/newserver_key
   ```

2. Let the new server reach the old one — it does the pulling:

   ```bash
   # on the NEW server
   ssh-keygen -t ed25519 -N "" -f ~/.ssh/id_ed25519
   cat ~/.ssh/id_ed25519.pub          # append to taxnestc@…:~/.ssh/authorized_keys
   ```

3. `rsync` is already built at `~/bin/rsync` on the live host (cPanel ships
   none; `01-preflight.sh` prints the rebuild command if it ever disappears).

---

## Running it

```bash
# --- weeks before: does this plan hold up? -------------------------------
bash scripts/migration/01-preflight.sh --src-only   # before the VPS exists
bash scripts/migration/02-snapshot-source.sh        # the yardstick

# --- an independent backup, kept off both servers ------------------------
bash scripts/migration/04-sync-db.sh --dump-only

# --- days before cutover: the long copy, site stays live -----------------
bash scripts/migration/01-preflight.sh
bash scripts/migration/03-sync-files.sh             # backgrounds itself
bash scripts/migration/03-sync-files.sh --status    # watch it
bash scripts/migration/04-sync-db.sh
bash scripts/migration/05-verify.sh                 # expect a few live-drift diffs

# --- rehearse ------------------------------------------------------------
bash scripts/migration/06-cutover.sh                # prints the plan, changes nothing

# --- cutover night, 02:00-05:00 PKT --------------------------------------
bash scripts/migration/06-cutover.sh --go
```

`06-cutover.sh --go` stops at the point where DNS must change. It never
touches DNS itself — that is the human decision, and it is also the rollback
lever.

Realistic downtime: **10–15 minutes.**

---

## Files

| Script | Touches | What it does |
|---|---|---|
| `lib.sh` | — | Config, host helpers, the payload list. Sourced, not run. |
| `manifest.sh` | either host | sha256 + size of every payload file, TAB separated. Read-only. |
| `dbstat.php` | either host | Row count + `CHECKSUM TABLE` per table. Read-only. |
| `mycnf.php` | either host | Writes a `0600` MySQL defaults file from `.env`. |
| `dumpwrap.sh` | source | Consistent `mysqldump` to stdout, credentials never in `ps`. |
| `syncrun.sh` | dest | The background rsync pull, with a durable status file. |
| `01-preflight.sh` | both | Reachability, tools, PHP extensions, disk, DB flavour, pull path. Read-only. |
| `02-snapshot-source.sh` | source | Builds the yardstick snapshot. Read-only. |
| `03-sync-files.sh` | dest | Starts/watches the pull. Never deletes. `--status`, `--wait`, `--dry-run`. |
| `04-sync-db.sh` | dest | Dump streamed old→new into a freshly recreated schema. Backs up dest first. |
| `05-verify.sh` | both | The proof. Non-zero exit on any mismatch. `STRICT=1` at cutover. |
| `06-cutover.sh` | both | The window. Restores the old server on *any* exit, including Ctrl-C. |

> Run `06-cutover.sh --go` from a real terminal (or `tmux`), not from a tool
> call with a timeout. It holds the site down for 10–15 minutes and must be
> allowed to reach its own end.

Working files (manifests, diffs, dumps) land in `.local/migration/`, which is
gitignored — the repo is public.

---

## Gotchas already handled

- **`cpanel.taxnest.com.pk`, never `taxnest.com.pk`** — the main record is
  proxied by Cloudflare, so port 22 is dead on it.
- **Live `.env` values are quoted** — parsed by hand and trimmed, or the
  username comes out mangled and MySQL says "access denied".
- **Live PHP CLI has `display_errors` off** — a fatal exits 255 silently, so
  every remote PHP call passes `-d display_errors=1`.
- **cPanel ships no `rsync`** — compiled into `~/bin` with gcc.
- **MariaDB's `mysqldump` rejects `--set-gtid-purged`** — dropped if unsupported.
- **`ZipArchive` and GD are web-only under CageFS on the old host** — preflight
  checks them in the *CLI* on the new one, where this trap should not exist.
- **The old server keeps working during the copy** — so `05-verify.sh` treats
  extra destination files as a warning, and expects live drift until cutover.
- **The new server must run MariaDB, not MySQL** — proven, not assumed: a real
  dump restored into MySQL 8.0 loaded only 155 of 180 tables. The source has a
  `UNIQUE` key over a `TEXT` column (`push_subscriptions.endpoint`), which
  MariaDB implements as a long unique index (`USING HASH`); MySQL rejects it
  with error 1170 and abandons everything after it in the dump. Preflight now
  fails outright on a non-MariaDB destination.
- **The live host's `~/.my.cnf` contains `database=taxnestc_db`** — `mysqldump`
  reads that as `--databases`, rejects the value, and **stops reading option
  files at that point**, so credentials in a `--defaults-extra-file` listed
  afterwards are silently never loaded and the dump dies with "access denied".
  `dumpwrap.sh` uses `--defaults-file` so only its own file is read.
- **Filenames can contain spaces** — manifests are TAB separated and every
  comparison in `05-verify.sh` is tab-aware, or a path would be truncated to
  its first word and real differences would go unnoticed.
- **"Is an rsync running?" is not a completion test** — it is false before the
  first process spawns, false between per-directory runs, and false when the
  probe itself fails. The sync writes a status file with its exit code, and the
  cutover waits on that.

---

## After the move

Two things that were shared-hosting workarounds and should not be recreated:

1. **21 crontab lines** spawning `queue:work --max-time=55` every minute (11
   just for `bulk`). Replace with 3–4 persistent systemd units with restart.
2. **`public/r.php` OPcache-reset hack** on every deploy. A real VPS can reload
   PHP-FPM directly.

And do not forget:

- **SPF** hardcodes `ip4:66.29.138.229`. Miss it and all outgoing mail fails.
- **Mail off the new IP** — a fresh VPS IP has no sending reputation. Zoho Mail
  (5 users free) or Brevo. The old host already relayed via MailBaby.
- **Origin TLS** — use a Cloudflare Origin Certificate (free, 15 years).
  Let's Encrypt HTTP-01 fails behind the orange cloud.
- **Keep the old plan paid for a month**, through a month-end close. That is
  what makes rollback real.
