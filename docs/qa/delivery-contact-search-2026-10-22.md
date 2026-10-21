# Delivery contact-number search

Owner voice report: delivery orders are commonly held, and staff need to find
them by contact number as well as the existing search terms.

## Reproduced gap

On main `7de0ae0e897786881a25105f7e639ec173050b87`, heldWindowList already
supports customer_phone and digits-only matching. Delivery popup and board also
contain customer_phone in their text haystacks, but their hint omits phone and
their substring comparisons cannot match `03001234567` against a fictional
saved contact `0300-123 4567`. Four popup regression cases failed before the fix.
This is not evidence that all contact search was absent.

## Change and preservation

- Mention phone in the English/Urdu Delivery search hint.
- Normalize phone formatting for numeric phone-like queries in the pending
  popup and current/old Delivery board rows; require at least three digits.
- Keep text search and the existing held-window implementation unchanged.
- Search only the supplied, already-authorized collections; no new endpoint,
  wider query, tenant/branch permission or saved-setting change.
- Keep current versus old delivery counters separate. Search never recalls,
  pays, prints, dispatches, deletes or writes an order.
- Add the focused regression file to the existing egress-blocked Node CI step.
  Existing tutorial recording/output-path gates remain intact.

## Verification

`node --test scripts/tests/delivery-phone-search.test.mjs scripts/tests/delivery-address-state.test.mjs`
passed **22/22**. These execute the actual popup/held methods and board event
handler with fictional records: full/formatted/partial contacts, missing phones,
mixed name+digits, existing text searches, clear-search recovery, current/old
counters, and exclusion of orders outside the supplied visible collection.
They do not replace server-side tenancy integration or real browser acceptance.

`NODE_PATH=../taxnest-tutorial-227/node_modules bash scripts/tests/rc-ci-gates-check.sh`
passed active/proposed fail-closed workflow contracts (locked js-yaml from the
existing worktree). `git diff --check` passed.

## Acceptance still pending

Fresh exact-HEAD mandatory CI and authenticated desktop/390px mobile browser
verification of these search interactions remain pending. This host cannot
start Xvfb listening sockets; no screenshot or live acceptance is claimed.
The known shared Electron 41.7.1 audit advisory blocking Agent release #235
may also block this main-based PR's dependency audit. It is not bypassed here.
This PR stays draft until gates and required acceptance pass. No production
access, real customer data, real print, merge or deployment was performed.
