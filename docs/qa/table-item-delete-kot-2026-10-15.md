# Table item deletion and duplicate ADD regression

Owner audio/video reports deleting a table item emits cancellation and also
adds dishes again. Priority NestPOS PRA fix; no production access or data edits.

## Reproduction and cause
On main f6fe303a the cashier edit matches remaining order lines with keyBy on
item type/product/name. Repeated dishes and printed/add-on chunks collide.
Only the last row is matchable; retained IDs become new ADD rows while their
original rows remain. Removing the last duplicate produces a valid VOID plus
duplicated retained quantities. Local real-controller/SQLite reproduction:
expected retained IDs [1,2], actual [1,2,4,5]. A legacy no-ID unchanged edit
also grows [1,2] to [1,2,3]. Both regression tests failed before correction.

Use unique order-line IDs and consume each once. Legacy no-ID requests consume
one identity match at a time, preferring matching notes. Missing, foreign or
reused explicit IDs conflict and roll back instead of silently adding a line.
Company/order lock, revision checks, edit UUID replay and existing KOT outbox
remain in the same transaction. No migration or existing-row repair is attempted.

## Compatibility and verification
- Repeated product rows: deleting one preserves exact retained IDs, quantities,
  billing totals and printed stamps; only the removed quantity enters VOID.
- Legacy manual carts with no line IDs: duplicate rows are consumed individually.
- Existing increase/decrease/modifier/no-change and retry/revision behavior stays.
- Cross-company line IDs roll back the whole edit and leave both orders intact.
- Counter and station KOT snapshots and void routing remain unchanged.

Guarded targeted PHP run: 67 tests / 447 assertions PASS with PHP 8.4.26.
Node browser script syntax, PHP fixture syntax and git diff --check PASS.
The source-level failure was retested successfully after correction.

A real-pointer desktop/390px journey claims a synthetic table with three repeated
printed lines, deletes the last, saves, checks exactly two original IDs and
quantity 3/total 300/no ADD action, reloads and reopens the same table to verify
persistence. This supplements PHP VOID-payload assertions. Native/browser/full CI
must pass before deploy readiness. Local AF_UNIX socket creation is denied with
EPERM, blocking the guarded MariaDB/Chrome fixture; no local browser/native or
physical printer verification is claimed. CI uses only disposable fixtures.

Saved tenant/printer/tax settings and older agents are unchanged. Raw customer
media are not committed. Already duplicated live orders require owner review;
this patch must not automatically delete or rewrite historical billing lines.

CI 38755045551: desktop actual deletion/save/reopen passed. Mobile pointer
click failed because .tn-cart-side discount/total controls covered the delete
button. The premium mobile rail constrained height while the footer squeezed
.tn-cart-main; its item content painted underneath the footer. PRA mobile now
uses a single normal-flow cart scroller with non-shrinking item/footer wrappers
and a non-sticky pay block. The real pointer regression is retained unchanged.
Desktop wide-cart and FBR CSS remain scoped outside this correction.
Additional local print permission/zero-loss regressions: 29 tests / 183
assertions PASS. Announcement parse/title checks PASS after correcting its
supported improvement type. Final-HEAD CI still required.
