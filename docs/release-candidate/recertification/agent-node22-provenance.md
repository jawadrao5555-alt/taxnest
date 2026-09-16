# Agent Node 22 and provenance recertification

- Tested worktree base: `b5bbb9a20f3a5f62bd0964f9439cd99e4945fe58`.
- Tests exercised the uncommitted correction patch recorded below; this is not a
  claim that the workflow correction is active on GitHub.
- The isolated runtime was `node v22.15.1`, installed beneath ignored
  `.local/recertification/node22` with a generated package lock
  (`649a4b26c42400d8c90f3f39f7e2f93f10c1084cd877a37fa2de9616069856c3`).
  Its executable digest was
  `fcb06b28567cfe421d03fb223d08e38187b5b7c79ff8fe1809bfccbf68cdd814`.
- Each test command used a fresh `env -i` home and the compiled
  loopback-only `LD_PRELOAD` network guard. The only socket activity in the
  agent and realtime suites was their explicit loopback test servers.

## Corrected provenance contract

`build-agent.yml` now selects reviewed Node 22, installs the committed agent
lock with `npm ci`, verifies a full requested SHA checked out from `main`, and
creates `release-manifest.json` before upload. The manifest carries a
hash/size inventory of the canonical ZIP and versioned setup executable,
product/version/compatibility metadata, and sets both `source_sha` and
`build_sha` to the verified checkout SHA.

The PHP advertising validator and the desktop updater now fail closed unless
those two 40-character SHA values are equal (case-insensitively), in addition
to their existing canonical asset, size, digest, tag, URL, and compatibility
checks. This prevents a manifest from claiming a build from a different valid
commit. Existing owner-only dispatch, main-ancestry, tag-collision, serialized
dispatch, and no-replace controls are retained.

The exact full workflow artifact is
`docs/release-candidate/proposed-workflows/build-agent.yml`. It is now
byte-identical to `.github/workflows/build-agent.yml`, including EOF: both
have git blob `ea95479996ae6a24f8b36176df3f5e065e673aec` and SHA-256
`0eb9ccc78d9d53ce6beb5ae6ad3d3f0437f6da9e31187ed4b9962615ee5ed6be`.
Owner authorization is still required to commit and push the active workflow
path; this report does not claim it is active remotely.

## Fresh results

| Command | Result |
|---|---|
| `bash scripts/tests/agent-release-chain-check.sh` | PASS — 14 static safeguards |
| `node v22.15.1 --test --test-concurrency=1` on 23 platform-independent PRA Agent test files | PASS — 43 tests; 0 failed, cancelled, skipped, or todo |
| `node v22.15.1 --test --test-concurrency=1 agent-realtime-gateway/test/gateway.test.js agent-realtime-gateway/test/wake-secret.test.js` | PASS — 8 tests; 0 failed, cancelled, skipped, or todo |
| `vendor/bin/phpunit tests/Unit/AgentReleaseManifestTest.php` | PASS — 3 tests, 8 assertions |
| `node v22.15.1 pra-agent/test/local-core-internet-cut.test.js` | PASS — real Electron Local Core release-gate harness, including offline KOT, held-order replay, counter recall/settlement, encrypted local persistence, and idempotent retry |

The Electron testcase was not part of the earlier 43 platform-independent
tests. Its separately recorded bounded Linux execution below uses the locked
existing Electron runtime; no native or Windows release build was attempted.

`pkgs.nspr` is already available without changing the environment:
`/nix/store/gpb87pb8s826aggy1s3f352alp40dkj8-nspr-4.36/lib/libnspr4.so`.
No Windows packaging claim follows from that availability.

## Bounded Linux Electron attempt — 12:10:09

At 12:10:09, a fresh workflow-authorization lookup observed HTTP 404. This
records only that authorization observation; it did not dispatch, alter, or
publish a workflow.

The first guarded invocation established that the `--ignore-scripts`
dependency installation had left the locked Electron package without its
reconstructable `dist/electron` runtime (`spawn ... ENOENT`). A second,
explicit dependency-installation step used `npm rebuild electron
--foreground-scripts --no-audit --no-fund` for the existing lockfile-selected
Electron `41.10.7`, under a fresh isolated `env -i` home/cache. It completed
successfully and exposed `pra-agent/node_modules/electron/dist/electron`
(`v41.10.7`). No registry configuration, mirror, or credential workaround
was used.

The testcase was then run once with isolated Node `v22.15.1`, the fresh
`env -i` home and loopback-only `LD_PRELOAD` network guard. `xvfb-run` was
not installed, so the available `Xvfb` binary was started directly on an
ephemeral local display with TCP listening disabled. The execution passed
with the known NSPR library and the Nix development-shell library closure in
`LD_LIBRARY_PATH`; it printed `PASS Electron Local Core release-gate harness
(incl. offline KOT: local print job + single cloud order.held + single
order.settled)`.

This is an actual passing Linux Electron verification, not a GUI skip. No
native application build, Windows build, release build, publication, or
workflow change was attempted.

## Raw evidence

Raw non-secret command output, runtime resolution, correction patch, and
SHA-256 file list are retained under
`.local/recertification/agent-node22-20260916T115246Z-rerun/`.

- release-chain log:
  `81c70206cfd8eabcc47def563b83025f5ffc2cd93b6531750b1def0fb5eb7eac`
- PRA Agent suite:
  `0bfae3b246cd6aeb9f5e9ae4e3d7513e4937c13b15fee76530139075631bb98f`
- realtime suite:
  `d32eef98810790d0cedda0ae2baef0cd26dfb32c29fd2e7aa5f3ff5321870687`
- PHP manifest suite:
  `fd359cb10d3daa2aed402c3a80af9daecaca34e027718e35b03ffcea271848d1`
- correction patch:
  `57d54d1db54c96ea7902e59bf6732ad8fcd26b8319aeaeec573ea8c447aefa55`