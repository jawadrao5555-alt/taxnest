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

Local Node checks (Linux): 23 pass, four Windows-only cases skipped. Tests cover
registration scope/encoding, awaited registration failure, safe directory
boundaries, installer ordering, pending journal/overlap handling, outcome
telemetry, bounded repair, real settings banner, existing PID/rollback/login
regressions, release identity and legacy build display. Lock portability and
negative source-integrity, release-chain matrix, active/proposed eight-gate
contract, syntax and whitespace pass. This host has no PHP, Chrome or Windows.

New PR-only read-only Windows workflow runs the real generated recovery body
in disposable folders (including spaces), with damaged/old/new fictional files
and a harmless fake-agent.cmd launcher. Covers before-backup, mid-copy,
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
- Backups are intentionally retained for repair; cleanup/retention needs final
  review before rollout to avoid accumulated disk use. Do not silently discard
  the sole recovery copy merely to save space.
- No Windows release build/assets/update discovery/fleet rollout is triggered
  or proven here. Offline Agent root cause remains separate. No customer or
  production access, secret, real print, approval, merge or deploy.

PR remains DRAFT until actual checks and stated acceptance requirements are
reviewed. #238 is separately being prepared as next release; #236 correction
continues; #237 HOLD remains untouched.
