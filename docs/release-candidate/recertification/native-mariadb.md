# Native MariaDB and DI final immutable-source proof

The final native proof passed from an independent, shallow, non-shared clone
at immutable source SHA `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1`.
The clone top-level and detached HEAD were checked before its clone-local,
locked Composer vendor dry run. This report certifies only the disposable
native database/DI lane; it does not replace any owner authorization or
separate release gates.

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

## Historical review-correction result

The equivalent `full`, data-bearing `upgrade`, and `di` modes were run as
three separate fresh guarded MariaDB roots (rather than retaining one server
between probes):

```bash
RC_MARIADB_RUN_ID=native_mariadb106_301873a3_guardeddi \
bash scripts/rc-mariadb-migration-lab.sh --di
```

with the four MariaDB 10.6 executable variables shown above.

| Probe | Result |
|---|---|
| Full fresh migration and re-entry | PASS — 542 migration files, 291 tables, 542 ledger rows; second migrate reported no pending migrations |
| Schema/index/FK integrity | PASS — 111 manifest tables, 7 explicit critical indexes, 119 foreign-key orphan checks |
| Data-bearing pre-DI upgrade and re-entry | PASS — two tenants, two tenant-setting records, two fiscal references/environments, two branches, two stock rows, two serials, two numbering settings, and two ledger rows preserved |
| Expected one-way migration | PASS — fictional active `Business` subscription became `Kaarobar` while its active flag and term remained unchanged; two historic fiscal records retained references and were not inferred into new fiscal state/provenance |
| Native InnoDB lock/fiscal identity probe | PASS — independent workers decremented stock `10 → 8`; fiscal identity outcomes were one `claimed`, one `duplicate` |
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

## Final immutable-source result

The final command was run once, from the independent clone, with a fresh
`/tmp/taxnest-rc-mariadb-native_final_23bb5252_all` root and port `33116`:

```bash
env -i PATH="$PATH" HOME="$E/run-home" LANG=C LC_ALL=C TZ=UTC CI=1 \
  NO_PROXY='*' no_proxy='*' \
  RC_MARIADB_SERVER="$MARIADB_BIN/mariadbd" \
  RC_MARIADB_CLIENT="$MARIADB_BIN/mariadb" \
  RC_MARIADB_ADMIN="$MARIADB_BIN/mariadb-admin" \
  RC_MARIADB_INSTALL_DB="$MARIADB_BIN/mariadb-install-db" \
  RC_MARIADB_RUN_ID=native_final_23bb5252_all \
  RC_RECERTIFICATION_DIR="$E/lab-all" \
  bash scripts/rc-mariadb-migration-lab.sh --all
```

Exit: `0`. MariaDB was native `10.6.22-MariaDB`; server/client identification,
initialization, startup, health checks, clients, clean PHP processes, and
independent PHP workers all used the loopback-only guard. The harness rejects
ambient MySQL, non-disposable roots, ports `9000`/`33117`, and absent guard
paths. Fiscal/PRA endpoint/token values were empty; DI recorded `endpoint_calls=0`.

| Final probe | Actual result |
|---|---|
| Migration/order/re-entry | PASS — 542 files, 53 timestamp ties, 291 tables, 542 ledger rows, no pending second migration |
| Schema integrity | PASS — 111 manifest tables, 7 integrity indexes, 119 FK orphan checks |
| Data-bearing upgrade | PASS — 2 each of fictional tenants, settings, fiscal references/environments, branches, stocks, serials, numbering settings, and ledgers; `Business→Kaarobar` expected backfill; 2 uninferred historic metadata records |
| InnoDB concurrency | PASS — stock lock `10→8`; canonical fiscal identity outcomes `claimed,duplicate` |
| DI state/result races | PASS — 10 claim workers/one winner, 10 duplicate result workers/one durable result, two distinct terminal results settle `total=2` once, and result-before-settlement recovery |
| Outbox crash/recovery | PASS — two committed rows dispatched; post-enqueue/pre-mark loss retained one live lease; immediate retry did not steal it; a real delayed `SeedBulkSubmitBatchJob` database-queue row had future `available_at`; after eligibility it was deserialized and handled once, replaying one handoff; duplicate downstream delivery persisted one fiscal result |

