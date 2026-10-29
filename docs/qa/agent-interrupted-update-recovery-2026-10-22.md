# Agent interrupted-update recovery — source candidate 1.13.22

## Report and source-supported gap

Owner asks for a practical recovery when someone closes the Agent or the PC
loses power during an update. Normal X already hides the window in the tray;
download failures retry, and a new launch can start a fresh download. The gap
is interruption during in-place file replacement: old code stores its only
backup and updater in TEMP, has no independent next-login recovery entry, and
cannot execute in-process repair if its own executable/app.asar is damaged.
An old Agent restarting during a copy can also begin another update attempt.
No real Windows interruption/production incident was reproduced on this Linux
host. This is source-supported diagnosis, not a claim of customer acceptance.

## Implementation and boundaries

- Store the backup/updater/result/repair script under a dedicated per-install
  userData/update-recovery directory, outside TEMP and the install tree.
- Arm a per-executable HKCU RunOnce recovery command before storing handoff or
  quitting. If Windows refuses it, keep the running Agent; do not replace files.
- Existing installer still waits for its exact source PID, backs up all files,
  and verifies old app.asar against backup before marking backup ready. Only
  then can it copy the new release. Original signed-size/hash/host/manifest
  checks, copy verification, rollback and release safeguards remain.
- Independent repair.cmd can run without Electron: before-copy interruption
  launches intact old files; interrupted copy restores verified backup and
  checks app.asar before launch; completed installation is never rolled back.
- Recovery first waits briefly for any process at this exact executable path;
  if still running it does not copy or kill it, and retains manual recovery.
- A failed repair never reports success or launches an uncertain copy. At most
  three automatic recovery logins are attempted; retain manual repair/backup.
- A pending/uncertain new handoff blocks overlapping installers. Legacy handoff
  records remain compatible. Recognized outcomes reach existing telemetry.
- Settings explain pending recovery in ordinary Roman Urdu and retain manual
  download. No fake forced retry button. Nothing restarts a real Agent here.
- Reject drive roots, other executable paths, recursive backup trees and repair
  state placed inside the installation. Never kill every Agent process or run
  uninstall; don't touch company/device/printer/fiscal settings, local-core DB,
  other profiles or unrelated installations.
- TEMP sweeps now require directory age over seven days and reject symlinks,
  rather than deleting other active update sources on every download.

Agent identity advances to **1.13.22** because released v1.13.21 is immutable.
Package/lock root version fields alone change; all 308 package entries,
dependency edges/integrities and exact Electron42.11.3 remain unchanged.
Reviewed graph: 4d7ee4ae41ce6ca650d026c98a09aea935629bc3c3f746903dbe31972122b1c5.
No old release tag/assets is moved or overwritten.

## Matrix and actual checks

Reported interruption during copy: independent restore of damaged app.asar.
Previously working normal copy: installed outcome is kept, no rollback.
Before-copy closure: original files launch, pending journal is resolved.
Failed/missing backup: do not launch; retry is bounded and explicit fallback
remains. Other install/drive roots: refused. Other languages/configuration and
normal download progress remain unchanged; actual settings render function is
exercised. Legacy handoffs keep their existing policy.

Local Node checks (Linux): 23 pass, five Windows-only cases skipped. Tests cover
registration scope/encoding, awaited registration failure, safe directory
boundaries, installer ordering, pending journal/overlap handling, outcome
telemetry, bounded repair, real settings banner, existing PID/rollback/login
regressions, release identity and legacy build display. Lock portability and
negative source-integrity, release-chain matrix, active/proposed eight-gate
contract, syntax and whitespace pass. This host has no PHP, Chrome or Windows.

New PR-only read-only Windows workflow runs the real generated recovery body
in disposable folders (including spaces), with damaged/old/new fictional files
and a harmless compiled Windows fixture executable. Covers before-backup, mid-copy,
completed copy and missing backup; checks actual restored bytes and launcher
probe. Registry operations are injected no-ops in that filesystem acceptance;
registration command generation is separately tested. It never installs or
restarts a real Agent, writes a real RunOnce entry, prints or publishes assets.
The existing eight mandatory checks are unchanged; require them AND this
Windows workflow before calling source review-ready. All fresh results pending.

## Material acceptance limits

- Protection starts only after this newer Agent is installed. The first update
  from an older Agent still uses its old updater; an already broken old install
  can need one manual repair/install without uninstalling saved configuration.
- Real Windows logoff/login RunOnce execution, concurrent normal startup,
  full packaged Electron/DLL replacement and physical power-loss timing remain
  separate acceptance; filesystem fixtures are not those proofs.
