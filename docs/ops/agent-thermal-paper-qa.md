# Agent 1.13.28: optional content-sized thermal paper

Reported flow: a LAN thermal queue accepts jobs, but paper contains a very long
blank section. Chrome and Windows test-page printing produce text. A separate
photo shows a flattened bill twice; that does not identify whether there were
one or two jobs, reprints, copies, or a text-only driver path.

## Scope and compatibility (defined before implementation)

| Configuration | Behavior |
|---|---|
| Existing queue, no new option / A4 | Unchanged Windows default paper |
| Explicit 58mm queue | 58mm page, 48mm body, measured print-media height |
| Explicit 80mm queue | 80mm page, 72mm body, measured print-media height |
| Multiple queues / PCs | Exact queue-name profiles local to each PC; existing mutex/routing retained |
| Older settings caller / new paired company | Omitted field preserves PC settings; no company/API schema changes |
| No explicit deviceName | Existing Windows default path; select an explicit queue to use a profile |
| Driver refusal / measurement failure / timeout | One failure, no fallback submission or replay |

The prior helper requests the entire Windows default form (which may be
58×3276mm) and has no content-height calculation. This is a verified code gap,
not proof that the customer's current driver uses that form. The opt-in path
measures print CSS, fonts/images, table and SVG/footer geometry, overrides the
page dimensions, and retains HTML rendering. It refuses oversize/overflow
rather than silently clipping. A 3mm footer allowance and 20mm minimum apply;
receipts longer than 2000mm require Windows mode. Driver custom-media support
still needs a physical test.

Also closes a concrete lifecycle gap: a fonts/layout continuation after the
30s timeout could call print on a reused window. The settled guard and disposal
now prevent late submission. This is not proof of the photo's duplicate cause.

## Local evidence

- Prior implementation: opt-in regression fails (missing paper profile support).
- Node 22.23.2 pinned dependencies installed; Electron remains 42.11.16.
- Final local Agent suite: 138 pass, 5 Windows-only skips.
- Targeted final tests: printer concurrency/timeout 9 pass; thermal/config 3 pass.
- All five lock portability guards pass. Whole lock compared: only the two
  root version fields changed to 1.13.28; dependency graph unchanged.
- Native Electron launch attempted with both ordinary and single-process
  headless flags. This execution environment denies sockets/udev and exits139
  before BrowserWindow renders. This is a testing limitation, not a passing check.
- Initial PR runner test passed (run 41465863420); the existing browser smoke
  needed its loopback static-file allowlist extended for the new UI helper.
- Mandatory PR runner test `thermal-paper-native.js` renders synthetic short and
  40-row tickets on 58/80mm, checks one-page PDF dimensions/footer, and exercises
  the real settings DOM/save with mocked IPC. No customer data or print jobs.
- PHP/MariaDB not installed here; backend/Blade/DB unmodified. Full PHP tests
  and Windows packaging remain required PR checks.

## Shop acceptance after owner approval/release

1. Confirm this PC is running Agent 1.13.28 (a server deploy alone is insufficient).
2. Agent Configuration → **Lambi khali slip? Printer paper mode**.
3. On the exact queue used by the KOT, explicitly choose its physical 58/80mm
   roll. Do not infer width from names like POS-58/POS-80. Save & Connect.
4. Send one clearly labelled test slip through existing POS Printer Settings.
   Check readable columns, footer/barcode, paper length and exactly one copy.
5. If the driver rejects/ignores custom media, restore **Windows paper setting**.
   Do not blindly replay old KOTs. Confirm the printer model/graphics driver and
   captured job count before choosing a different rendering/transport path.

No claim of physical resolution, deployed version on the shop PC, or correction
of the separate flattened/duplicate sale receipt is made by this PR.
