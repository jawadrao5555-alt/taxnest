# IMS reply compatibility and empty Caller ID overlay

## Scope and evidence boundary
The shop's invoices reached the central FBR portal, but the Agent callback was
classified `verification_required` with `response_received`. The earlier receiver
stored only a diagnostic enum and discarded the callback's status/reference
fields. Its exact historical response format cannot be established from those
records. This patch does NOT claim the synthetic serializer variants prove the
specific shop cause. Previously reconciled bills must never be resubmitted.
Owner-directed recovery through the existing portal-evidence UI is separate
from this development and is not proof of an automatic acknowledgement fix.

## Reproductions
1. Real Alpine 3.14.9 + DOM reproduction using the original placement method:
   string `:style` replaces cssText, removing x-show's `display:none`. Empty
   Caller ID overlays become visible at boot/resize and reappear after close.
   The updated style object preserves hidden state, valid fallback opening and
   closing. The actual source predicate now also requires a nonblank number.
2. The baseline alongside bridge drops casing/underscore aliases and bounded
   JSON envelopes, and rejects whitespace-padded explicit success codes. It
   also loses conflicting case-alias status evidence. The new regression file
   failed all three original cases before the normalization change.

## Implementation
- Return an Alpine style object from `callerOverlayStyle`; keep measured header
  clearance, mobile max height, scrolling, zoom escape and payment blocking.
  Do not disable Caller ID, copy-number fallback, real rings or call history.
- Normalize fiscal field names/casing and at most two explicit JSON-envelope
  levels. Preserve all competing references in the canonical three-field
  contract. Conflicting statuses, invalid reference values, size/depth limits,
  absent code/number and unsafe numeric IDs remain unconfirmed.
- Success still requires explicit Code 100 AND a valid unique fiscal reference.
  No bare number, prose, HTTP 200 or timeout is converted into success. No extra
  fiscal POST, retry or change to existing records is introduced.
- Receiver trims harmless status whitespace and rejects conflicting Code/code.
  Keep only validated bounded status/reference fields in encrypted attempts.
  A bounded name/type-only response shape makes future unknown formats visible;
  it contains no response values, tokens, credentials, buyer data or raw XML.
- Agent 1.13.34, root lock identity update only. Full parsed JSON comparison
  verified unchanged dependencies/integrities; pinned fingerprint updated to
  match that reviewed identity change. No security or CI checks are relaxed.

## Compatibility matrix
| Configuration | Coverage / expected |
|---|---|
| Existing JSON/SOAP replies | Existing dispatch tests remain green |
| Explicit padded/lowercase/underscore/wrapped acknowledgement | Canonical Code/reference reaches callback in one dispatch |
| Conflicting codes/references, no number, oversized/deep wrappers | Unknown; never replay fiscal POST |
| Legacy safe numeric reference | Preserved as exact string; unsafe JS integer rejected |
| Callback outage/restart, another key/company/device | Existing scoped durable retry/claim tests retained |
| Older Agent without shape metadata | Optional protocol fields; existing callbacks unchanged |
| Idle/closed/blank-number Caller ID | Hidden through resize and style updates |
| Valid fallback / recent calls / large header / mobile / zoom | Retains working controls and bounded placement |

## Validation completed locally
- `PraDiSandboxTest`: 62 tests / 471 assertions passed, including safe reply
  evidence, status normalization, conflict rejection and existing tenant guards.
- `PosFederalAutomationTest`: 20 / 130 passed.
- `PosCallerDialBackTest`, `PosCallerIdPlanGateTest`,
  `PosCallerIdToggleAndEventsTest`: 63 / 151 passed together.
- Focused Agent/fiscal/release + overlay tests: 33 passed.
- Npm lock portability, PHP syntax, JS syntax and diff whitespace guards passed.
- Real-Alpine DOM baseline/updated reproduction passed. Reproduce independently:
  `npm install --prefix /tmp/ims-caller-tools --no-audit --no-fund jsdom@26.1.0 alpinejs@3.14.9`
  `NODE_PATH=/tmp/ims-caller-tools/node_modules node scripts/qa/caller-overlay-repro.cjs bfad147d8092379f6233230ed441de0293f66784`
- Chromium 153 synthetic loopback component reproduction passed at 1280px and
  390px: baseline empty popup reappears on resize, updated popup stays hidden,
  valid number opens and close remains closed. Screenshots saved locally via
  the repository browser helper. This is a component test, not a full Laravel
  screen run. Reproduce with `CHROMIUM_BIN=<supported cached binary> node
  scripts/qa/caller-overlay-browser-repro.mjs bfad147d8092379f6233230ed441de0293f66784`.
  Required hosted Chrome additionally asserts
  idle/closed/blank-number overlays stay hidden, opens a valid fallback, resizes,
  checks history placement, and saves synthetic desktop/mobile screenshots.
- Local PHP 8.3.6 is below the unchanged ^8.4.1 full-suite runtime contract;
  the required hosted PHP 8.4 full suite/MariaDB/browser/Windows checks must pass
  the published exact HEAD before owner approval.

## Release and live acceptance
No merge/deploy or new fiscal submission is part of this patch preparation.
Existing settings, money, stock, subscriptions, printers and pairing are retained.
After owner deployment AND Agent 1.13.34 release/update, inspect the next genuine
finalized bill once. It must obtain the reference automatically; compare central
FBR evidence separately. If still unknown, the retained code/reference/field
shape identifies the precise unsupported reply without resending any bill.
The historical customer root cause remains unconfirmed until that evidence is
observed. Do not label manual portal recovery as automatic network success.
