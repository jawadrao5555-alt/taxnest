# Agent bulk consolidation — 27 October 2026

Owner requested one separate Agent bulk PR for #234, #250, #251 and #256. Base: deployed #303, `a7875f3cb27f1f73b7dc9ea0ebd01c3e2dc43de1`. All source heads/history are retained; originals remain open for reference.

| Source | Pinned head | Scope |
|---|---|---|
| #234 | `e8737cadb8ecebc2b56d43a0681eb4852d36ebc3` | Complete Agent Health fleet counts and observed heartbeat evidence |
| #250 | `e6316b1f718fae7e2b91db551ef6b99799023c7a` | Visible POS launch failures and explicit browser recovery |
| #251 | `fc2c52a5e8bb33a29f86caa8246478c56aeb3232` | Default-OFF native report Save As preference |
| #256 | `7ef4aaebb67cd37ea00be9f96c936dccc7025641` | Durable interrupted-update recovery and bounded backup retention |

## Integration and compatibility

- Count all in-scope enabled companies before bounded diagnostic details; observed device/heartbeat evidence does not establish a physical outage cause.
- Keep the deployed IMS discovery and all existing Agent settings/build identity checks. Both actual UI scripts load in the combined settings fixture.
- Keep one guarded POS launch handler; add the report-folder preference listener without restoring the old duplicate click handler. Failure never auto-opens a browser. Explicit browser IPC accepts no renderer destination argument.
- Missing/OFF folder preference preserves silent collision-safe Downloads. ON uses native Save As; cancellation is silent. Preserve unknown saved keys, kiosk/startup, configured origins, offline/local-core, device and printer settings.
- Merge independent recovery and retention code while retaining current patched Electron **42.11.16**, undici/fast-uri and all current lock protections. Never restore old source #256 Electron42.11.3.
- Source identity **1.13.24** reserves a new version rather than overwriting existing1.13.23. No tag/release/build/update discovery is created. No existing v1.13.24 tag was found at intake.
- All 308 locked package entries, dependency edges and integrity fields compare equal to deployed main after removing only top/root release version fields. The strict reviewed full-lock graph changes from `fea69730790ee8d75438cc5f0e34818df2a8c6a4e39bbb08c2a47201782391d2` to `90de4e526b499322af5c47c685ac31aeea3feff808b24333318282893b07072b` solely for identity. Package/resolved counts, current dependency pins and negative integrity tests remain enforced.
- One ordinary Agent announcement replaces overlapping source announcements. No production configuration/data mutation and no financial/PRA/print routing changes.

## Current local evidence

PHP8.4.27, Node24.22.1, Chromium151 on loopback:
- Existing CI-style Agent Node discovery (excluding the separately exercised native Electron internet-cut test): **131 tests,126 pass,5 Windows-only skips,0 failures**.
- Diagnostic service and owner formatter: **19 tests /110 assertions pass**.
- Actual Agent settings HTML smoke passes version/date, refused POS launch, no automatic browser fallback, explicit browser click, existing FBR cloud/fiscal/unknown IMS discovery, explicit fictional installer, running service and PRA modes. IPC is synthetic; no native shell/browser/install action.
- Locked graph and its isolated negative/source-integrity regression checks pass.
- Current locked Agent audit: **0 vulnerabilities**. Release-chain, JS syntax and whitespace checks pass.
- New PR's full PHP/MariaDB/Node22/actual desktop390/browser/audit-build/workflow/release/validate checks and disposable Windows recovery workflow remain required. Historical source greens do not prove this combined head.

## Material unfinished acceptance

Keep DRAFT. Windows native login/RunOnce execution, startup concurrency, full packaged Electron/DLL replacement, foreground window/browser handoff, native Save As and actual physical power-loss timing remain unverified. Disposable filesystem Windows CI is preserved but does not waive those gaps. Local Electron AF_UNIX display constraints prevent native Electron acceptance here.

Recovery protects only installations with the new updater already installed; a first upgrade from an old/broken Agent may still need manual repair without uninstalling saved settings. Corrupt/missing backups or failed/full disk may require explicit recovery. No universal power-loss, customer outage, offline/root-cause, fleet rollout, physical printing or production acceptance claim.

No approval, merge, deploy, relay dispatch, registry changes or release assets were performed. Agent publication remains owner-controlled after all current-head gates and required acceptance.


## Additional native acceptance — 28 October 2026

Owner requested closing the remaining verification gaps. Add a fail-closed
Windows packaging-job step using the actual delivered portable app.asar and
its production POS BrowserWindow/download handler. The disposable Electron
profile, Downloads directory and alternate folder are generated beneath a
unique OS temp directory. A fictional loopback CSV is the only report.

The new native harness verifies:
- Existing POS window hides, returns as the same window, becomes visible/focused.
- Default OFF keeps collision-safe Downloads and preserves the pre-existing file.
- Opt-in opens the actual Windows Save As dialog; an own-process, exact-title UI
  Automation driver enters a filename in the alternate fixture folder and clicks
  the actual Save control. Assert completed status, chosen filename and bytes.
- Actual Cancel reports cancelled, creates no selected file and does not silently
  fall back to Downloads.
- Switching OFF at runtime resumes collision-safe silent download on the same session.

No dialog response mocking, automatic pass on unsupported OS, external browser
launch, installed Agent, registry, production origin, printer or fiscal request.
The added CI step is required to pass; missing real dialog controls fail the job.
Local Node syntax and focused download/recovery/retention/result/retry group:
24 tests, 19 pass, 5 Windows-only recovery skips, zero failures. Native Windows
execution remains pending the new current-head CI, not established by Linux.

Remaining honest limits: OS login-triggered RunOnce execution, startup race with
an installed Agent, complete Electron/DLL replacement under Windows locks,
external default-browser handoff and actual power-cut durability are not covered
by this new test. They require an isolated Windows installation/VM and observed
acceptance; never use an active customer's billing PC to manufacture a failure.
A failed/full disk or legacy broken updater may still require manual recovery.
Do not call these gaps cleared because the native download tests were added.
All eight mandatory checks and both Windows jobs must pass on the new head.
