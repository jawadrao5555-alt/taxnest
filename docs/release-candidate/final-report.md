# TaxNest full-maturity release-candidate report

**Report state:** working-draft recertification checkpoint. This consolidated
report has been updated for the current source identity and the fresh lanes
still running. Any lane explicitly marked **AWAITED — UNCERTIFIED** is not a
pass and must not be used as final release certification.

**Current verified GitHub normal head:** `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1`
(parent `903bf6d2ec215f3b983479a39c99ebe44d4e8fd4`); this ordinary-source
commit includes the native-guard two-proof fixes and browser fixtures and was
pushed by the main agent at `12:48:16` UTC. The final delivery commit will be
docs/evidence-only and the main agent will update the PR body with this exact
source SHA.
**Baseline:** `0677abbc3fec912b3011dd6449be6a17263db84d` (audited remote `main`;
not an assertion of the current production SHA)
**Draft PR:** [#82](https://github.com/jawadrao5555-alt/taxnest/pull/82), still
Draft and unmerged
**Current working checkout:** `/home/runner/workspace/.local/worktrees/taxnest-maturity-rc`
**Current branch:** `replit/taxnest-full-maturity-rc-20260916`

**Workflow authorization:** held exact workflow commit
`1e016668037fae4f80b629410007a7793c1a5e27` has parent
`5f887ddd671e3cad69a5b667ee429a9a9053f900`. The protected
`.github/workflows/build-agent.yml` and `.github/workflows/pr-checks.yml` files
are **NOT PUSHED**; the fresh owner-authorized write probe returned HTTP `404` at
`12:10:09` UTC. The sanitized record is
[`recertification/workflow-authorization.json`](recertification/workflow-authorization.json);
the exact proposals and compressed patch are retained at
`recertification/` and `proposed-workflows/`. The held workflow is not active
on GitHub.

**Fresh lanes currently awaited:** desktop/mobile browser acceptance
with 48-category contexts (the full browser process remains running without a
300-second ceiling); and the new fresh-checkout global proof. The final
immutable native source proof at
`23bb5252291f6f5afd5a7f83a70a0b002ae34fc1` is PASS below. Remaining lanes are
**AWAITED — UNCERTIFIED — NO RESULT YET**.

**Bounded completed lanes:** the proposed Node 22 agent lane (`43` tests), the
realtime lane (`8` tests), PHP manifest (`3` tests / `8` assertions), release
chain (`14` safeguards), and six zero-advisory audit records are documented in
`recertification/`. These are bounded evidence lanes, not a green global
certification of source
`23bb5252291f6f5afd5a7f83a70a0b002ae34fc1`. Electron `41.10.7` runtime
rebuild and the guarded Node 22 Local Core release-gate testcase now pass; no
native application or Windows build was attempted.

Historical observations whose raw logs were lost remain explicitly historical
and are never promoted to fresh certification. No production, regulator,
deployment, workflow-dispatch, merge, release, permission, cleanup,
secret-rotation, production-query or Live Ops action was performed by this
documentation task.

## 1. Owner Summary (Roman Urdu)

Yeh working-draft recertification abhi **READY** nahin hai. Current verified
normal head `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1` hai aur Draft PR #82
abhi merge nahin hui. Fresh full PHPUnit parent-source run `5018` tests,
`39251` assertions, `9` failures, `3` errors aur `7` skips ke saath
non-pass raha. Desktop/mobile browser plus 48-category contexts aur
fresh-checkout global proof abhi chal rahe hain; in remaining lanes ka result
**AWAITED — UNCERTIFIED** hai. Final immutable native `--all` result PASS hai.

Proposed YAML par bounded Node 22 agent `43`, realtime `8`, PHP manifest
`3/8`, release-chain `14` aur six advisory audits zero recorded hain. Yeh
evidence lane scoped hai; source
`23bb5252291f6f5afd5a7f83a70a0b002ae34fc1` ki global release certification
nahin. Electron `41.10.7` runtime rebuild aur guarded Node 22 Local Core
release-gate testcase PASS hain; native application ya Windows build nahin hui.

Workflow ke liye held exact commit `1e016668037fae4f80b629410007a7793c1a5e27`
(parent `5f887ddd`) hai, lekin dono protected workflow files GitHub par push
nahin huay; fresh `12:10:09` UTC permission probe HTTP `404` tha. Is liye
proposed CI ko active nahin kaha gaya.

Purane lost raw logs aur historical counts sirf provenance ke liye hain; unhein
fresh certification mein reuse nahin kiya gaya. Final status:
**NOT READY — CORRECTIONS STILL REQUIRED; NOT MERGED — NOT DEPLOYED.**

## 2. Baseline SHA and code/tooling checkpoint

| Item | Recorded value | Verdict |
|---|---|---|
| Owner command | `/home/runner/workspace/attached_assets/Pasted-Continue-the-existing-TaxNest-full-maturity-assignment-_1789558757709.txt` | Read in full; its no-merge/no-deploy/no-production boundary remains active. |
| Audited remote `main` baseline | `0677abbc3fec912b3011dd6449be6a17263db84d` | Audited starting point; not current-production SHA. |
| Current verified GitHub normal head | `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1` | Pushed by the main agent at `12:48:16` UTC; final docs/evidence delivery commit remains separate. |
| Parent source commit | `903bf6d2ec215f3b983479a39c99ebe44d4e8fd4` | Parent of the current ordinary source; prior GitHub run and unchanged-source supplemental probes refer to this SHA. |
| Prior recovery checkpoint | `75466819e4b5bcefff37b28fbd51d8f8d0025721` | Historical recovered code/tooling checkpoint; not current head. |
| Last pre-loss checkpoint | `93de2ccf` | Historical reference only; not the current head. |
| Held workflow commit | `1e016668037fae4f80b629410007a7793c1a5e27` (parent `5f887ddd`) | Exact proposal held locally; protected files are not pushed. |
| Current fresh verification | Parent-source full PHPUnit is recorded non-pass; browser/global lanes remain in progress for ordinary head `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1` | PHPUnit **NON-PASS**; browser/global **AWAITED — UNCERTIFIED**; native immutable `--all` is PASS. |
| Historical final suite | Exit `0`, exact counts in Section 12 | Historical observation only; lost raw logs never become fresh certification. |
| Tenant settings | No silent-default rewrite was authorized or reported | Preservation remains an acceptance condition, not a production assertion. |

## 3. Branch and Draft PR link(s)

| Delivery item | State |
|---|---|
| Isolated worktree | `/home/runner/workspace/.local/worktrees/taxnest-maturity-rc`, current verified head `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1` |
| Branch | `replit/taxnest-full-maturity-rc-20260916` |
| Draft PR | [#82](https://github.com/jawadrao5555-alt/taxnest/pull/82), remains Draft |
| Main ordinary-source push | `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1` after parent `903bf6d2ec215f3b983479a39c99ebe44d4e8fd4` | Pushed at `12:48:16` UTC; final delivery commit is docs/evidence-only and PR-body update remains pending. |
| Complete workflow proposals | Exact YAML proposals and patch are retained; proposed YAML parsing/semantic checks passed in the bounded lane. |
| Actual workflow state | Both protected files are **NOT PUSHED**; owner write probe returned HTTP `404` at `12:10:09` UTC. CI upgrade is not active. |
| Historical GitHub run on parent | Run `35095675935` on `903bf6d2ec215f3b983479a39c99ebe44d4e8fd4`; validate job `104792288620` failed at Exact-SHA Agent release chain checks; preceding six workflow-safety checks passed and following steps were skipped; not a run for current `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1` |
| PR state at fresh `12:48:16` UTC source push | PR #82 remains `Draft=true`, `merged=false`, `auto_merge=null`; ordinary head is `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1` |
| Fresh full PHPUnit parent-source evidence | `5018` tests / `39251` assertions / `9` failures / `3` errors / `7` skipped; 8 exact-filter partitions; JUnit SHA-256 `1fa5a1a3ba7e4ec1cb0675afe816545d716dc41c1973b663006ac2de61c16470` | **NON-PASS**; 9 payroll Hazri failures and 3 pending-bills tile errors are listed in `recertification/full-phpunit-903bf6d2ec215f3b983479a39c99ebe44d4e8fd.md` |
| Agent release-chain reproduction on parent | Baseline `4` failures / `10` passes / exit `1`; held local proposal `14` passes / `0` failures / exit `0` | Baseline workflow gate remains non-pass; local proposal is separate evidence |
| Fresh source `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1` global proof | In progress in a new checkout — **AWAITED — UNCERTIFIED — NO RESULT YET** |
| Bounded Node 22 / realtime / manifest / chain | `43` / `8` / `3 tests, 8 assertions` / `14` passed on proposed YAML lane; bounded evidence only, not global certification of source `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1`. |
| Merge/release/deployment | None performed; no production workflow activated. |

The current source identity, held workflow state, bounded evidence and
report-only documents are separate handoff items. No lost source, workflow,
fixture, raw log or historical test result is claimed to be fresh merely
because its path or count is listed. The optional current evidence map is
[`recertification/evidence-index.md`](recertification/evidence-index.md).

## 4. Exact files and migrations changed by phase

The companion [`files-by-phase.md`](files-by-phase.md) remains the
baseline-relative actual-path inventory for the recovered implementation. This
report additionally indexes the current recertification documents under
[`recertification/`](recertification/evidence-index.md). The inventory lists
source, docs, tests, migrations, deletions and generated CSS text by filename
only; generated binaries, secrets, temporary QA JSON, deleted sensitive
contents, raw logs and production data are excluded from delivery.

| Phase | Scope summary | Recovery status |
|---|---|---|
| A | Baseline, findings, category and requirement ledgers | Recovered docs and exact category matrix |
| B | Dependency, FBR error, PRA lock, logging and artifact controls | Recovered code/guards; npm CI lock of 173 packages verified |
| C | Hotel canonical resolver and preserved settings/roles | Source retained; current desktop/mobile browser lane **AWAITED — UNCERTIFIED** |
| D | DI state, hash, queue, batch and additive migrations | Final immutable MariaDB/DI `--all` proof **PASS** on source `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1`; production/browser acceptance remains separate |
| E | Agent manifest, heartbeat, callback/retry, relay and Android provenance | Bounded Node 22/realtime/manifest/chain lanes passed; Electron `41.10.7` Local Core release-gate PASS |
| F | MariaDB migration/schema/concurrency harness | Final immutable source `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1` native `--all` proof **PASS**; browser/global remain separate |
| G | Safe runner, network guard, CI DAG and browser harness | Bounded guards/proposed DAG passed; fresh global/browser proof **AWAITED — UNCERTIFIED** |
| H | 46 category profiles, 30 typed workflows and native UI routes | 48-category browser contexts **AWAITED — UNCERTIFIED** |
| I | Health/Hospital access, clinical, pharmacy, billing and operations guards | Fresh browser/category contexts **AWAITED — UNCERTIFIED** |
| J | Local encrypted recovery and production dry-run assets | Restore result retained; raw logs unavailable |
| K | Artifact/ref classification and repository guards | Guards recovered; current compiled guard pass recorded |

## 5. R01–R14 closure matrix

`Reduced` means historical source/focused evidence lowered risk but a mandatory
gate remains. `Blocked` means current evidence or authority is unavailable.
`Awaited — uncertified` identifies a lane that is running but has no result;
it is not a pass. The historical full suite and lost raw logs never close a
current finding.

| Finding | Immutable finding | Status | Evidence and recovery limitation |
|---|---|---|---|
| R01 | Dependency advisories | **Reduced** | Six zero-advisory bounded records are documented with lock hashes; fresh global proof for source `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1` remains **AWAITED — UNCERTIFIED**. |
| R02 | Required-CI coverage | **Blocked** | Exact proposal and held commit are documented; protected files are not pushed after the fresh HTTP `404` authorization result. |
| R03 | Backup permissions | **Reduced** | Historical dry-run and native encrypted restore pass retained; production permission/restore never authorized. |
| R04 | Hotel canonical category | **Reduced** | Historical focused runs remain historical; fresh desktop/mobile and category contexts are **AWAITED — UNCERTIFIED**. |
| R05 | Exception disclosure | **Reduced** | Historical focused FBR evidence and suite exit-0 observation; raw logs lost and current harness unrecertified. |
| R06 | PRA lock fallback | **Reduced** | Historical idempotency evidence plus separate `22/168` settings regression; no live fiscal endpoint. |
| R07 | Ambiguous ZIP selection | **Reduced** | Historical manifest/hash/version evidence; publication/build remains unexecuted. |
| R08 | Installed artifact provenance | **Reduced** | Bounded manifest/chain lane and locked Electron `41.10.7` Local Core gate passed; Windows/hosted attestation remains open. |
| R09 | Recovery evidence | **Reduced** | Retained native restore result at 10:28:27; raw operations logs lost and production scratch restore not run. |
| R10 | Stale Android/generated inputs | **Reduced** | Bounded release-chain lane passed; final global proof for source `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1` is **AWAITED — UNCERTIFIED**. |
| R11 | Waiting Live Ops | **Blocked** | No Live Ops or production authority was available or used. |
| R12 | Schedule observation | **Blocked** | No production observability observation was run. |
| R13 | Cached configuration permissions | **Reduced** | Historical fail-closed plan; production filesystem/config verification unexecuted. |
| R14 | Category-count discrepancy | **Reduced** | 46 commercial profiles plus `general` are retained; 48-category browser contexts are **AWAITED — UNCERTIFIED**. |

No R finding is Closed solely because a historical file or count exists.

## 6. F01–F11 closure matrix with tests

The F IDs and meanings remain immutable from the owner README. Historical DI
focused evidence and the bounded native checkpoint are recorded separately;
the final immutable native proof for source
`23bb5252291f6f5afd5a7f83a70a0b002ae34fc1` is **PASS**. Browser/global
acceptance remains **AWAITED — UNCERTIFIED**. No historical count is a new run.

| Finding | Immutable finding | Status | Historical evidence | Current remaining gate |
|---|---|---|---|---|
| F01 | Synthetic success | **Reduced** | Synthetic response cannot become regulator acceptance | Reconstructed harness not recertified |
| F02 | Permissive acknowledgement | **Reduced** | Acknowledgement-shape and explicit-environment tests | Browser/API role evidence unconfirmed |
| F03 | Environment labeling | **Reduced** | Canonical state/provenance source and focused tests | Browser label verification unconfirmed |
| F04 | Ambiguous transport replay | **Reduced** | Timeout/callback-loss, result-before-settlement, expired-claim and outbox lease recovery passed in final immutable native proof | Production/browser transport acceptance remains separate |
| F05 | Endpoint/confirmation discrepancy | **Reduced** | Controller and persisted-environment paths | Browser confirmation unconfirmed |
| F06 | Sensitive logs | **Reduced** | Redaction paths and static scan | Fresh lint/scan unavailable |
| F07 | Privileged fiscal identity changes | **Blocked** | Historical reason/role/lock/audit cases | Browser rerun and owner authorization |
| F08 | Mutation/submission race | **Reduced** | Final immutable source `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1` passed 10 synchronized DI claim workers, one winner, durable result recovery, terminal settlement and guarded outbox crash/recovery | Production and regulator acceptance remain separate |
| F09 | Fiscal hash coverage | **Reduced** | Immutable fiscal reference/line identity test | Final immutable native fiscal reference/line path passed; production/regulator acceptance remains separate |
| F10 | Batch accounting idempotency | **Reduced** | Final immutable source passed duplicate-result persistence, terminal settlement, full outbox post-enqueue recovery, durable delayed seed recovery and one downstream fiscal result | Production and regulator acceptance remain separate |
| F11 | Reservation/timeout consistency | **Reduced** | Final immutable source passed guarded queue lease/recovery proof; focused timeout contract remains separately recorded | Production queue operations remain unexecuted |

No FBR/PRA production submission, validation, retry or cancellation was
performed. Simulated outcomes are not regulator acceptance.

## 7. Security advisory before/after comparison

The six zero-advisory results below are documented in
`recertification/rc-20260916T120502Z-13398.md` and its JSON companion. That
bounded lane used committed source `b5bbb9a20f3a5f62bd0964f9439cd99e4945fe58`
with Node `20.20.0`; it is not silently relabeled as a global run for source
`23bb5252291f6f5afd5a7f83a70a0b002ae34fc1`. The new fresh-checkout global
proof is **AWAITED — UNCERTIFIED**.

| Surface | Historical before | Historical after | Recovery status |
|---|---:|---:|---|
| PHP Composer advisories | 25 across 4 packages | 0 | Bounded zero result; lock hash recorded; source `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1` global recheck **AWAITED — UNCERTIFIED** |
| Root npm lock | 7 vulnerabilities | 0 | Bounded zero result; lock hash recorded; source `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1` global recheck **AWAITED — UNCERTIFIED** |
| `pra-agent` npm lock | 7 vulnerabilities | 0 | Bounded zero result; lock hash recorded; Electron `41.10.7` gate passed; no native/Windows build claim |
| Realtime npm lock | Previously recorded | 0 | Bounded zero result; lock hash recorded; source `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1` global proof **AWAITED — UNCERTIFIED** |
| Mockup/video package locks | 0 | 0 | Bounded zero results; lock hashes recorded |
| Source/artifact scan | Remediation under review | Guarded after recorded fix | Fresh global proof **AWAITED — UNCERTIFIED** |
| npm CI dependency lock | — | `173` packages locked | Bounded post-recovery verification; not global certification of source `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1` |
| Compiled network guard | — | TCP + UDP + DNS non-loopback denial/control pass at `11:17:38` | Bounded post-recovery verification |

The current npm lock and compiled-guard results are verification evidence, not
proof that the global browser/native harness is recertified. The original root
is tracked-clean and its encrypted backup remains present.

## 8. Backup/config hardening deliverables and unexecuted production actions

| Deliverable | Retained result/status |
|---|---|
| Native encrypted restore | Historical pass at 10:28:27: 2 accounts, 3 invoices, FK/index/hash/`CHECK TABLE` and tamper rejection. Raw logs are lost. |
| Earlier 10:06 archive | Not the sole recovery evidence; it is not treated as the native restore result. |
| Permission hardening | Historical exact-target fail-closed script; fresh lint/execution unavailable. |
| Production backup/offsite/restore | Never executed; no production filesystem/database/backup/cache/permission access. |
| Monitoring readiness | Historical local no-network guard; production alert sources remain unverified. |
| RPO/RTO | Recommendation retained; owner tier/retention/legal hold/offsite destination not selected. |

All production backup, restore, permission, cache, secret rotation, schedule,
monitoring and recovery actions remain **PROPOSED — NOT EXECUTED — OWNER
APPROVAL REQUIRED**.

## 9. Hotel hotfix verification

| Evidence | Retained status |
|---|---|
| Canonical resolver | Historical `HotelShell` canonical profile resolver preserving saved settings and roles. |
| Focused Hotel/service initial run | Historical `136` tests / `10332` assertions. |
| Later Hotel/service run | Historical `91` tests / `1576` assertions plus header `1/6`. |
| Browser verification | Desktop/mobile acceptance and 48-category contexts are currently running from fresh fixtures; no result is available yet. |
| Current verdict | **AWAITED — UNCERTIFIED**, not Closed; historical browser observations are not fresh certification. |

No saved tenant configuration rewrite or independent second Hotel implementation
is claimed.

## 10. Agent/printing/offline/release-manifest results

| Area | Historical evidence | Recovery verdict |
|---|---|---|
| Release manifest | Product/version/asset/hash/size/source/build compatibility checks | Bounded PHP manifest `3 tests / 8 assertions` passed; global proof for source `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1` remains **AWAITED — UNCERTIFIED** |
| Heartbeat/callback/retry | Focused PHP/Node and realtime evidence | Bounded Node 22 agent `43` and realtime `8` passed; Electron `41.10.7` Local Core release-gate passed |
| Latest relay/settings regression | Clean-environment safe-run `PosDayCloseAutoFinalizeTest`: `22/168` passed at `10:58:17` | Recorded observation only; not summed into full suite |
| Electron test | Official locked Electron `41.10.7` runtime restored; direct existing-Xvfb guarded Node 22 testcase passed | PASS — offline KOT/local print, single cloud `order.held`/`order.settled`, persistence and retry path; no native application or Windows build |
| Windows build | `wine` absent | Blocked; no package publication |
| Android provenance | Historical exact-byte pass/rejection | Reduced; hosted publication unexecuted |
| Printing/KOT | Software paths only | No physical-printer certification |
| Fiscal relay | Focused HTTPS/host/redirect/private-host checks | No live fiscal endpoint |

No other agent changes are reconstructed by this scoped documentation task.

## 11. Database/migration/integrity verification

The final immutable native proof was run once from an independent shallow,
non-shared clone at exact source
`23bb5252291f6f5afd5a7f83a70a0b002ae34fc1`, using genuine MariaDB
`10.6.22-MariaDB`, clean guarded environments, loopback port `33116`, and
`scripts/rc-mariadb-migration-lab.sh --all`. It exited `0`; endpoint calls
were `0`. The machine-readable companions are
`recertification/native-mariadb-23bb5252.json` and
`recertification/native-mariadb-23bb5252.junit.xml`; the final raw-log SHA-256
is `e473ca7aaa4f9e33517dd5a989b9270a22ae79c6d9d7b66d58cf72ce4ff48998`.

| Gate | Retained result/status |
|---|---|
| Additive DI migrations | **PASS (immutable source)** — 542 migration files, 291 tables, 542 ledger rows, and clean migration re-entry |
| Owner deployment approval migration | Historical worktree review only; no production migration |
| Native encrypted restore | Historical 10:28:27 pass with 2 accounts/3 invoices and integrity/tamper checks |
| Full migration worker | **PASS (immutable source)** — 542 migrations, 53 timestamp ties, 291 tables, and no pending second migration |
| Data-bearing upgrade | **PASS (immutable source)** — two each of tenants, settings, fiscal references/environments, branches, stock rows, serials, numbering settings, and ledgers preserved; expected `Business→Kaarobar` backfill; two historic metadata records remained uninferred |
| Schema-only upgrade | Historical/bounded `482` prefix→`541` result is not data-bearing proof |
| Generic lock/fiscal claim | **PASS (immutable source)** — stock lock `10→8`; canonical fiscal identity outcomes `claimed,duplicate`; 10 synchronized claim workers produced one winner |
| DI state/result races | **PASS (immutable source)** — 10 claim workers/one winner, 10 duplicate-result workers/one durable result, two distinct terminal results settled `total=2` once, and result-before-settlement recovery |
| Outbox crash/recovery | **PASS (immutable source)** — post-enqueue/pre-mark loss retained a live lease; immediate retry did not steal it; a real delayed `SeedBulkSubmitBatchJob` row had future `available_at`, became eligible, handled once, replayed one handoff, and duplicate downstream delivery persisted one fiscal result |
| Endpoint guard | **PASS (immutable source)** — fiscal/PRA values empty and `endpoint_calls=0` |
| Supplemental native PHPUnit | Unchanged parent-source probes remain owner-schema `6/23` and FBR KOT timestamp `1/4`; these are supplemental, not the immutable lab counts |
| Category-native lab / SQL plans | Native MariaDB final lab passed; 48-category browser contexts remain **AWAITED — UNCERTIFIED** |
| DI focused shell result | Final immutable lab passed its synchronized DI/outbox proof; focused `34/154` checkpoint remains separately recorded |
| Native bulk/concurrency | **PASS (immutable source)** — full synchronized claim/result/outbox and lease/recovery proof |
| Synthetic upgrade fixture | Historically created/running; no production export is needed or authorized |
| Schema/ledger comparison | **PASS (immutable source)** — 111 manifest tables, 7 integrity indexes, 119 foreign-key orphan checks, 542 ledger rows |
| Integrity hash | **PASS (immutable source)** — final upgrade before/after hashes retained; all rows were fictional and disposable |

All production schema, data, reconciliation, migration, seeding and repair
steps remain **PROPOSED — NOT EXECUTED — OWNER APPROVAL REQUIRED**.

## 12. Full test inventory and exact pass/fail/skip counts

The following table separates the current awaited lanes from exact retained
historical observations. A lane with no result is **AWAITED — UNCERTIFIED**,
not pass, fail, or skip. Lost raw logs are never treated as fresh evidence.

| Run | Exact retained result | Interpretation |
|---|---|---|
| Fresh full PHPUnit parent-source result | `5018` tests / `39251` assertions / `9` failures / `3` errors / `7` skipped; 8 exact-filter partitions; Node `v22.15.1`, PHP `8.4.16`, Composer `2.9.2` | **NON-PASS**; aggregate JUnit SHA-256 `1fa5a1a3ba7e4ec1cb0675afe816545d716dc41c1973b663006ac2de61c16470` |
| Current desktop/mobile browser | Fresh desktop/mobile run with 48-category contexts | **AWAITED — UNCERTIFIED — NO RESULT YET** |
| Current native proof | Independent shallow clone at immutable source `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1`; `--all`, exit `0` | **PASS** — 542 migrations, 291 tables, 542 ledger rows, 111 manifest tables, 7 indexes, 119 FK orphan checks, data-preserving upgrade, synchronized DI/outbox recovery, endpoint calls `0` |
| Current global proof | Fresh-checkout global lane on ordinary head `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1` | **AWAITED — UNCERTIFIED — NO RESULT YET** |
| Full run 1 | `5003` tests / `38882` assertions; `1` artifact-guard failure, with `4995` passed and `7` skipped in its summary | Historical non-pass snapshot; fix was later recorded as `2/18`. |
| Full run 2 | `5012` tests / `39246` assertions; `1` PRA offline-fixture failure | Historical non-pass snapshot; fix was later recorded as `41/255`. |
| Historical final full suite | `5014` tests / `5007` passed / `0` failed / `0` errors / `7` native-only skips; `39265` assertions; `28` deprecations; exit `0` | **Observed historical PASS**, not current reconstructed-harness certification. |
| Historical JUnit evidence | SHA-256 `cc045d97d8501c054820fda8c7ec8f8380c34c591d57642600d7541df415a5bb`; [`evidence-observed.json`](evidence-observed.json) | Raw logs were lost during 11:04–11:06 recovery window; the committed observation record preserves provenance. |
| Separate PRA settings regression | Clean-env safe-run `PosDayCloseAutoFinalizeTest`: `22` tests / `168` assertions passed at `10:58:17` | Separate observation; do not add to `5014` totals. |
| Final immutable native proof | `542` migrations, `291` tables, `542` ledger rows, `111` manifest tables, `7` integrity indexes, `119` FK orphan checks; data-preserving `Business→Kaarobar` upgrade; full DI claim/result/outbox crash/recovery | **PASS** — source `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1`, exit `0`, raw log SHA-256 `e473ca7aaa4f9e33517dd5a989b9270a22ae79c6d9d7b66d58cf72ce4ff48998` |
| Supplemental unchanged-source native PHPUnit | Owner-schema `6/23` plus FBR timestamp `1/4` on parent source `903bf6d2ec215f3b983479a39c99ebe44d4e8fd4` | **PASS (supplemental result)**; not substituted for the final immutable lab |
| Historical DI native shell | `12 assertions / 0 failures` printed | Outer `ShellExec` timed out; exit indeterminate; superseded by final immutable proof above. |
| Historical DI bulk race | Later DI bulk-result race | Not executed in that historical shell; superseded by final immutable full outbox/DI proof above. |
| DI focused | `35` tests / `169` assertions | Historical focused evidence only. |
| Health focused | `416` tests / `2261` assertions, including HR tests | Historical result; no-hospital-pilot coverage is separate. |
| Hotel/service | `136/10332`, later `91/1576`, plus header `1/6` | Historical focused evidence; browser rerun unconfirmed. |
| Agent/realtime | Proposed-YAML Node 22 lane: `43` agent, `8` realtime; PHP manifest `3/8`, release chain `14`; Electron `41.10.7` Local Core gate passed | Bounded current lane; no native application or Windows build claim. |
| Browser | Fresh desktop/mobile run with 48-category contexts | **AWAITED — UNCERTIFIED — NO RESULT YET**; historical first pass is not reused. |

Overlapping counts are intentionally not summed. The browser/global lanes have
no result yet and are not included in any pass total; the fresh PHPUnit
non-pass is reported once from its machine-readable evidence, and the final
native lab PASS is reported once from its machine-readable evidence.
No lost raw log is claimed to be available or used as current certification.

## 13. MariaDB concurrency results

The final immutable native proof from exact source
`23bb5252291f6f5afd5a7f83a70a0b002ae34fc1` passed `--all` with exit `0`.
Genuine MariaDB `10.6.22-MariaDB` applied `542` migration files to `291`
tables with `542` ledger rows, clean re-entry, `111` manifest tables, `7`
integrity indexes and `119` foreign-key orphan checks. The data-bearing
upgrade preserved two of each documented fictional category and performed the
expected `Business→Kaarobar` backfill without inferring two historic metadata
records.

The same immutable run passed stock lock `10→8`, canonical fiscal outcomes
`claimed,duplicate`, 10 synchronized claim workers with one winner, 10
duplicate-result workers with one durable result, two terminal results settling
`total=2`, result-before-settlement recovery, full outbox post-enqueue/pre-mark
crash recovery, durable future-seeded delayed recovery with one real handoff,
and duplicate downstream delivery persisting one fiscal result. Endpoint calls
were `0`. The final raw log SHA-256 is
`e473ca7aaa4f9e33517dd5a989b9270a22ae79c6d9d7b66d58cf72ce4ff48998`.

The parent-source supplemental probes remain owner-schema `6/23`, FBR KOT
timestamp `1/4`, and documented same-file worker `25/80`; they are not used to
replace the final immutable machine-readable native result. Browser/global
acceptance remains separate and **AWAITED — UNCERTIFIED**.

The historical encrypted restore and lost raw-log observations remain
historical only. No historical shell timeout, schema-only result, or prior
bulk-race gap is relabeled as the current result for source
`23bb5252291f6f5afd5a7f83a70a0b002ae34fc1`.

Historical harness paths included:

- `scripts/rc-mariadb-migration-lab.sh`
- `scripts/tests/di-fiscal-mariadb-check.sh`
- `scripts/tests/di-fiscal-mariadb-claim-worker.php`
- `tests/native/rc_mariadb_schema.php`
- `tests/native/rc_mariadb_concurrency.php`

No production database, staging database or regulator endpoint was contacted.

## 14. Desktop/mobile browser results

The fresh desktop/mobile browser run and 48-category contexts are in progress
in the persistent main process without a `300`-second ceiling. No result,
console/page-error summary, failed-request summary or overflow summary is
available yet, so this lane is **AWAITED — UNCERTIFIED — NO RESULT YET**. No
desktop/mobile, Hotel-role, Health-browser, DI-privileged-role or category
acceptance is marked complete.

Earlier browser observations (including the `570px/390px` overflow and first
pass failures) remain historical context only. They are not reused as a fresh
pass or as a replacement for the current run.

Historical loopback/synthetic assets included:

- `scripts/rc-browser-acceptance.mjs`
- `scripts/rc-browser-fixture-seed.php`
- `scripts/rc-browser-fixture-resume.php`
- `scripts/rc-di-browser-fixture.php`
- `scripts/rc-browser-fixture.sh`

No customer identity, production session or hardware browser evidence was used.

## 15. 46-category plus `general` completion matrix

The retained category matrix contains **46 commercial rows plus one `general`
fallback**. The current run is exercising 48 category contexts across
desktop/mobile browser lanes; no result is available yet. Current native/browser
acceptance is therefore **AWAITED — UNCERTIFIED**, not pass.

| Engine | Retained evidence | Recovery verdict |
|---|---|---|
| Food POS | Profile/family/module contracts and focused category tests | Reduced; browser/KOT pending |
| Goods POS | Profile/module/URL-gate focused tests | Reduced; browser/native pending |
| Pharmacy POS | Profile/family separation and URL-gate tests | Reduced; browser/native pending |
| Hotel stay/folio | Canonical shell and focused route tests | Reduced; browser role chain unconfirmed |
| Typed service work orders | 30 typed profiles/routes/lifecycle/invoice/native-UI tests | Reduced; browser/mobile/native unconfirmed |
| `general` fallback | Generic catalogue/billing contract | Reduced; no bespoke vertical claim |

No category row is promoted to complete from historical source existence alone.
Existing tenant settings remain authoritative.

## 16. Healthcare/Hospital readiness matrix

Historical Health focused result was **416 tests / 2261 assertions**, including
HR tests. Fresh Health/category browser contexts are part of the in-progress
desktop/mobile lane and are **AWAITED — UNCERTIFIED**. No-hospital-pilot
coverage is a separate evidence area; this report does not infer an HR test gap
from that pilot boundary.

| Domain | Status after recovery | Limitation |
|---|---|---|
| Authentication/product isolation | Reduced | Historical focused guards; no fresh SSO/browser proof |
| Patient identity/confidentiality | Reduced | Historical denial tests; browser journey unconfirmed |
| OPD/clinical permissions | Reduced | Historical Health suite; browser unconfirmed |
| IPD/wards/beds/procedures | Reduced | Historical operations tests; native/browser unconfirmed |
| Pharmacy/stock/branch | Reduced | Historical cross-company/branch tests |
| Billing/refunds/day-close/panels | Reduced | Historical accounting tests; fresh chain unavailable |
| Doctor shares/accounting | Reduced | Historical settlement/journal evidence |
| HR/attendance/leave/roster | Reduced | Included in historical `416`; no-hospital-pilot remains separate |
| Audit/redaction | Reduced | Historical attachment strengthening; fresh export/redaction unavailable |
| FBR integration | Blocked | No endpoint, credential, registration or regulator acceptance |
| Backup/monitoring/device | Blocked | No production/hardware evidence |

Health/Hospital is not certified, compliant, production-ready or regulator
accepted by this report.

## 17. Repository/branch/artifact cleanup register

| Area | Conservative recovery classification |
|---|---|
| Current verified source | GitHub ordinary head `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1`; native immutable proof passed, browser/global verification remains in progress |
| Prior recovery checkpoint | Full working application code and guards at `75466819e4b5bcefff37b28fbd51d8f8d0025721`; historical only |
| Held workflow proposal | Exact commit `1e016668037fae4f80b629410007a7793c1a5e27`, parent `5f887ddd`; protected files not pushed |
| Lost uncommitted source/workflows | No claim beyond the exact held workflow artifacts and current worker lanes |
| Generated build outputs | Excluded; no rebuild/provenance claim |
| Sensitive/deleted artifacts | Not reproduced, dumped or used as fixtures |
| 82 untracked paths | Existing evidence count retained; names unavailable where not retained; no new production read |
| Raw logs | Lost during 11:04–11:06 temporary-worktree recovery window |
| Branch/ref state | Current branch and GitHub ordinary head are `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1`; PR #82 remains Draft/unmerged |

No production inventory, secret/session rotation, history purge, remote cleanup,
artifact deletion or release publication was performed.

## 18. Remaining blockers and exact reasons

### Internal verification still running

1. Fresh full PHPUnit parent-source run has a machine-readable **NON-PASS**:
   `5018` tests, `39251` assertions, `9` failures, `3` errors and `7` skips.
2. Desktop/mobile browser acceptance and 48-category contexts are still running
   in the persistent main process without a `300`-second ceiling; no result:
   **AWAITED — UNCERTIFIED**.
3. Final immutable native proof from an independent clone at source
   `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1` passed `--all` with exit `0`;
   its machine-readable JSON/JUnit and raw-log SHA are recorded above. Native
   verification is no longer a blocker.
4. New fresh-checkout global proof has no result: **AWAITED — UNCERTIFIED**.
5. Historical GitHub automatic run `35095675935` on parent source
   `903bf6d2ec215f3b983479a39c99ebe44d4e8fd4` failed validate job
   `104792288620` at **Exact-SHA Agent release chain checks**. The preceding
   six workflow-safety checks passed and following steps were skipped. It is
   not a result for the now-pushed ordinary head
   `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1`; older run `35090165512` on the
   prior `20547e395a2c407f9b1ab56bd4939b81610f11cf` head is historical only.
6. Electron `41.10.7` official runtime was restored and the direct-Xvfb guarded
   Node 22 Local Core release-gate testcase passed, including offline KOT/local
   print and single cloud `order.held`/`order.settled`. No native application
   or Windows build was attempted.
7. Historical lost raw logs and the historical `5014` observation remain
   provenance only, never fresh certification.

### External-only blockers and boundaries

8. Workflow writes remain unauthorized: held commit
   `1e016668037fae4f80b629410007a7793c1a5e27` (parent `5f887ddd`) is not
   pushed; the fresh write probe returned HTTP `404`. The exact proposal JSON,
   YAML files and compressed patch are retained for owner action.
9. Windows/Wine packaging, physical-printer/hardware evidence, production
   reconciliation, production permissions, backup/restore, migrations, fiscal
   submissions, regulator acceptance and Live Ops observations require external
   owner/environment authority and were not attempted.
10. No production, staging or regulator endpoint was contacted; no production
    action is inferred from any bounded local result.

## 19. Handoff exclusions and status

No owner request or task is issued by this report. The handoff records facts
only: current verified normal head
`23bb5252291f6f5afd5a7f83a70a0b002ae34fc1`; Draft PR #82 remains open and
unmerged; parent-source full PHPUnit is **NON-PASS**, while current browser and
global lanes are **AWAITED — UNCERTIFIED** (final immutable native proof PASS);
the exact held workflow commit and authorization JSON are
separate evidence; protected workflow files are not pushed; and no production
action is authorized. The main agent must perform the final documentation push
and reverify GitHub state afterward; this working draft does not perform that
push.

## 20. Deployment prerequisites and rollback plan

### Prerequisites

- Current verified normal head is
  `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1`; parent-source full PHPUnit is
  **NON-PASS**, browser and global results remain **AWAITED — UNCERTIFIED**,
  and final immutable native `--all` is PASS with exit `0`.
- Historical full-suite and bounded native results remain evidence with their
  stated scope; lost raw logs and prior schema-only output are not fresh proof.
- Fresh browser evidence is running, not blocked or passed; its result is
  still **AWAITED — UNCERTIFIED**.
- Proposed workflow definitions and bounded lanes passed locally. Historical
  GitHub run `35095675935` on parent source failed the Exact-SHA Agent release
  chain check; the protected workflow files remain unpushed after HTTP `404`.
- Electron `41.10.7` guarded Local Core release-gate passed; no native
  application or Windows build was attempted. Windows packaging and physical
  hardware evidence remain external-only blockers.
- Production approvals, tenant-preservation review, backup/recovery ownership
  and rollback rehearsal are not evidenced.

### Migration/rollback posture

The DI changes were documented as additive migrations and do not authorize
silent tenant-setting rewrites. No production migrate, rollback, schema repair,
queue replay or fiscal reset was run. Rollback is not inferred from historical
source presence or the retained suite result.

All deployment, migration, rollback, cache, queue, secret, backup and service
actions are **PROPOSED — NOT EXECUTED — OWNER APPROVAL REQUIRED**.

## 21. Explicit confirmation that nothing was merged or deployed

Nothing was merged, approved, deployed, rolled back, published as a release,
dispatched as a production workflow, submitted to FBR/PRA production, or
executed against a production database/service from this documentation scope.
Main pushed ordinary source
`23bb5252291f6f5afd5a7f83a70a0b002ae34fc1` at `12:48:16` UTC; PR #82 remains
open and Draft, `merged=false`, `auto_merge=null`. The final delivery commit is
docs/evidence-only and the main agent will update the PR body with the exact
source SHA. The held workflow files remain **NOT PUSHED** after HTTP `404`;
workflow activation was **NOT DELIVERED**.

## 22. Final verdict

**NOT READY — CORRECTIONS STILL REQUIRED**

Parent-source full PHPUnit is **NON-PASS** (`9` failures, `3` errors);
desktop/mobile/browser-category and fresh-checkout global results remain
**AWAITED — UNCERTIFIED**. The final immutable native `--all` proof and
Electron Local Core gate are PASS within their documented boundaries. The
prior-parent GitHub run `35095675935` failed the Exact-SHA Agent release chain
check, and the two protected workflow files remain unpushed because the write
probe returned HTTP `404`. Therefore this Draft PR is
**NOT MERGED — NOT DEPLOYED**.