### F01–F11 native closure mapping

| Finding | Final native evidence |
|---|---|
| F01 | DI has no configured fiscal endpoint; synthetic acceptance remains source-tested |
| F02 | Native DI acceptance/state path passed without endpoint calls |
| F03 | Upgrade retained 2 explicit fiscal environments |
| F04 | Ambiguous recovery and sealed non-replay path passed |
| F05 | No regulator endpoint was invoked (`endpoint_calls=0`) |
| F06 | Clean `env -i` plus loopback guard applied to native PHP children |
| F07 | Not a native database finding; privileged browser/owner controls remain separately gated |
| F08 | Ten synchronized canonical claim workers produced exactly one winner |
| F09 | Native DI immutable fiscal reference/line state path passed |
| F10 | Duplicate-result, terminal-settlement, outbox crash, durable delayed recovery, and downstream single-result proofs passed |
| F11 | Guarded native queue/lease proof passed; focused timeout contract remains separately recorded |

The unchanged-source supplemental native PHPUnit evidence is retained from the
independent clone preparation lane: owner deployment MariaDB schema `6 tests /
23 assertions`, and exact FBR KOT timestamp probe `1 / 4`.

## Raw non-secret evidence

Final immutable-source evidence is
`.local/recertification/native-final-23bb5252/`:

- source fetch/checkout and locked vendor check:
  `ae12636a297fda3fe442c797c9472b953c9766a16b421146278c37ef72d0d198`,
  `c9fce63367a08a0b64ab14b7b4b561882e0ecc56cb07c6f44695300407a3d163`,
  `80f405fab00170b59483e441922ddc9f7ebbd7838cb1e6e639aef9f6d021da5d`
- final `native-all.log` / `lab-all/mariadb-all.log`:
  `e473ca7aaa4f9e33517dd5a989b9270a22ae79c6d9d7b66d58cf72ce4ff48998`
- final migration list / timestamp ties:
  `86ca6097a5141f02d085c263982e3e72934ee78ed19bbba28301e81148cef2ca`,
  `fa324b92cff3aa3f1dfe946a20178507aeb576798c84a265aae80d1cea2c3a6d`
- final upgrade before / after:
  `676dc7257c3a4fbd3816b830944e9c853d8e46abac6dba329ff2237fb5471059`,
  `0e33f5801e59c6e2e910397148117959e25ad3f861efd6cbf33bd3c3c8d5b771`

Historical pre-review `--all` evidence remains in
`.local/recertification/native_mariadb106_301873a3_final/`; it must not be
used as certification for the corrected source. Review-correction evidence:

- `native_mariadb106_301873a3_guardedfull/mariadb-full.log`:
  `3bd6856c78b2cad1fb38630ce505b9bb573fbdac6b4750921bfa58ec5b875934`
- `native_mariadb106_301873a3_guardedupgrade/mariadb-upgrade.log`:
  `6f81372e549b082b39d86b9e69daab88139de2985cc6019733281d796aee6d18`
- `native_mariadb106_301873a3_guardedupgrade/upgrade-before.json`:
  `676dc7257c3a4fbd3816b830944e9c853d8e46abac6dba329ff2237fb5471059`
- `native_mariadb106_301873a3_guardedupgrade/upgrade-after.json`:
  `04ce639295a2ba05b134ff3e44a1913493f694e10ee9b4b4057c025e708d4133`
- `native_mariadb106_301873a3_guardeddi/mariadb-di.log`:
  `dcd21157f166531177d547fd1122dfb52b6d3c7314af86ccc2d15b50fd5da84d`
- `native_mariadb106_301873a3_guardeddi/di-focused-phpunit.log`:
  `4fd6433615121131e2a56c8fa1e56fb47fcc9fb121bae1042dbad554bed27dad`
- `native_mariadb106_301873a3_guardedconcurrency/mariadb-concurrency.log`:
  `07366e85717235a1d5f24d1e8e24d1a34f6a8e0838b5156927a41024c88e5eaf`