# FBR IMS discovery after pairing — PR #275

## Problem and reproduction

The owner reports successful FBR pairing but no IMS download section. An authenticated FBR cloud-mode heartbeat made the deployed `imsMode` return null and hide the card. This reproduces that supported configuration; it does not assert the reporter's live settings (no production access).

On verified main `7a12107f078997003ab04b46c073fc538c5ce13a`, real Chrome Headless Shell rendered the original production settings/helper against a loopback fictional IPC bridge. The new behavioral smoke timed out waiting for the FBR cloud IMS install control, reproducing the missing card. The same smoke passes against the corrected production settings/helper.

## Behavior and compatibility

Authenticated, connected FBR companies can discover/check/explicitly install local IMS in cloud, fiscal-device or missing-mode responses. Cloud/missing-mode explanation says installing IMS does not switch reporting mode. No automatic installation, reporting-mode change or saved configuration write is added. The official installer implementation and IPC security boundaries remain unchanged.

| Configuration | Expected result |
|---|---|
| FBR cloud / fiscal / missing mode, service missing | FBR IMS card and explicit install button |
| FBR service running | Running badge, install hidden |
| PRA fiscal-device | Existing PRA guidance; no FBR installer, even hidden-control invocation |
| PRA cloud / unknown product / disconnected / legacy company-ID-only response | IMS hidden |
| Existing company, counter, printer and silent-print preferences | Preserved |
| Invalid/missing build metadata or failed identity IPC | Honest unavailable date/version, rest of settings still functional |

## Release identity and previous release

Source package and both lockfile root identities advance to **1.13.23**; the pinned release-identity regression and reviewed graph fingerprint are updated. `v1.13.22` was published on 24 Oct 2026 and is immutable. GitHub had no `v1.13.23` tag during preparation. Comparing the old and new lock JSON after resetting only the two root version fields proves every dependency entry, integrity value and edge is unchanged. The approved Electron/runtime remains 42.11.16.

The existing PR branch incorporates verified main #272 with a history-preserving merge. #272 owner approval remains separate: approved head `06d6475b40ad5fa4ac2c3cac6273c46688b69d7d`, squash/live `7a12107f078997003ab04b46c073fc538c5ce13a`, [Deploy Production 40105006503](https://github.com/jawadrao5555-alt/taxnest/actions/runs/40105006503), extended live verification PASS and callback succeeded (request `590588a9-249b-44cb-b8ef-6772e3d63083`).

## Local verification

- Node **22.23.2**, `bash scripts/rc-safe-run -- node --test --test-concurrency=1 <all platform-independent pra-agent/test/*.test.js>`: **107/107 PASS**, no skips. The real Electron internet-cut harness is separately covered by mandatory CI, as the workflow already specifies.
- `CHROMIUM_BIN=<local Chrome Headless Shell 145.0.7632.6> node scripts/agent-build-display-smoke.mjs` on Node 22.23.2: **PASS**. Actual settings HTML, scripts and display; fictional IPC only. Covers the matrix above, explicit fictional install click, re-check, visible version/build date, missing/failed identity IPC; no unexpected settings calls or pageerrors. External browser requests blocked.
- Initial broad run on the host's unreviewed Node 24.22.1 was not an acceptance result: one timezone-sensitive existing waiter fixture and an uninstalled Electron harness failed. Repeating the workflow's platform-independent set through the clean Node 22 runner passes 107/107. No unrelated waiter/runtime code changed.
- `node scripts/tests/npm-lock-portability.mjs`: PASS, 308 Agent packages / 307 resolved sources; Agent graph `fea69730790ee8d75438cc5f0e34818df2a8c6a4e39bbb08c2a47201782391d2`.
- `bash scripts/tests/npm-lock-portability-test.sh`: PASS including isolated corruption/source-integrity regression.
- `bash scripts/tests/agent-release-chain-check.sh`: ALL PASS; immutable release and exact-source gates unchanged.
- `bash scripts/tests/elaan-spec-parse-check.sh`: ALL PASS; committed announcement dry-run passes with six points. Audience `all` because the new desktop Agent version/update instructions serve both PRA and FBR; FBR-specific points are explicitly labelled. No category exclusion, DI widening, existing announcement or read-state mutation.
- `npm run build`: PASS. Generated assets are verification-only and excluded from this Agent diff.
- `node --check pra-agent/test/native-pairing-harness.js` and `git diff --check`: PASS.

