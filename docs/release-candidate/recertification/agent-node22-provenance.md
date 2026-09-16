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

The exact proposed workflow artifact is
`docs/release-candidate/proposed-workflows/build-agent.yml`; its expected
git blob is `ea95479996ae6a24f8b36176df3f5e065e673aec`. The corresponding
unstaged active-path proposal has expected blob
`326276fafa4dce4e7b771974bd65c02a67fd2351` (the only byte-level difference
is the trailing newline). Owner authorization is still required to commit and
push the active workflow path; this report does not claim it is active
remotely.

## Fresh results

| Command | Result |
|---|---|
| `bash scripts/tests/agent-release-chain-check.sh` | PASS — 14 static safeguards |
| `node v22.15.1 --test --test-concurrency=1` on 23 platform-independent PRA Agent test files | PASS — 43 tests; 0 failed, cancelled, skipped, or todo |
| `node v22.15.1 --test --test-concurrency=1 agent-realtime-gateway/test/gateway.test.js agent-realtime-gateway/test/wake-secret.test.js` | PASS — 8 tests; 0 failed, cancelled, skipped, or todo |
| `vendor/bin/phpunit tests/Unit/AgentReleaseManifestTest.php` | PASS — 3 tests, 8 assertions |

`pra-agent/test/local-core-internet-cut.test.js` was deliberately excluded:
it launches Electron and therefore requires a native/Linux GUI binary. No
native or Windows release build was attempted. The platform-independent
internet-cut harness is included in the 43 passing PRA Agent tests.

`pkgs.nspr` is already available without changing the environment:
`/nix/store/gpb87pb8s826aggy1s3f352alp40dkj8-nspr-4.36/lib/libnspr4.so`.
No Windows packaging claim follows from that availability.

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