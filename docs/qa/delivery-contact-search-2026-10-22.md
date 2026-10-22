# Delivery contact-number search — refreshed after #235

Owner report: staff need to find held/delivery orders by contact number as well
as existing search terms. On the former main `7de0ae0e897786881a25105f7e639ec173050b87`,
heldWindowList already supported customer_phone and digits-only matching. The
Delivery popup/board contained phones in their text haystacks, but could not
match `03001234567` against fictional saved `0300-123 4567`; their hints omitted
phone. Four actual-popup-method cases failed before the original correction.

## Scope and preserved behavior

Normalize numeric phone-like queries in the pending popup and current/old
Delivery board rows, with at least three digits for normalized matching.
Existing raw text/name/bill/address/rider search and working held-window
normalization remain available. English, Urdu and Roman Urdu hints mention
phone. The release announcement uses ordinary customer language.

Only already-authorized collections are filtered: no endpoint/query/permission,
tenant/company/branch, saved customer/contact, money, tax, fiscal, printer or
cashier configuration changes. Current and historical counters remain separate.
Search does not recall, pay, print, dispatch, delete or edit an order.

Merged deployed main `5a8ac35cc4a670dd77e5322c929d9e4a022ec666` into the existing
cursor branch without conflicts or history rewriting. #235 Agent 1.13.21,
Electron 42.11.3 package/lock/build, release identity test, reviewed graph
`7160c23b726d468b39e3137c520e7e70922cef37953827119ee29b6eb9d6fc25`, immutable-tag
collision guards and #227 recording/output-path/fail-closed CI contracts remain
unchanged. Only the per-release POS announcement is replaced for this issue.

## Verification and acceptance boundary

Local on Node 24.19.0:

- Actual popup/held methods plus board input handler and delivery-address
  regressions: **22/22**. Full/formatted/partial phones, missing phone,
  name/bill/address/rider, mixed name+digits, clearing, current/old counters,
  supplied scoped collection and held table/waiter boundary are covered.
- Release-identity/build-display regression: **9/9**.
- Agent release-chain matrix, all locked dependency graphs and negative
  source-integrity checks pass; reviewed Agent graph remains as above.
- Active/proposed fail-closed workflow contracts and tutorial recording checks
  pass. Browser JS syntax and whitespace checks pass.

Added a real disposable Chrome journey `delivery-contact-search`, run by the
existing full browser suite at desktop 1366px and mobile 390px. It uses isolated
fictional companies and the real authorized routes/UI, fills board and popup
search controls, checks formatted/full/partial phones, existing name/clear
behavior, current/old separation, foreign-company exclusion and stable returned
bill/contact/money values, and saves screenshots. Its historical fixture day is
closed using the existing report model, preserving the real pending-day guard.
Other journeys/assertions, production logic and all eight gates are retained.

This host has loopback sockets and locked root JS dependencies, but no PHP or
installed Chrome; no local app/browser acceptance is claimed. New exact-HEAD
GitHub CI must supply supported PHP 8.4, MariaDB, Node 22, actual desktop/mobile
Chrome and all eight mandatory outcomes, including final validate. Draft until
those pass; a promotion-triggered new same-HEAD run must pass all eight too.
Earlier six non-audit successes on the old base are historical evidence only.

#235 production prerequisites were verified from Actions: exact squash deploy
39726954414, live SHA/NestPOS markers PASS and POST outcome callback success.
Windows build 39726967316 and published v1.13.21 assets succeeded; installed
Windows update discovery/fleet acceptance and Offline Agent root cause remain
separate. No production/customer access, physical print, approval, merge or
deployment was performed by this agent. #237 remains HOLD; other drafts untouched.
