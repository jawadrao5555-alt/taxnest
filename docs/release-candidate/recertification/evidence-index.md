# Recertification evidence index (working draft)

This index belongs to the consolidated release-candidate report. It maps the
durable recertification documents to their evidence boundary; it is not a
replacement for a test result. The frozen tested source identity is
`982826bbcc0e97ea5a96c308c76224082ff035c4`. Browser acceptance is a
scope-aware union, not one all-green invocation. No current lane is
**AWAITED — UNCERTIFIED**; that label remains non-pass if encountered in
historical provenance.

## Current release state

| Item | State |
|---|---|
| Draft PR | [#82](https://github.com/jawadrao5555-alt/taxnest/pull/82) remains open, `Draft=true`, `merged=false`, `auto_merge=null` |
| Current GitHub ordinary head | `982826bbcc0e97ea5a96c308c76224082ff035c4`; snapshot observed at `13:57:59.780Z` |
| Current code-fix parent | `2b500f6a67320a1138cc555df342b5f29bdb2377`; DI header/main flex-shrink correction without overflow masking, `PosController` minimal-schema guard, feature-cache-flush fix for `12` cases, focused `12/39` plus `17/55` |
| Prior ordinary source | `1510be744a335155331e4dc13ca1ae67e4a1d3c4`; browser/Health scopes in final union |
| Prior immutable native source | `23bb5252291f6f5afd5a7f83a70a0b002ae34fc1`; final immutable native proof |
| Main ordinary-source push | `982826bbcc0e97ea5a96c308c76224082ff035c4`; final docs/evidence carrier is separate and cannot embed its own self-SHA |
| Current GitHub run | `35104942875` / job `104823597571` on source `982826bbcc0e97ea5a96c308c76224082ff035c4`; completed failure only at old Exact-SHA Agent release chain checks |
| Older run | `35090165512` on prior `20547e395a2c407f9b1ab56bd4939b81610f11cf` head; historical only |
| Workflow authorization | Sole current blocker: owner write probe HTTP `404`; old active workflow evidence is `4` failures / `10` passes / exit `1`, while held proposal is `14` passes / `0` failures / exit `0`; protected files remain unpushed |
| Current verdict | **FINAL EVIDENCE COMPLETE — WORKFLOW AUTHORIZATION BLOCKED; NOT MERGED — NOT DEPLOYED** |

## Durable documents

| `full-phpunit-2b500f6a67320a1138cc555df342b5f29bdb2377.md` / `.json` / `.junit.xml` | Fresh committed-runner parent-source PASS: `5018` tests, `39281` assertions, `0` failures, `0` errors, `7` skipped; exact IDs `5018/5018`, missing `0`, extra `0`, duplicates `0`; JSON SHA-256 `0671595dba08aed54e5e381fa915025c978accf766069f49ee984c1bbe6a9153`; JUnit SHA-256 `dd2e9e216b80fe45ce01d8dd2b10400818c02297129e35d58154e284c9b5f197` |
| `full-phpunit-2b500f6a67320a1138cc555df342b5f29bdb2377-verification.md` | Sanitized coverage, lock hashes, fresh parent-source zero-advisory audits, Vite build, artifact guard, and compiled network-guard evidence |
| `global-checks-2b500f6a67320a1138cc555df342b5f29bdb2377.json` | Fresh source-parent machine record for all 12 required dependency/build/guard lanes, with real commands, exit/status, and evidence SHA-256 values; JSON SHA-256 `de55ebaccc356ac3dca39321f0e238ae073b88c6cf8b1f797ccdbc72f70d7f74`; no historical substitution |
| `view-proof-58d0c164.md` / `.json` | Focused changed-view follow-up: exact source `58d0c164` has `3` existing PHPUnit methods, `7` assertions, `0` failures/errors, Blade cache compile exit `0`, and meaningful artifact guard exit `0`; final source `982826bb` adds only `7` existing `dark:text-white` classes plus `1` specific harness selector; supplements rather than replaces parent `5018`-test PASS |
| `view-proof-982826bb.md` / `.json` | Frozen final-source `982826bb` proof: same `3` direct view methods, `7` assertions, `0` failures/errors, and cheap Blade compile exit `0`; no full-suite/build/audit repeat |
| `browser-acceptance-union-982826bb.md` / `.json` / `.junit.xml` | Scope-aware browser union: `61` named journeys, `122` contexts, `48` category profiles, `128` route/viewport checks, `0` missing, `252` accepted outcomes, `250` unique messages; JUnit `5` tests / `252` assertions / `0` failures / `0` errors / `0` skips; not one all-green invocation. SHA-256 JSON `2c9821a9d222486f5f45ec6ea3756404ea9b091dd13f8d3a182882ad1fb367de`, MD `3140bf77804f48d2803adb11ab6b06fe70b6eb5f7d2b2df585ab44a0c596d598`, JUnit `eda183ecdae9f0e751368daaae2952c187260c6baf22d80fa1a2fceea0e84b30` |
| `github-source-982826bb.json` | GitHub snapshot at `13:57:59.780Z`: PR #82 open Draft/unmerged, `auto_merge=null`; run `35104942875`, job `104823597571`, failure only at old Exact-SHA Agent release chain checks; SHA-256 `d9fdd59b239606fac1cea467e5b5f306d5577b7b2c69c80274b0d95b71faafe1` |
| `view-proof-982826bb.json` / `.md` | Final-source DI focused proof: `3` tests / `7` assertions / `0` failures/errors; Blade compile exit `0`; JSON SHA-256 `dbe30c41584ae5515002ae8b9c99aae333bd76fd8bb5a7537dd999af0f9806b3`, MD `208ea739029e2c2d1092412348165d1f62c49a40b14a7ac22b21f29ff452ab87` |

| Document | Scope and classification |
|---|---|
| `rc-20260916T120502Z-13398.md` | Bounded dependency/build/guard lane from source `b5bbb9a20f3a5f62bd0964f9439cd99e4945fe58`, Node `20.20.0`; six advisory audits zero, web build and bounded guards pass; not source `1510be744a335155331e4dc13ca1ae67e4a1d3c4` global certification |
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
| `final-report.md` current code/browser addendum | Frozen head `982826bbcc0e97ea5a96c308c76224082ff035c4`; code-fix parent `2b500f6a67320a1138cc555df342b5f29bdb2377`; committed parent-source full runner and 12-lane global checks are PASS; prior raw full observation with `36` assertion failures remains **NON-PASS**; final browser union PASS within mixed exact-source scopes |
| `final-report.md` original-root boundary | Tracked-clean original root HEAD `1dcf0f1d9b0af5aae9e3be4674d3e7f94785ce4d`; only original untracked backup observed (`13.5GB`, September 12 mtime); no baseline hash, so independent backup-integrity verification is not claimed |
| `final-report.md` native vendor boundary | Composer modified only the primary release-candidate vendor tree; original vendor Aug 8 timestamps are unchanged and remain a separate inode |
| `final-report.md` original-root UI safety boundary | UI helper briefly edited two original-root view files; minimal delta transferred to RC and original root restored to exact `git diff 0`; no original DB, backup, service or secret changed |
| `workflow-authorization.json` | Fresh `12:10:09` UTC owner-write observation: HTTP `404`, no PAT/SSH, no branch change, no dispatch; held commit and exact expected workflow blobs |
| `../proposed-workflows/build-agent.yml` | Exact proposed build workflow; held, not active on GitHub |
| `../proposed-workflows/pr-checks.yml` | Exact proposed PR workflow; held, not active on GitHub |
| `../proposed-workflows/workflow-authorization.patch.gz` | Exact compressed workflow patch; SHA-256 values are recorded in `workflow-authorization.json` |

### Bounded zero-advisory lock hashes

These hashes are copied from `rc-20260916T120502Z-13398.json` and identify the
lockfiles used by that bounded lane. They do not replace the fresh 12-lane
global audit record on exact source `2b500f6a67320a1138cc555df342b5f29bdb2377`.

| Lockfile | SHA-256 |
|---|---|
| `composer.lock` | `f77aae4ea1f5d8b5427132fb76e2ca017b7917ebdece26036715055a5fc141e4` |
| `package-lock.json` | `ee6faa95cd57839884299d07e871ae9593029be42bd363f523d1b13caac8ff10` |
| `pra-agent/package-lock.json` | `288f23ac9c63ec72a2318895ebb582f0cb85b31f07aeefcffc60800d22a74472` |
| `agent-realtime-gateway/package-lock.json` | `3c96680d9ce8a9d0c6085fafc250a3b3de4a8df3f099d40b441d4e76f54b461a` |
| `artifacts/mockup-sandbox/package-lock.json` | `aecf45ae4de09b7b7bdbe65fe0c21e88504a1ae01704ea6fdb42c2fd9cff861a` |
| `tools/video-pipeline/package-lock.json` | `1ea36c806550b001e4b9cf14d35160432094bf0a2e6facd73f8c93a087f7acf8` |

## Completed fresh lanes

The final browser work is complete as a scope-aware union keyed by frozen
source `982826bbcc0e97ea5a96c308c76224082ff035c4`. The parent-source full
runner and all 12 global checks are complete and machine-readable:

- Full PHPUnit runner on exact code-fix parent: **PASS**; `5018` exact IDs
  covered with `39281` assertions, `0` failures, `0` errors, `7` skips.
- Full desktop/mobile browser acceptance and 48-category contexts: `61` named
  journeys, `122` contexts, `48` category profiles, `128` route/viewport checks,
  `0` missing, `252` accepted outcomes, and `250` unique messages; JUnit `5`
  tests / `252` assertions / `0` failures / `0` errors / `0` skips. **PASS
  within scope-aware union; not one all-green invocation.**
- Parent-source global checks: **PASS**, with no remaining Node-engine skip.

The browser union excludes an invalid fixture whose
`EloquentCollection::merge` rekeyed email maps by numeric model ID, causing all
logins to fail, plus superseded server-unavailable/pre-browser resume runs.
These exclusions are machine-readable in the companion JSON.

## Historical evidence exclusion

`../evidence-observed.json` records observations whose temporary worktree and
raw logs were lost. Its historical `5014` exit-0 suite, historical browser
results, and old native observations remain provenance only. They are not
reused as fresh certification for source
`982826bbcc0e97ea5a96c308c76224082ff035c4`.

No file in this index authorizes a workflow push, merge, deployment, release,
production query, fiscal submission, regulator operation, or hardware claim.