# Recertification evidence index (working draft)

This index belongs to the consolidated release-candidate report. It maps the
durable recertification documents to their evidence boundary; it is not a
replacement for a test result. The current source identity is
`903bf6d2ec215f3b983479a39c99ebe44d4e8fd`. Any lane marked **AWAITED —
UNCERTIFIED** has no result yet and must not be reported as a pass.

## Current release state

| Item | State |
|---|---|
| Draft PR | [#82](https://github.com/jawadrao5555-alt/taxnest/pull/82) remains open, `Draft=true`, `merged=false`, `auto_merge=null` |
| Current GitHub normal head | `903bf6d2ec215f3b983479a39c99ebe44d4e8fd` |
| Fresh GitHub run | `35095675935` on `903...`; validate job `104792288620` failed at Exact-SHA Agent release chain checks; preceding six workflow-safety checks passed and following steps were skipped |
| Older run | `35090165512` on prior `205...` head; historical only |
| Workflow authorization | Writes still HTTP `404`; held workflow is not active or pushed |
| Current verdict | **NOT READY — CORRECTIONS STILL REQUIRED; NOT MERGED — NOT DEPLOYED** |

## Durable documents

| Document | Scope and classification |
|---|---|
| `rc-20260916T120502Z-13398.md` | Bounded dependency/build/guard lane from source `b5bbb9a...`, Node `20.20.0`; six advisory audits zero, web build and bounded guards pass; not source-`903` global certification |
| `rc-20260916T120502Z-13398.json` | Machine summary for the bounded lane: `tests=12`, `failures=0`, `errors=0`, `skipped=1`; lock hashes and per-check log hashes are recorded; not a current full-suite result |
| `rc-20260916T120502Z-13398.junit.xml` | JUnit companion for the bounded dependency/build/guard lane |
| `agent-node22-provenance.md` | Proposed-YAML bounded Node 22 lane: agent `43`, realtime `8`, PHP manifest `3 tests / 8 assertions`, release chain `14`; Electron runtime install/test remains in progress; prior `ENOENT` observation is superseded |
| `native-mariadb.md` | Bounded native checkpoint with full `542` migration, data-bearing upgrade, synchronized DI claim/result/outbox probes and focused `34/154`; explicitly not final GitHub certification |
| `workflow-authorization.json` | Fresh `12:10:09` UTC owner-write observation: HTTP `404`, no PAT/SSH, no branch change, no dispatch; held commit and exact expected workflow blobs |
| `../proposed-workflows/build-agent.yml` | Exact proposed build workflow; held, not active on GitHub |
| `../proposed-workflows/pr-checks.yml` | Exact proposed PR workflow; held, not active on GitHub |
| `../proposed-workflows/workflow-authorization.patch.gz` | Exact compressed workflow patch; SHA-256 values are recorded in `workflow-authorization.json` |

### Bounded zero-advisory lock hashes

These hashes are copied from `rc-20260916T120502Z-13398.json` and identify the
lockfiles used by that bounded lane. They do not establish a fresh source-`903`
global audit.

| Lockfile | SHA-256 |
|---|---|
| `composer.lock` | `f77aae4ea1f5d8b5427132fb76e2ca017b7917ebdece26036715055a5fc141e4` |
| `package-lock.json` | `ee6faa95cd57839884299d07e871ae9593029be42bd363f523d1b13caac8ff10` |
| `pra-agent/package-lock.json` | `288f23ac9c63ec72a2318895ebb582f0cb85b31f07aeefcffc60800d22a74472` |
| `agent-realtime-gateway/package-lock.json` | `3c96680d9ce8a9d0c6085fafc250a3b3de4a8df3f099d40b441d4e76f54b461a` |
| `artifacts/mockup-sandbox/package-lock.json` | `aecf45ae4de09b7b7bdbe65fe0c21e88504a1ae01704ea6fdb42c2fd9cff861a` |
| `tools/video-pipeline/package-lock.json` | `1ea36c806550b001e4b9cf14d35160432094bf0a2e6facd73f8c93a087f7acf8` |

## Awaited fresh lanes

The following work is in progress from fresh source-`903` material. No result
artifact is available yet:

- Full PHPUnit (`5018` tests launched): **AWAITED — UNCERTIFIED**.
- Full desktop/mobile browser acceptance and 48-category contexts:
  **AWAITED — UNCERTIFIED**.
- Final native checks in a new clone plus two targeted proof-strengthening
  lanes: **AWAITED — UNCERTIFIED**.
- New fresh-checkout global proof: **AWAITED — UNCERTIFIED**.

The consolidated report must be updated only after these lanes produce
machine-readable results. Their in-progress counts are not pass counts.

## Historical evidence exclusion

`../evidence-observed.json` records observations whose temporary worktree and
raw logs were lost. Its historical `5014` exit-0 suite, historical browser
results, and old native observations remain provenance only. They are not
reused as fresh certification for source `903`.

No file in this index authorizes a workflow push, merge, deployment, release,
production query, fiscal submission, regulator operation, or hardware claim.