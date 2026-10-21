# Rider cash: select all, omit pending bills, confirm received cash

Owner source 017ddaae-0b37-4618-8652-b45bf8a6fa96.mov requests selecting all, excluding two/three pending orders and marking the rest delivered/paid. Existing cash-settlement backend already accepts selected bill_ids and advances fully paid assigned/dispatched bills to Delivered. Do not add a second money/ledger path or use bulk-status to pretend cash was received.

Make this existing flow discoverable with Select all cash bills (indeterminate after omissions), plain instructions and explicit confirmation showing selected count and received cash. Disabled/prepaid-in-progress bills are not toggled; empty/invalid/over-received/pending forms cannot confirm. Cancelling confirmation submits nothing. Existing partial-receipt allocation remains; only fully paid selected bills advance, and omitted bills remain unchanged. No tax, payment-mode conversion, fiscal identity, settings or tenant/rider scope change. This is cash settlement; online/prepaid conversions keep existing admin guards. PR #237 remains HOLD and is not implemented here.

Actual UI selection/confirmation module tests: 3/3 pass locally. PHP syntax-only, JS syntax, whitespace/CI contract pass. Added backend selected-two/omit-one regression asserting Delivered, unchanged omitted snapshot, original fiscal status, repeated request rejection and one exact cash ledger receipt; existing returned/card/foreign-company/rider/partial/archive invariants retained.

Added real Chrome board/modal acceptance with separate fictional rider fixtures per viewport: select all, omit one, verify 200 cash, cancel/no POST, confirm/one POST to disposable local DB, verify only omitted bill remains, capture desktop/390px evidence. No real rider cash, production data, Agent or print operation. Supported local PHP 8.4/application/browser unavailable, full supported CI/visual acceptance pending; no independent production claim.

## Overnight CI follow-up

Latest full PHP/selected-ledger tests passed. Mobile browser found the support widget z60 intercepting settlement confirmation; keep the selected-settlement overlay at z80. Preserve genuine pointer checks, cancel/no-POST and confirm/exactly-one-POST. Shared rider map fixture also crossed nightly cutoff; explicitly start fictional duty through its real loopback API before upload, preserving auto-off enforcement and all map checks. Fresh browser CI required.

Local environment recheck: loopback binding is available in this run, but supported PHP 8.4 is absent and Chrome installation failed (truncated/non-zip download). Local pure JS/syntax checks are limited evidence; supported real browser/database/full PHP acceptance remains GitHub CI, not customer production acceptance.
