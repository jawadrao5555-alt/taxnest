# Agent printer default paper size — 2 November 2026

## Report and confirmed defect

The reported shop received blank automatic KOTs while a Windows test page and
a Chrome KOT produced text. The Chrome sample also clipped the right-hand
quantity/time. The physical roll width, exact successful Chrome queue and
Windows port/driver defaults were not confirmed. Therefore this patch must
not be described as a completed physical-printer repair.

There is a confirmed defect at the Agent/Electron API boundary:

- `pra-agent/src/printer.js` omitted both `pageSize` and
  `usePrinterDefaultPageSize`, assuming omission selected the driver default.
- The pinned Electron **42.11.16** implementation defaults
  `usePrinterDefaultPageSize` to false and, without explicit media, chooses
  **210000 × 297000 microns (A4)**. It calls `GetPrinterDefaultPaperSize` only
  when that option is true. This explains a real difference from Chrome's
  print dialog and is a strong candidate for the incident, not physical proof.
- The fix passes `usePrinterDefaultPageSize: true` to the existing shared
  print call. No explicit 58/80mm size, scale, printer name, tenant or driver
  is hard-coded. Invalid/unavailable printer defaults can still fall back to
  A4 inside Electron; the driver must be configured correctly.

Pinned primary evidence:

- https://github.com/electron/electron/blob/v42.11.16/shell/browser/api/electron_api_web_contents.cc
  (`OnGetDeviceNameToUse`, default-size selection, and `WebContents::Print`).
- https://github.com/electron/electron/blob/v42.11.16/docs/api/web-contents.md
  (`contents.print`, `usePrinterDefaultPageSize`).
- https://releases.electronjs.org/pr/50808 (explicit media > selected printer
  defaults when requested > A4 fallback).

## Route audit and compatibility matrix

| Route/configuration | Traced path | Result/safety |
| --- | --- | --- |
| Chrome preview and explicit print | RestaurantPosController kitchenTicket → kitchen-ticket Blade → window.print | No Agent call or change; a successful Chrome print is not proof of Agent output. |
| Cashier full/delta/add-on KOT, transaction KOT, KDS | universal/KDS enqueue → PosController/KotPrintService → AgentController content → processCloudPrintJob → printHtml | All reach the corrected printer call; no enqueue, stamp or permission changes. |
| VOID and kitchen/counter/station copies | KotPrintService routes → cloud jobs → shared printHtml | Each selected queue uses its own driver paper; copy and order-wave rules unchanged. |
| Printer identification test slip | apiTestPrintJob → test-slip render → processCloudPrintJob → printHtml | Uses the same correction; acceptance still means spool acceptance only. |
| Bill/proof, 58mm and 80mm queues | AgentController receipt/proof render → printHtml | Preserves selected queue, silent/background printing and zero margins. Both queue widths are covered at the API boundary. |
| Desktop direct bridge, no explicit printer | main.js pos-print-html IPC → printHtml | Existing authorized sender check retained; requests the OS default printer's paper when deviceName is absent. |
| Offline/local/LAN KOT | main.js local core → local-kot drain → injected printHtmlSilent | Same corrected helper; local claim, handoff, encrypted store and ack logic unchanged. LAN cabling does not bypass the Windows print driver in this path. |
| Several printers/jobs | printer-queue scheduling and printHtml lane mutex | Different queues remain independent; same queue/order waves remain ordered. |
| Empty delta, failed download, lost acknowledgement | processCloudPrintJob and server claim/result handling | Empty/204 is no-document; no automatic second spool attempt after uncertainty. |
| Older installed Agent | unchanged server payload/API | Continues existing behavior until an owner-approved new Agent is installed. No server migration or settings reset. |
| Tenants/settings | no persisted company/device configuration modified by this patch | No DB contract change or tenant-specific values; existing isolation/queue ownership remains in server code. |
| Legacy root pra-agent/printer.js | not required by main.js/src/agent.js and not included in build.files | Inactive file is deliberately not patched; packaged runtime is src/printer.js. |

## Reproduction and tests

Before the fix, existing printer/job/queue tests passed (23). A new behavioral
regression exercised the real `processCloudPrintJob → printHtml → Electron`
boundary and captured the submitted options. With unchanged production code,
it failed on `usePrinterDefaultPageSize: undefined` instead of true; the
default-printer case failed too (3 pass / 2 fail). The BrowserWindow/spooler is
mocked: this is API-boundary reproduction, not a fabricated native print test.

After the fix, the focused printer, job, queue, poll, local-KOT,
internet-cut/reconnect harness and release-manifest run passed **34/34** on
Node 24.22.1. The new matrix covers full KOT, VOID, bill, proof and
test jobs without adding second print attempts. Existing tests cover same-
printer serialization, independent queues, no-document and acknowledgement loss.

After a clean dependency installation with the repository's pinned npm 10.9.2
bootstrap and supported Node 22.23.2:

- Platform-independent Agent suite: **136 tests, 131 passed, 0 failed, 5
  existing Windows-only updater recovery tests skipped**. The native Electron
  offline test is a separate command, not included in this total.
- Final release-manifest tests: **5/5 passed**.
- Agent dependency audit: **0 vulnerabilities**.
- All five lock graph checks, lock-portability negative tests, release-chain
  guard, announcement spec parser/dry-run and `git diff --check`: **passed**.
- Local announcement scope suite: **3 passed / 1 blocked** because this clean
  worktree has no `vendor/autoload.php`; the available PHP binary alone is not
  sufficient. The full PHP/MariaDB and announcement scope checks must pass in
  the existing disposable PR workflow.
- Native Electron offline preflight: **blocked**, executable absent (`ENOENT`)
  because dependency installation skips the Electron binary. The PR lane
  explicitly installs Electron and Xvfb before running this acceptance test.

No Blade/UI, DB, controller or route behavior is modified. Native Windows,
CUPS and a local display server are unavailable here. No production
credentials or physical printing are used by tests. CI results belong to the
PR's exact head and do not replace the Windows/physical-paper acceptance below.

## Release and field acceptance

- Source version **1.13.27**; 1.13.26 is used by separate PR #329. Git tag lookup
  found released tags through 1.13.25, with no 1.13.26/1.13.27 tag. Recheck
  merge order/version before release; do not overwrite any existing tag.
- Only package and the two lock root versions change. A structural comparison
  against base proves all **293 dependency entries**, edges and integrity
  values identical; reviewed fingerprint advances accordingly. Electron stays
  pinned at 42.11.16.
- Owner-approved Windows build/install is required. A website deployment or
  POS refresh alone cannot change the installed Agent's printing engine.
- On the target PC, confirm the same queue/port used by Chrome, match driver
  Preferences and Printing Defaults to the actual roll, then test one labeled
  slip. Confirm readable text, complete right column/barcode and feed length.
- Then check one genuine KOT/add-on and a working receipt; do not replay old
  kitchen orders automatically. Do not report physical paper verified from a
  successful Windows callback.
- Roll back through a new reviewed change/release if the target driver rejects
  its own paper default; preserve saved device/printer assignments.
