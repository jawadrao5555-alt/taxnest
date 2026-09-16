# TaxNest full-maturity release-candidate traceability

**Recovery state:** reconstructed from the retained release-candidate README,
phase ledgers and committed source after temporary worktree loss. It is an
evidence index, not proof that lost raw logs or uncommitted files remain.

## Authority and delivery boundary

| Item | Retained value |
|---|---|
| Audited remote `main` baseline | `0677abbc3fec912b3011dd6449be6a17263db84d` |
| Restored pushed head | `93de2ccf` |
| Worktree | `/tmp/taxnest-maturity-rc` |
| Branch | `replit/taxnest-full-maturity-rc-20260916` |
| Draft PR | [#82](https://github.com/jawadrao5555-alt/taxnest/pull/82), remains Draft |
| Recovery loss | Temporary worktree and raw logs lost 11:04–11:06 UTC; committed code restored |
| Production boundary | No production query, fiscal submission, workflow dispatch, merge, release or deployment |

The baseline is an audited remote-main reference, not a claim about the current
production SHA. The reconstructed harness is not recertified from historical
counts alone.

## Requirement-to-evidence map

| Requirement area | Source / implementation | Historical evidence | Recovery verdict |
|---|---|---|---|
| DI acknowledgement, provenance and timeout | `app/Services/DiFiscalSubmissionState.php`; DI controllers/jobs; additive migrations | DI focused `35/169`; historical final suite exit `0` | Reduced; DI 12-assertion shell timeout indeterminate |
| PRA lock, claim and day-close | PRA integration/service/controller paths | Separate `22/168` settings regression at 10:58:17; generic lock/fiscal claim native exit `0` | Reduced; later DI bulk race not executed |
| FBR errors and redaction | `FbrPosController`; `FbrService`; FBR feature tests | Historical focused FBR evidence and suite observation | Reduced; raw logs lost |
| Dependency and artifact safety | lockfiles; repository guards; artifact tests | Historical Composer/root npm/PRA audits `0`; artifact fix recorded | Reduced; current guard not recertified |
| Hotel canonical category | `HotelShell`; layout/banner; Hotel tests | Historical Hotel/service runs; browser first pass `9` failures | Reduced; corrected browser rerun unconfirmed |
| Category engines | `PosCategoryProfiles`; service workflow profiles | Exact 46 commercial + `general` matrix; category-native lab exit `0` | Reduced; browser/native acceptance pending |
| Service workflows | service profile/controller/service/views | Historical typed workflow evidence | Reduced; current reconstructed harness unrecertified |
| Agent/offline/printing | agent manifest/controller and `pra-agent` sources | Phase-E evidence; separate `22/168` regression | Reduced; Electron `libnspr4` and Windows `wine` blockers |
| MariaDB schema and migration | DI migrations; native schema/concurrency harnesses | Full 541 migration exit `0`; EXPLAIN validation exit `0`; generic lock/fiscal claim exit `0` with stock 10→8 and claimed/duplicate paths | Reduced; data-bearing upgrade and race gaps remain |
| Data-bearing upgrade | upgrade fixture and schema comparison harness | Schema-only 482-prefix→541 exit `0` | Blocked; not data-bearing upgrade proof |
| Native skipped cases/category lab | native case matrix and category-native lab | Seven previously skipped cases `6/23 + 1/4` exit `0`; category-native lab exit `0` | Reduced; raw logs gone |
| DI shell and bulk race | DI assertion runner and bulk-result race harness | `12 assertions / 0 failures` printed, outer `ShellExec` timed out; later bulk race not executed | Blocked; exit indeterminate and race gap remains |
| Health/Hospital | Health controllers/middleware/tests | Historical `416/2261`, including HR tests | Reduced; no-hospital-pilot coverage separate |
| Backup/recovery | operations scripts and artifacts | Native encrypted restore 10:28:27; 2 accounts/3 invoices/integrity/tamper rejection | Reduced; raw logs lost; production restore not run |
| CI/browser/workflow | safe-run/network/browser helpers and workflow proposals | Historical full suite exit `0`; first browser pass `9` failures | Blocked; current harness and corrected rerun unconfirmed |
| Deployment/release | manifests, artifact inventory, rollback/runbooks | Draft PR only; no activation/merge/release | Blocked pending approvals and fresh evidence |

## Immutable R findings

| ID | Finding |
|---|---|
| R01 | Dependency advisories |
| R02 | Required-CI coverage |
| R03 | Backup permissions |
| R04 | Hotel canonical category |
| R05 | Exception disclosure |
| R06 | PRA lock fallback |
| R07 | Ambiguous ZIP selection |
| R08 | Installed artifact provenance |
| R09 | Recovery evidence |
| R10 | Stale Android/generated inputs |
| R11 | Waiting Live Ops |
| R12 | Schedule observation |
| R13 | Cached configuration permissions |
| R14 | Category-count discrepancy |

## Immutable F findings

| ID | Finding |
|---|---|
| F01 | Synthetic success |
| F02 | Permissive acknowledgement |
| F03 | Environment labeling |
| F04 | Ambiguous transport replay |
| F05 | Endpoint/confirmation discrepancy |
| F06 | Sensitive logs |
| F07 | Privileged fiscal identity changes |
| F08 | Mutation/submission race |
| F09 | Fiscal hash coverage |
| F10 | Batch accounting idempotency |
| F11 | Reservation/timeout consistency |

## Category and scope contract

[`category-matrix.md`](category-matrix.md) contains the exact 46 commercial
profiles plus the `general` fallback reconstructed from
`PosCategoryProfiles::PROFILES`. `general` is not a marketed vertical.
Shared service workflows provide configurable operational tracking and do not
claim regulated clinical, legal, financial, educational or licensing outcomes.
No existing tenant settings are silently rewritten.

## Historical verification record

The retained historical final suite result was:

- `5014` tests, `5007` passed, `0` failed, `0` errors
- `7` native-only skips, `39265` assertions, `28` deprecations
- exit `0`
- JUnit SHA-256
  `cc045d97d8501c054820fda8c7ec8f8380c34c591d57642600d7541df415a5bb`
- summary path `evidence/release-suite-summary.json`

This is an observed historical result. Raw logs are gone and the recreated
harness is not recertified. The separate `PosDayCloseAutoFinalizeTest`
regression was `22` tests / `168` assertions pass at `10:58:17`; it is not
added to the full-suite total. The native result is also historical: full 541
migrations, schema-only 482-prefix→541, generic claim/lock, seven skipped cases,
category-native lab and SQL `EXPLAIN` checks exited `0`; schema-only is not
data-bearing proof, DI shell exit is indeterminate, and the later DI bulk race
was not executed. The final verdict remains **NOT READY**.