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

## Fresh browser failure follow-up

Run 39729547432 on HEAD 6156862e4a8ea625c1efdf61da2b55d1d518e792
passed all six non-browser mandatory lanes, including audit/build, full PHP,
MariaDB and real Electron. Browser job 119661195622 failed the first desktop
phone-filter current/old visibility assertion. It had already confirmed the
real API's three authorized bills, two current rows, one old row and filtered
current count one. The board handler changes the count synchronously and
opens the old section via the existing tn-open-olddel Alpine event. The test
checked old-row visibility before waiting for that DOM expansion.

Wait up to five seconds for the actual matching old row to become visible,
then retain every existing current/old/filter assertion. A missing event,
wrong phone filter or genuinely hidden old row still fails. No runtime product
code, gates or assertions are removed. The mechanism is established from the
handler/Alpine code; fresh real browser CI must confirm this correction rather
than treating the suspected timing cause as customer acceptance.


## Refresh after owner-deployed #238 — 22 October

Owner handoff39742749962 bound approved PR238 HEAD565cdf51aa0ed27d25bc567be09d66e96bc712bf
to squash7101db90b404f9950d15d97aa4cc50da588bb49f. Exact Deploy39742816381
succeeded; job119704281151 logged live SHA/extended NestPOS markers PASS at
07:48:40Z, and callback119704977836 returned succeeded for the same SHA/approval.
This is Actions verification, not broad independent customer screen acceptance.

Merge this deployed main into the existing #236 history. Resolve only the
announcement conflict with this PR's unique phone-search benefit; preserve all
#238 helper/display/read-state regressions and notification browser fixtures.
Agent1.13.21/Electron42.11.3/lock graph7160c23b726d468b39e3137c520e7e70922cef37953827119ee29b6eb9d6fc25
and #227 recording/output/CI contracts remain. No runtime feature changes in
this refresh.

Fresh local checks: actual-method/board/held/address22/22, Agent release and
legacy build display9/9, tutorial2/2; release-chain, locked graphs plus negative
fork integrity, active/proposed fail-closed CI contracts, JS syntax and whitespace
pass. Node24.19.0; PHP and installed Chrome absent, so no new local application
or browser acceptance. Earlier exact-HEAD run39733347959 passed all8 and actual
contact search at1366/390; promotion run39740569434 audit hit an external npm
HTTP503. Neither old run establishes this refreshed HEAD: require its own all8,
actual desktop390 browser and final validate plus clean mergeability. No retry
of the superseded head, audit bypass, approval, merge or deploy.
