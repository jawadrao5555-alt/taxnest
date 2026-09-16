# Native MariaDB and DI recertification checkpoint

This is a **bounded native checkpoint**, not final GitHub certification. It
was run on committed base `b5bbb9a20f3a5f62bd0964f9439cd99e4945fe58` plus the
uncommitted RC correction set. Re-run it after the final code checkpoint on
the authoritative GitHub SHA.

## Runtime and safety boundary

- MariaDB was genuine `10.6.22-MariaDB`, resolved from the isolated
  `nixpkgs#mariadb_106` package. The initial ambient `mysqld` was MySQL 8.0.42
  and is explicitly rejected by `scripts/rc-mariadb-lab.sh`; its earlier
  diagnostic output is not certification evidence.
- The final run used only a unique root
  `/tmp/taxnest-rc-mariadb-native_mariadb106_301873a3_final`, loopback port
  `33116`, its Unix socket, and unique `taxnest_rc_*` databases. Port `33117`
  remains reserved for the browser fixture.
- Each PHP command starts with `env -i`; fiscal/PRA URLs and tokens are empty,
  and the DI proof makes zero endpoint calls. All rows are fictional.

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

## Fresh result

Command run:

```bash
RC_MARIADB_RUN_ID=native_mariadb106_301873a3_final \
bash scripts/rc-mariadb-migration-lab.sh --all
```

with the four MariaDB 10.6 executable variables shown above.

| Probe | Result |
|---|---|
| Full fresh migration and re-entry | PASS — 542 migration files, 291 tables, 542 ledger rows; second migrate reported no pending migrations |
| Schema/index/FK integrity | PASS — 111 manifest tables, 7 explicit critical indexes, 119 foreign-key orphan checks |
| Data-bearing pre-DI upgrade and re-entry | PASS — two tenants, two tenant-setting records, two fiscal references/environments, two branches, two stock rows, two serials, two numbering settings, two ledger rows preserved |
| Expected one-way migration | PASS — fictional active `Business` subscription became `Kaarobar` while its active flag and term remained unchanged; two historic fiscal records retained their references and were not inferred into a new fiscal state/provenance |
| Native InnoDB lock probe | PASS — independent workers decremented stock `10 → 8`; fiscal identity outcomes were one `claimed`, one `duplicate` |
| Native DI claim/result/outbox proof | PASS — 10 synchronized claim workers produced one winner; 10 duplicate callbacks produced one durable result; two synchronized distinct results settled a `total=2` batch; a result-before-settlement redelivery recovered; concurrent outbox dispatch handed off two rows once; an expired crash-window claim recovered one row once; endpoint calls `0` |
| Focused DI regression suite | PASS — 32 tests, 145 assertions: `InvoiceBulkSubmitTest`, `DiFiscalSubmissionStateTest`, `DiFiscalQueueTimeoutContractTest` |

## Corrections exercised

`SeedBulkSubmitBatchJob` now uses durable short outbox dispatch claims. A
dispatcher claims rows transactionally before queue hand-off, clears the claim
only when `dispatched_at` is recorded, and permits recovery only after the
claim lease expires. The additive
`2026_12_04_000000_add_di_bulk_outbox_dispatch_claims.php` migration supplies
the token, timestamp, and recovery index. This closes the previously
unproven concurrent-dispatch/crash window while retaining redelivery after a
dispatcher crash.

## Raw non-secret evidence

`.local/recertification/native_mariadb106_301873a3_final/`:

- `mariadb-all.log`:
  `c216aecb8883de669a33396304fbbf27a6ee4483713edca4265a556ae98969f6`
- `migration-files.txt`:
  `86ca6097a5141f02d085c263982e3e72934ee78ed19bbba28301e81148cef2ca`
- `migration-timestamp-ties.txt`:
  `fa324b92cff3aa3f1dfe946a20178507aeb576798c84a265aae80d1cea2c3a6d`
- `upgrade-before.json`:
  `676dc7257c3a4fbd3816b830944e9c853d8e46abac6dba329ff2237fb5471059`
- `upgrade-after.json`:
  `e26816daad30019c461037976b4050d2bdda2b36e06c8beafe7eff5692b2f2bf`
- `di-focused-phpunit.log`:
  `19991848b3c53317e0e8265d455a8bf92d20b9eef2614c9aa79639c14c526d5d`