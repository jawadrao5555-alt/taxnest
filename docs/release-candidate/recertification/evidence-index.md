# Recertification evidence index (working draft)

This index belongs to the consolidated release-candidate report. It maps the
durable recertification documents to their evidence boundary; it is not a
replacement for a test result. The current source identity is
`23bb5252291f6f5afd5a7f83a70a0b002ae34fc1`. Any lane marked **AWAITED —
UNCERTIFIED** has no result yet and must not be reported as a pass.

## Current release state

| Item | State |
|---|---|
| Draft PR | [#82](https://github.com/jawadrao5555-alt/taxnest/pull/82) remains open, `Draft=true`, `merged=false`, `auto_merge=null` |
| Current GitHub normal head | `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1` |
| Parent source commit | `903bf6d2ec215f3b983479a39c99ebe44d4e8fd4`; unchanged-source supplemental probes and prior GitHub run refer to this parent |
| Main ordinary-source push | `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1`, pushed at `12:48:16` UTC; final delivery commit is docs/evidence-only and PR-body update remains pending |
| Historical GitHub run on parent | `35095675935` on `903bf6d2ec215f3b983479a39c99ebe44d4e8fd4`; validate job `104792288620` failed at Exact-SHA Agent release chain checks; preceding six workflow-safety checks passed and following steps were skipped |
| Older run | `35090165512` on prior `20547e395a2c407f9b1ab56bd4939b81610f11cf` head; historical only |
| Workflow authorization | Writes still HTTP `404`; held workflow is not active or pushed |
| Current verdict | **NOT READY — CORRECTIONS STILL REQUIRED; NOT MERGED — NOT DEPLOYED** |

## Durable documents

| Document | Scope and classification |
|---|---|
| `rc-20260916T120502Z-13398.md` | Bounded dependency/build/guard lane from source `b5bbb9a20f3a5f62bd0964f9439cd99e4945fe58`, Node `20.20.0`; six advisory audits zero, web build and bounded guards pass; not source `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1` global certification |
| `rc-20260916T120502Z-13398.json` | Machine summary for the bounded lane: `tests=12`, `failures=0`, `errors=0`, `skipped=1`; lock hashes and per-check log hashes are recorded; not a current full-suite result |
| `rc-20260916T120502Z-13398.junit.xml` | JUnit companion for the bounded dependency/build/guard lane |
| `agent-node22-provenance.md` | Proposed-YAML bounded Node 22 lane: agent `43`, realtime `8`, PHP manifest `3 tests / 8 assertions`, release chain `14`; locked Electron `41.10.7` direct-Xvfb Local Core gate PASS; no native application or Windows build |
| `native-mariadb.md` | Final immutable native proof from source `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1`: MariaDB `10.6.22`, `542` migrations, `291` tables, `542` ledger rows, `111` manifest tables, `7` indexes, `119` FK orphan checks, data-preserving upgrade, full DI/outbox recovery, endpoint calls `0`, exit `0` |
| `native-mariadb-23bb5252.json` | Machine-readable final native proof: source `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1`, `--all`, exit `0`, raw log SHA-256 `e473ca7aaa4f9e33517dd5a989b9270a22ae79c6d9d7b66d58cf72ce4ff48998` |
| `native-mariadb-23bb5252.junit.xml` | JUnit companion: `4` testcases, `0` failures, `0` errors, `0` skipped; MariaDB `10.6.22`, endpoint calls `0` |
| `final-report.md` current native addendum | Final immutable source `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1`: `542/291/542`, `111/7/119`, data-preserving `Business→Kaarobar`, full DI/outbox crash/recovery, endpoint calls `0`; supplemental parent probes are `6/23`, `1/4`, and `25/80` |
| `full-phpunit-903bf6d2ec215f3b983479a39c99ebe44d4e8fd.md` | Fresh independent-clone parent-source partitioned run: `5018` tests, `39251` assertions, `9` failures, `3` errors, `7` skipped; **NON-PASS** |
| `full-phpunit-903bf6d2ec215f3b983479a39c99ebe44d4e8fd.json` / `.junit.xml` | Machine-readable full PHPUnit companions; aggregate JUnit SHA-256 `1fa5a1a3ba7e4ec1cb0675afe816545d716dc41c1973b663006ac2de61c16470` |
| `agent-release-chain-903bf6d2ec215f3b983479a39c99ebe44d4e8fd.md` / `.json` | Parent baseline workflow gate: `4` failures / `10` passes / exit `1`; separate held local proposal: `14` passes / `0` failures / exit `0` |
| `workflow-authorization.json` | Fresh `12:10:09` UTC owner-write observation: HTTP `404`, no PAT/SSH, no branch change, no dispatch; held commit and exact expected workflow blobs |
| `../proposed-workflows/build-agent.yml` | Exact proposed build workflow; held, not active on GitHub |
| `../proposed-workflows/pr-checks.yml` | Exact proposed PR workflow; held, not active on GitHub |
| `../proposed-workflows/workflow-authorization.patch.gz` | Exact compressed workflow patch; SHA-256 values are recorded in `workflow-authorization.json` |

### Bounded zero-advisory lock hashes

These hashes are copied from `rc-20260916T120502Z-13398.json` and identify the
lockfiles used by that bounded lane. They do not establish a fresh global audit
of source `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1`.

| Lockfile | SHA-256 |
|---|---|
| `composer.lock` | `f77aae4ea1f5d8b5427132fb76e2ca017b7917ebdece26036715055a5fc141e4` |
| `package-lock.json` | `ee6faa95cd57839884299d07e871ae9593029be42bd363f523d1b13caac8ff10` |
| `pra-agent/package-lock.json` | `288f23ac9c63ec72a2318895ebb582f0cb85b31f07aeefcffc60800d22a74472` |
| `agent-realtime-gateway/package-lock.json` | `3c96680d9ce8a9d0c6085fafc250a3b3de4a8df3f099d40b441d4e76f54b461a` |
| `artifacts/mockup-sandbox/package-lock.json` | `aecf45ae4de09b7b7bdbe65fe0c21e88504a1ae01704ea6fdb42c2fd9cff861a` |
| `tools/video-pipeline/package-lock.json` | `1ea36c806550b001e4b9cf14d35160432094bf0a2e6facd73f8c93a087f7acf8` |

## Awaited fresh lanes

The following browser/global work remains in progress on current source
`23bb5252291f6f5afd5a7f83a70a0b002ae34fc1`. No final browser/global result
artifact is available yet:

- Full desktop/mobile browser acceptance and 48-category contexts remain in a
  persistent main process without a `300`-second ceiling:
  **AWAITED — UNCERTIFIED**.
- New fresh-checkout global proof: **AWAITED — UNCERTIFIED**.

The consolidated report must be updated again only after the remaining lanes
produce machine-readable results. Their in-progress counts are not pass counts;
the final native result above is already machine-readable and is recorded as
PASS.

## Historical evidence exclusion

`../evidence-observed.json` records observations whose temporary worktree and
raw logs were lost. Its historical `5014` exit-0 suite, historical browser
results, and old native observations remain provenance only. They are not
reused as fresh certification for source
`23bb5252291f6f5afd5a7f83a70a0b002ae34fc1`.

No file in this index authorizes a workflow push, merge, deployment, release,
production query, fiscal submission, regulator operation, or hardware claim.