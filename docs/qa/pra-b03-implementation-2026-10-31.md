# PRA B03 implementation work ledger

Owner requested the next implementation after deployed PRs #317/#322/#323.
Source contract: planning #304, retaining #280/#281/#261 and the complete B03
requirements. Planning documents remain requirements, not completion evidence.

## Compatibility matrix before implementation

| Flow | Reported / failing case | Working configuration to preserve | Negative / concurrency |
|---|---|---|---|
| Phone search | Reprint omits phone; local/international spelling diverges | Name/serial/amount, partial digits and combined business-day/type/payment filters | Foreign tenant/foreign country code, isolated cashier |
| Customer correction | Existing profile correction and order-only choices require identity continuity | Historical bill/address snapshots and explicit profile save | Duplicate canonical identity and stale profile write |
| Table/mode | Auto-KOT after selection; parallel select/recall | Existing selected table, held order and explicit auto-KOT preference | Cancel, occupied table, repeated click, failed reserve |
| Print settings | Eight optional designs plus existing per receipt/KOT and Local/Fiscal | Existing arbitrary fonts, logo/QR/footer/width and routing | Preview/cancel/invalid ID/foreign tenant/old client |
| Print recovery | Retain existing safe pre-fetch recovery vs ambiguous accepted jobs | One/many printers and counters, legacy Agents | Offline/reconnect/failed route; no blind reprint |
| Staff approval | Optional proposal/review/finalization contract remains independently tracked | Ordinary bills and reporting-OFF NULL status | Role/tenant/revision/duplicate finalization |

Implementation and actual evidence will be appended by checkpoint. No whole-B03,
live, physical paper or installed-Windows acceptance is established by this ledger.


## Implementation checkpoint: checkout/search/print designs

- Canonical phone lookup/search and company-row-locked creation preserve typed
  legacy duplicate selection, foreign country codes and tenant boundaries.
  Reprint applies business-day/type/payment/search before the 300-result cap.
- Auto-KOT table selection requires confirmation; Cancel retains draft/table.
  Concurrent table selection is suppressed. Deliveries has local Retry and
  explicit New Sale with draft confirmation and focus restoration. Existing
  provisional editing exits through its established no-write navigation.
- Nine choices (Existing plus eight optional designs) for receipts and KOTs,
  independent Local/Fiscal settings. Actual-renderer previews are synthetic,
  sandboxed and script-free. Explicit scoped Save changes only that design;
  existing fonts, sizes, saved routing and other-stream settings are preserved.
- Homogeneous KOTs avoid repeated PACK labels; mixed orders retain both
  fulfillment instructions. Saved printer summary distinguishes PC acceptance
  from physical paper. Agent binaries/routing protocol are unchanged.

## Reproduction and local evidence

Before-fix HTTP failures established formatted-phone lookup and ignored Reprint
filters. Behavior tests established unconfirmed auto-KOT and duplicate table
reserve requests. These cases pass after the implementation.

- New JavaScript behavior tests: 14 passing; related JavaScript regression set:
  53 passing. Frontend build passes; generated assets are not part of this PR.
- KOT/routing/print regression: 101 tests, 937 assertions passing.
- Scoped receipt-save and theme regression: 53 tests, 705 assertions passing.
- Customer regression: 19 tests, 267 assertions passing.
- Final design tests: 10 tests, 522 assertions passing, including all nine
  designs, both streams, both receipt widths, script-free sandboxed previews,
  preservation and mixed-order KOT instructions.
- Initial full suite: 5,768 tests with one Urdu language-purity failure; the
  untranslated button words were corrected. Final suite/CI results are recorded
  in the implementation PR body for its exact head.

The local host denies AF_UNIX socket creation, so native MariaDB/Chrome lab
acceptance cannot run here. Required CI now runs six simultaneous actual
customer-store controller workers against native MariaDB with process-local
cache, and actual Chrome desktop/mobile preview/save/cancel, delivery Retry and
table-confirmation journeys. Existing required checks remain enabled. No claim
of completed native/browser acceptance is made until that exact-head CI passes.

## Remaining B03 work (not closed by this checkpoint)

Staff/family-discount proposal, approval and finalization remains planning in
its retained spec. Physical printer and installed-Windows/offline acceptance,
and broader diagnostic/printing complaints #310/#315, remain separate evidence
requirements. Planning PR #304 and master planning #301 are not feature-complete
because this checkpoint exists. Owner merge/deploy and live verification are
separate from PR readiness.
