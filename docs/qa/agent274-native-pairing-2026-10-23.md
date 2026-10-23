# PR274 disposable native Windows acceptance

## Reproduced cause
Settings use Electron's default sandbox with context isolation and no Node integration.
The previous preload called `require('./src/ims-visibility')`. Electron's sandbox
loader cannot load local CommonJS files, so it throws before exposing agentAPI.
A renderer can parse perfectly while Test Connection, Save & Connect, Start and
Open POS remain inert. The restricted-loader reproduction on the prior source
produced `Sandbox cannot load ./src/ims-visibility`, with no bridge exposed.
Electron documents this restriction at https://www.electronjs.org/docs/latest/tutorial/sandbox.

## Correction
The pure IMS visibility helper also exposes a browser-only frozen API; the
settings page loads that local script. The preload now requires only Electron.
Sandbox, context isolation, Node isolation and fiscal/IMS visibility rules remain.
The existing company configurator is extracted without changing its decisions,
so both production main and the native acceptance harness execute the same
confirmation, revision, concurrent-manual-identity and preference-write logic.
Only production main connects the existing Agent lifecycle callbacks.

## Native Windows gate
The PR-only Windows workflow builds the real installer and portable ZIP, then
opens the actual packaged app.asar HTML/preload in an isolated Electron harness.
It never executes production main.js, installs an Agent, changes registry, uses
customer configuration, enumerates actual printers or physically prints.

The gate uses real mouse input for all four settings buttons; real packaged
PRA/FBR BrowserWindows and cookie-bearing Electron Session.fetch; and Windows
UI Automation to read the company name and invoke the real native Connect/Cancel
buttons. The coordinator persists only fictional settings in a disposable profile.
The HTTP server is a loopback protocol fixture, not Laravel; Laravel security and
plan/tenant/CSRF behavior remain independently covered by actual Feature tests.
Every non-loopback HTTP request is blocked. Confirmation text is checked not to
contain fixture credentials. Screenshots include only the public fictional page.

Required cases: rendered settings bridge and visible test result; save retaining
printer/silent-off; Start/Open POS click IPC; first PRA pairing; same-company
re-login without another prompt/write; Cancel switch without a credential POST;
server-rejected exchange retaining old config; FBR successful pairing preserving
printer/device preferences. Harness errors, preload errors and deadlines fail.
All prior mandatory eight gates and package output assertions remain required.

## Local evidence / limits
- Node24.19.0: same platform-independent selection as PR CI: 106/106 PASS, zero skips.
- Behavioral sandbox bridge regression passes; coordinator concurrent manual
  identity rejection and latest-preference preservation pass.
- JS syntax / workflow YAML / whitespace and existing release-chain tests pass.
- Sparse checkout initially omitted test fixtures/public scripts: the broad local
  invocation failed for missing files, then all 106 passed after materializing them.
- Native Windows is unavailable locally. Electron42.11.9 downloaded successfully;
  Linux graphical acceptance was not run (no display; Xvfb package retrieval was
  denied by container setgroups permissions). No local native acceptance claim.
- Fresh exact-source all eight checks plus the expanded Windows gate must pass.
  Prior 21fe3d8 all-green runs do not verify this correction.
- This is packaged-source native acceptance, not NSIS installation/SmartScreen,
  physical printer, fleet rollout or universal interrupted-update proof.

## First Windows harness result (historical HEAD637e79e)
Windows40004950589/job120590374696 built the actual installer/ZIP and passed
24 focused tests. Its launcher then failed before Electron started: plain Node's
fs.existsSync cannot traverse app.asar. The launcher now checks the archive file,
while the actual Electron harness requires the packaged coordinator and therefore
still fails for a missing module. No native assertions removed. A new exact-source
Windows run and mandatory eight remain required; this attempt is not UI proof.

## Second Windows harness result (historical HEAD91ae5ef)
Windows40005558602/job120592445001 again passed package output checks but the
launcher correctly rejected the absent archive. Source review of package-portable.js
confirmed canonical packaging intentionally removes win-unpacked after creating
the ZIP, protecting it from an old overwrite step. The workflow now extracts the
actual delivered ZIP into a disposable directory, requires its app.asar, and runs
the same native assertions on that delivered content. Packaging behavior and all
checks remain intact. This fixture correction needs fresh exact-HEAD verification.

## Third Windows result (historical HEAD837be305)
Windows40006149877/job120594446113 reached the actual delivered ASAR harness,
passed the settings-button/HTTP path before native confirmation, then its UIA
driver timed out locating the dialog. The driver previously searched only direct
desktop children; owned dialogs can be below the owner. It now searches descendants
and has a Win32 native Button fallback, strictly matching PID, dialog title,
visible company text and button name. It never fabricates a response; the harness
still requires actual dialog resolution and expected POST/write counts. This is
a source-supported driver correction, not yet a passing Windows acceptance claim.

## Native dialog diagnostic follow-up
HEAD e272b5a Windows40006788782/job120596631784 again reached native confirmation
but both bounded UIA/Win32 lookup timed out. This is not evidence of a successful
confirmation. The next source adds only own-process native window title/class
diagnostics (no control values/keys) and an explicit post-settings stage marker,
to distinguish a runner desktop limitation from a title/hierarchy mismatch.
No assertion, native dialog, identity match or deadline is bypassed.

## Exact native diagnostic (HEAD6cee9e)
Windows40007398837/job120598690273 confirmed the own-process #32770 native
`Connect TaxNest Agent` dialog exists and is visible, alongside the real settings
and NestPOS windows. This rules out an absent dialog/desktop for that attempt.
The driver now binds UI Automation directly with FromHandle to the HWND already
validated by PID/title/visibility, checks the actual company text/button name,
then invokes that control. If its provider lacks InvokePattern, only a parsed
actual AutomationId can drive documented native TDM_CLICK_BUTTON; no guessed
response ID or fabricated success. Native result/POST/write assertions stay.
Failure diagnostics may include only this known fixture dialog's static labels,
never settings values. This still requires a fresh native pass.

## Actual dialog control evidence (historical HEADa4b3d2e)
Windows40008040267/job120600887338 read the exact visible company message
`Connect this Agent to Fixture 101?`, Company ID101, Connect and Cancel. It still
timed out invocation. The driver now supports the exact named control's real
InvokePattern or LegacyIAccessible DoDefaultAction rather than assuming a command
link must expose ControlType.Button; actual dialog result and POST/write counts
remain mandatory. Failure metadata adds only this fixture dialog's control type
and AutomationId. This is not a fake response or assertion removal.
