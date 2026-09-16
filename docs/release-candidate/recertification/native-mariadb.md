# Native MariaDB and DI recertification checkpoint

This is a **bounded native checkpoint**, not final GitHub certification. The
review-correction DI proof below ran against committed source
`5f887ddd671e3cad69a5b667ee429a9a9053f900`. A fresh guarded `--all` run and
the remaining release gates are still required before an owner may certify a
GitHub SHA.

## Runtime and safety boundary

- MariaDB was genuine `10.6.22-MariaDB`, resolved from the isolated
  `nixpkgs#mariadb_106` package. The initial ambient `mysqld` was MySQL 8.0.42
  and is explicitly rejected by `scripts/rc-mariadb-lab.sh`; its earlier
  diagnostic output is not certification evidence.
- The review-correction run used only a unique root
  `/tmp/taxnest-rc-mariadb-native_mariadb106_301873a3_guardeddi`, loopback port
  `33116`, its Unix socket, and unique `taxnest_rc_*` databases. Port `33117`
  remains reserved for the browser fixture.
- Each PHP command and independently forked PHP worker starts with `env -i`
  plus an explicitly built loopback-only `LD_PRELOAD` guard. MariaDB install,
  server startup, health checks, and client calls also run through clean,
  guarded environments. Fiscal/PRA URLs and tokens are empty, and the DI
  proof makes zero endpoint calls. All rows are fictional.

## Fresh reproducible command

```bash
composer install --no-interaction --prefer-dist
nix build --out-link .local/recertification/mariadb_106 nixpkgs#mariadb_106
MARIADB_BIN="$PWD/.local/recertification/mariadb_106/bin"
RC_MARIADB_SERVER="$MARIADB_BIN/mariadbd" \
RC_MARIADB_CLIENT="$MARIADB_BIN/mariadb" \
RC_MARIADB_ADMIN="$MARIADB_BIN/mariadb-admin" \
RC_MARIADB_INSTALL_DB="$MARIADB_BIN/mariadb-install-db" \
RC_MARIADB_RUN_ID="native_mariadb106_${USER}_$(date +%s)_$$" \
bash scripts/rc-mariadb-migration-lab.sh --all
```

The launcher fail-closes unless server and client both identify themselves as
MariaDB 10.6.x, uses `mariadb-install-db` (not MySQL initialization), refuses
non-`/tmp/taxnest-rc-mariadb-*` roots, rejects ports `9000` and `33117`, and
records raw non-secret output below the ignored `.local/recertification/`
directory.

## Review-correction result

Command run:

```bash
RC_MARIADB_RUN_ID=native_mariadb106_301873a3_guardeddi \
bash scripts/rc-mariadb-migration-lab.sh --di
```

with the four MariaDB 10.6 executable variables shown above.

| Probe | Result |
|---|---|
| DI migration | PASS — 542 migration files applied to the guarded native MariaDB 10.6 instance |
| Worker synchronization | PASS — the coordinator waits for every worker-ready acknowledgement before releasing each barrier |
| Native DI claim/result/outbox proof | PASS — 10 synchronized claim workers produced one winner; 10 duplicate callbacks produced one durable result; two synchronized distinct results settled a `total=2` batch; result-before-settlement redelivery recovered; concurrent outbox dispatch produced two hand-offs; a process loss after queue enqueue/before mark retained a live lease; immediate retry did not steal it; expiry replayed one additional at-least-once hand-off; duplicate downstream delivery persisted one fiscal result; endpoint calls `0` |
| Focused DI regression suite | PASS — 34 tests, 154 assertions: `InvoiceBulkSubmitTest`, `DiFiscalSubmissionStateTest`, `DiFiscalQueueTimeoutContractTest` |

## Corrections exercised

`SeedBulkSubmitBatchJob` now uses durable short outbox dispatch claims. A
dispatcher claims one row transactionally before queue hand-off, clears the
claim only when `dispatched_at` is recorded, releases it immediately on a
visible enqueue error, and schedules durable delayed recovery while
undispatched rows (including another dispatcher's live lease) remain. Queue
handoff is explicitly **at-least-once**, not exactly-once: process loss after
enqueue/before mark is expected to replay after lease expiry, and the
downstream canonical fiscal claim/result key absorbs that replay. The additive
`2026_12_04_000000_add_di_bulk_outbox_dispatch_claims.php` migration supplies
the token, timestamp, and recovery index. This closes the previously
unproven concurrent-dispatch/crash window while retaining redelivery after a
dispatcher crash.

## Raw non-secret evidence

Historical pre-review `--all` evidence remains in
`.local/recertification/native_mariadb106_301873a3_final/`; it must not be
used as certification for the corrected source. Review-correction evidence:
`.local/recertification/native_mariadb106_301873a3_guardeddi/`:

- `mariadb-di.log`:
  `dcd21157f166531177d547fd1122dfb52b6d3c7314af86ccc2d15b50fd5da84d`
- `di-focused-phpunit.log`:
  `4fd6433615121131e2a56c8fa1e56fb47fcc9fb121bae1042dbad554bed27dad`