## Fresh acceptance required before readiness

Fresh exact-HEAD eight mandatory checks and the Windows PR packaging/native lane must pass. The Windows lane now additionally drives the actual delivered ZIP's app.asar settings through the production sandbox preload: actual packaged version/build date, FBR cloud/fiscal/missing-mode IMS, explicit fictional installation IPC, running service, PRA fiscal/cloud, unknown/disconnected and no configuration writes. It retains all existing native Connect/Cancel, company switching, stale/revoked exchange and preference-preservation checks. No real FBR installer, printer or installed customer Agent is invoked.

No local PHP runtime/MariaDB exists in this fresh environment; full PHPUnit and real MariaDB acceptance are required from the unchanged mandatory CI lanes, with results recorded in the PR body for the exact final head. Windows acceptance must come from the real Windows runner, never a Linux simulation.

## Owner boundary and limits

After fresh checks pass, stop for a **new PR #275 exact-HEAD admin approval**. The owner-controlled merge flow detects Agent changes and dispatches the exact-squash Windows release as documented in `docs/ops/owner-merge-and-deploy.md`. Preparing this PR neither publishes 1.13.23 nor replaces any existing tag/assets. Server deployment alone cannot replace installed desktop binaries. Actual test-PC installation, authenticated company pairing and fiscal/physical-printer acceptance remain post-release owner work; no field-installation or live IMS claim is made here.

## Refresh after the simple-billing correction (PR276)

PR276 is now LIVE VERIFIED: approved head `eea249038c20f2f6927778606272d33ffce25c55`, actual squash/deployed main `65fddf64a3d8ea8e3d93948b44d600d884db131e`. [Deploy Production40121120158](https://github.com/jawadrao5555-alt/taxnest/actions/runs/40121120158) completed successfully: exact live SHA, extended verification PASS and outcome callback succeeded for request `9b6e35f9-d0bb-43ff-8a7a-5a67ee286f75`. Prior relay success alone was not treated as proof.

The existing PR275 branch incorporates that verified main history-preservingly. The sole merge conflict was `deploy/elaan.yml`; this PR retains its reviewed Agent-specific announcement. The previously deployed simple-billing announcement remains in publication history/read state. No duplicate PR, production/release action or settings migration.

A diff against the new main confirms the application, PRA Blade, language files, simple-note browser workflow, note/address/caller tests and KOT/drawer/rider PHP regression sources are unchanged. PR276's removed portion controls stay removed; Parcel notes, sent-line/KOT delta guards, separate Delivery and historical financial compatibility remain intact. No whole-tree checkout from the old Agent branch is used.

Fresh local checks after merging main:

- Reviewed Node22.23.2 clean runner: all **107/107** platform-independent Agent tests PASS, no skips.
- New simple-note/address/phone/caller production-function regressions: **35/35 PASS**.
- Private supported PHP8.4.27 runtime is now available: the targeted simple-note/KOT/rider/drawer plus existing accessibility/responsive suites PASS (exact count recorded in PR body). This supersedes the initial preparation's missing-local-PHP limitation; no local MariaDB acceptance is claimed.
- Actual Chrome settings smoke PASS: FBR cloud/fiscal/missing-mode IMS, explicit fictional installation IPC, running service, PRA fiscal/cloud, unknown/disconnected, actual version/build-date display and missing/failed identity IPC. No settings writes or pageerrors; no real installation/download/printer/customer account.
- Reviewed lock graph, immutable release-chain and announcement checks PASS. Package/lock/identity remain1.13.23;1.13.23 tag was absent during refresh and published1.13.22 is preserved. No dependency or binary source changes beyond this existing reviewed PR.

The previous7414ba78 head's CI/Windows results are historical and are not reused. The refreshed exact head requires all eight mandatory checks, genuine MariaDB/browser/full PHP and actual Windows package/native acceptance. Results and the final approval SHA belong in the PR body. Owner approval for276 does not approve275.