- Recovery is on the next login of the same Windows user, not an immediate
  offline reboot service. Restricted Windows policy/filesystem permissions,
  disk failure/full disk or a lost/corrupt backup can still need manual repair.
- Completed-backup retention is now scoped to the exact install subtree: keep
  two newest completions plus the journal-referenced copy; never remove the only
  rollback copy. Failed/pending/unknown/unreadable/link-bearing attempts and
  other installs remain untouched. Those exceptional copies can still require
  manual disk review; disk exhaustion safely fails the backup before replacement.
- No Windows release build/assets/update discovery/fleet rollout is triggered
  or proven here. Offline Agent root cause remains separate. No customer or
  production access, secret, real print, approval, merge or deploy.

PR remains DRAFT until actual checks and stated acceptance requirements are
reviewed. #238 is separately being prepared as next release; #236 correction
continues; #237 HOLD remains untouched.

## Fresh CI correction — 22 October

Windows run39735650547/job119680583375 failed the three launch cases with EBUSY
at cleanup, masking a sixty-second command timeout. START of the fictional .cmd
launcher opens a persistent command shell whose CWD locks the installation;
the real product launches an .exe. Replace only that fixture with a harmless
C# WindowsApplication executable compiled using the existing Windows PowerShell
Add-Type tool. It writes a probe beside itself and exits. Retain every actual
copy/result/launch assertion, all original interruption stages, real production
recovery script and no-op registry injection. Cleanup retries file-handle
release briefly, still fails on error and preserves the primary test error if
cleanup also fails. No blanket process termination or skipped Windows checks.
Fresh Windows CI must confirm this correction; Linux cannot run it.

The audit/build failure in old generic run39735650480 was not an advisory:
registry.npmjs.org bulk audit returned HTTP503 after300seconds, followed by the
retired fallback endpoint's HTTP400. No audit bypass/dependency change was made;
the intentional new HEAD will get a new full locked audit, not a superseded-head
retry. Old fullPHP/MariaDB/Chrome/Node/workflow/release lanes passed.

Real disposable filesystem regressions now verify retention keeps current and
sole rollback copies, two recent completions, all uncertain attempts, foreign
installations and external symlink targets. Existing dependency/release identity
and gates remain. Latest local combined focused count is27pass/fiveWindows
skipped; fresh all8plusWindows checks still required. Real Windows login/RunOnce,
full packaged DLL replacement and physical power-loss acceptance remain gaps.


The corrected executable fixture exposed a real marker-parser gap in fresh
Windows39741532558/job119699645205: before-backup/mid-copy/missing-backup and
retention passed, but completed-without-newline read as recovered_old_version.
Windows FINDSTR /x did not match that EOF marker. Compare exact trimmed file
content with PowerShell instead; only installed is success, unreadable/partial
markers still take the guarded recovery path. Retain the no-newline regression
and add the production echo CRLF variant. Do not relax the completed-install
assertion or claim Windows acceptance until the new exact-HEAD run passes.


## Refresh after owner-deployed #238 — 22 October

Verified owner handoff39742749962 and exact Deploy39742816381 for
7101db90b404f9950d15d97aa4cc50da588bb49f: live SHA/extended NestPOS markers
PASS in job119704281151 at07:48:40Z; callback119704977836 returned succeeded.
Merge that deployed main into this existing draft without changing recovery
runtime behavior. Resolve only the announcement conflict with this PR's unique
recovery benefit. Preserve #238 display helper, immutable notice/read-state
behavior and actual notification browser regressions, plus #227 tutorial/CI
contracts. Agent1.13.22, Electron42.11.3 and reviewed graph
4d7ee4ae41ce6ca650d026c98a09aea935629bc3c3f746903dbe31972122b1c5 remain.

Fresh local focused checks:27pass/five Windows-only skips, including actual
settings renderer, recovery registration/result/path policy, legacy PID/login,
retention filesystem isolation, pinned identity and legacy build display.
Release-chain/locked graphs/negative-integrity/CI contracts/JS/whitespace pass.
Node24.19.0; PHP, installed Chrome and native Windows absent. Previous exact
cbcf41fe Windows39742081559/job119701503827 passed20/20, no skips; that result
is historical after this merge. Require fresh own all8 plus actual disposable
Windows workflow on the new HEAD. Keep DRAFT: real Windows login/RunOnce,
concurrent startup, full packaged DLL replacement, physical power loss and
first legacy upgrade remain separate unverified acceptance; no universal
recovery/fleet claim. Failed exceptional backup disk review still applies.
No production/release workflow, real Agent/registry/login/reboot/print or owner
approval/merge/deploy performed.
