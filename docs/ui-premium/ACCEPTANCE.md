# Acceptance record

## Environment and safety

Only the isolated synthetic RC browser application on `localhost:5911` is used.
Runtime credentials remain outside the repository. The existing safe runner
continues to block external application connections. No real payment, regulator
submission, clinical record or production mutation is part of this review.

## Before evidence

The `evidence/` directory contains desktop (1440×960) and mobile (390×844)
screenshots for:

| Surface | Filename stem |
| --- | --- |
| Admin dashboard, PRA company tab | `before-admin-dashboard` |
| PRA restaurant billing | `before-pra-invoice-create` |
| FBR retail billing | `before-fbr-create` |
| Hotel front desk | `before-hotel-front-desk` |
| Service work orders | `before-service-work-orders` |
| Health dashboard | `before-health-dashboard` |

The initial FBR capture encountered a service-worker recovery page. It was
replaced with the actual billing screen captured in a clean browser context
with service workers blocked; no service-worker application code was changed.
The PRA baseline shows the real grid-arrangement mode, with “Done” visible.
It is not a like-for-like interaction-state comparison with the normal
billing mode used for acceptance. Product layout comparisons should account
for that difference.

The admin capture selects the synthetic PRA-company tab. Service work orders
and health legitimately contain empty or zero-valued synthetic data; no fake
revenue, trends or charts have been added.

## Image-source boundary

PRA renders the existing product image supplied by its data source. The current
FBR product serializer supplies `image: null`, and its catalog has no image
column. This presentation-only branch does not change that backend contract:
FBR cards (and other products without an image) show a monogram fallback.
Actual FBR product-photo upload/storage is not implemented in this branch.

The initial FBR after pass found an empty catalog because the synthetic fixture
extension populated the PRA table, not the distinct FBR product table. Only the
guarded synthetic extension was corrected. The before FBR catalog and corrected
after catalog are therefore different fixture states, not a count changed by
the UI redesign.

## Final verification

- `bash scripts/rc-safe-run -- php vendor/bin/phpunit tests/Unit/PremiumUiPresentationContractTest.php`:
  **PASS — 4 tests, 57 assertions**, on the disposable runtime.
- `node scripts/premium-ui-boundary-check.mjs`: **PASS**, against the exact base.
  Protected application/database/routes/configuration/workflow/deployment and
  service-worker paths are unchanged. Executable sale scripts and the checked
  Alpine event/model/ref contracts match the base.
- Browser/fixture script syntax checks and `git diff --check`: **PASS**.
- Admin: authenticated administrator identity and exact dashboard URL checked.
  PRA company tab checked to contain synthetic rows only. Desktop/tablet/mobile
  captures use the updated metric and action contrast.
- PRA: all eight synthetic product cards, normal (non-arrangement) mode, product
  add, quantity increase to 2/Rs.1,700 and decrease to 1/Rs.850, category filter,
  unmatched search/empty state, and payment dialog open/cancel **PASS**.
  Final display captures show an empty cart and correctly disabled payment
  actions; prior nonempty-cart interactions were not submitted.
- FBR: fixed synthetic catalog contains eight products. Adding the Ceramic Mug,
  quantity 2/Rs.860, and payment dialog open/cancel **PASS**. The credit option
  correctly refused to proceed without a customer. The cart was not finalized.
  Mobile cart access and navigation drawer open/close **PASS**. Measured document
  widths were 1440/1440, 834/834 and 390/390 (scroll/client width), without page
  overflow. The final desktop capture confirms a white grand total on the dark
  summary band; prior responsive captures predate this final shared contrast fix.
- Hotel, service work orders and health: correct authenticated native routes
  verified; desktop/tablet/mobile screenshots retained. These are visual route
  checks, not end-to-end booking, clinical or work-order mutation tests.

No fiscal transaction, payment, booking or clinical record was submitted.
Browser warnings included the existing Tailwind CDN production warning and
password/form-semantics warnings. Service-worker registration was intentionally
blocked in the test browser. Old browser tabs briefly reported connection
refusals during the fixture restart; these are not the final route results.

Dark mode has CSS/contract coverage but was **not browser-tested** in this pass.
The browser checks do not exercise offline service-worker behavior, hardware
printing, real regulator connectivity, every saved theme, or every permission
combination. Those underlying paths are intentionally unchanged.