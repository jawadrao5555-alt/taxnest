# Acceptance record

## Current status: PASS — remaining mobile blockers verified

The corrected 390×844 FBR journeys pass in light and genuine dark mode.
PRA's mobile dark catalog, filled cart and payment dialog have also been
recaptured and checked in genuine dark mode. This is evidence for review,
not approval to merge or deploy.

### Measured FBR root cause and fix

The live CART state contained the correct names and quantities. Its first
name span was 198×20px, but the cart list was only 6.59px high (clientHeight 7):
the name started 10px below the list's top and was therefore clipped out.
This was not missing item data, an empty name, or a hidden MENU tab.

`mobile.css` collapsed the fixed payment groups into one column. The FBR
template now uses that stylesheet's existing `grid-cols-2-keep` and
`grid-cols-4-keep` DOM opt-ins. The payment groups retain their columns,
giving the cart a measured 230.59px viewport (clientHeight 231). The first
row/name is visible at scrollTop 0; the remaining rows scroll inside the
list. No fallback names, fiscal changes or cart-state rewriting were added.
The dark quantity-button hover foreground was separately corrected without
lowering the audit's contrast threshold.

### Persistent regression and current evidence

`scripts/premium-ui-mobile-regression.mjs` is the committed real-page journey.
The existing screenshot runner dispatches it with
`RC_PREMIUM_MOBILE_REGRESSION=1` and `RC_PREMIUM_ONLY=retail`; it now fails
closed if the journey is selected but never runs. Set
`RC_PREMIUM_EXPECTED_THEME=light` or `dark` and a non-`before` phase when using
the existing isolated RC browser runner.

- Actual product-card clicks: Mug 450 + Notebook 250 + Gel Pen 80 = **780**.
- Menu → cart → menu → cart; Mug quantity + / − / + produces
  **1,230 → 780 → 1,230**, with matching live Alpine and rendered row values.
- Payment opens and cancels without submitting. All required visible
  name, quantity, amount, header and payment categories pass their audits.
- The real debounced safety backup is checked before reload. FBR intentionally
  opens an **empty cart after reload**; auto-restore remains disabled. The test
  preserves that existing product contract and separately proves mode persistence.
- Body/document/main have no horizontal overflow; primary PAY is fully within
  the phone viewport. Cart scrolling is internal. Vertical scroll metrics are
  retained in the raw reports, not represented as zero overflow everywhere.
- `fbr-mobile-light-regression.json` and `fbr-mobile-dark-regression.json` each
  contain eight passing cart states and seven passing visual audits. Their
  checkpoint screenshots are the canonical light/dark mobile filled/payment files.
- `pra-mobile-dark-verification.json` records passing catalog, filled and payment
  audits. Actual command-palette **Toggle Dark Mode** was used, not Midnight:
  html.dark, computed colorScheme dark, dark browser media and shell luminance
  0.002 were checked. Burger 850 + Cooler 320 + Bowl 690 = **1,860**.
- The three `pra-dark-mobile-{catalog,filled,payment}.png` files are fresh genuine
  dark captures. All 30 required gallery screenshots are present.

Current reports and captures are under `evidence/visual-correction/`.
Superseded captures, failed intermediate diagnostics and older supplemental
FBR catalogs are retained under `superseded/`, not accepted as current evidence.
The older aggregate audit files below are historical; the three reports named
above are the authority for this mobile correction.

Final static checks: **6 PHPUnit tests / 104 assertions**, assertion self-tests,
JavaScript/PHP syntax, exact-base presentation boundary and whitespace checks pass.

No merge, deployment, approval or manual workflow rerun was performed.

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

## Initial verification (historical)

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

Dark mode had CSS/contract coverage but was **not browser-tested** in the initial pass.
The browser checks do not exercise offline service-worker behavior, hardware
printing, real regulator connectivity, every saved theme, or every permission
combination. Those underlying paths are intentionally unchanged.

## Earlier visual-correction verification history

The `evidence/visual-correction/` directory supersedes the initial visual
captures for the admin dashboard and PRA/FBR billing screens.

### Requested matrix and evidence

- Admin dashboard: light and dark at 1440×960, 834×960 and 390×844. The
  dashboard heading and metric ledger are above the fold; selecting the PRA
  company tab is followed by resetting the page/main scroll positions to zero.
- PRA and FBR: the same two modes and three viewports, each with a filled
  cart and an open payment dialog. Mobile catalog captures are supplemental;
  the primary mobile filled-cart captures show the cart, totals and payment
  controls rather than hiding them behind the product tab.
- Modes are actual browser-rendered `html.dark` states, with different computed
  light/dark backgrounds. These checks do not claim preference-save/persistence
  coverage. Admin has a palette picker, not a new binary mode switch.
- `admin-audits.json`, `pra-audits.json` and `fbr-audits.json` retain measured
  browser data, including contrast ratios, disabled state, viewport dimensions,
  overflow and payment-control geometry. Final outcomes must be read from those
  reports, not inferred from the existence of screenshots.

### Synthetic catalog and safe interactions

PRA uses eight realistic named/priced synthetic products and locally stored,
attributed stock food photographs. `fixture-assets/ATTRIBUTION.md` records the
sources. FBR uses eight realistic synthetic retail products and honest monogram
fallbacks: its existing backend still provides no product-photo field. Product
names and test identities retain their Synthetic labels.

- PRA: Garden Burger ×2, Citrus Cooler ×1 and Firecracker Fries ×1:
  **3 lines, 4 quantity, Rs. 2,410**. Decreasing the burger once gives Rs. 1,560;
  increasing it again restores Rs. 2,410.
- FBR: Ceramic Mug ×2, Notebook ×1 and Gel Pen ×1:
  **3 lines, 4 quantity, Rs. 1,230**.
- Empty-cart payment controls were natively disabled. A forced disabled-PAY
  click neither opened payment nor changed route.
- The actual payment dialogs were opened and cancelled. No tender, payment,
  fiscal submission, booking or administrative approval was finalized.

### Assertions and corrections

The reusable `scripts/premium-ui-assertions.mjs` runs inside the real browser.
It composites parsed CSS colors/opacity and supported gradients, requires
4.5:1 for tested text and 3:1 for tested icons, reports unsupported colors as
unresolved, and exempts genuinely disabled controls from the text threshold
while checking native disabled state and deliberate visual treatment.
Conditional Tailwind classes such as `disabled:opacity-30` are not themselves
evidence that an enabled control is disabled.

The audit also checks root/main horizontal overflow and payment visibility.
Interaction evidence checks mobile navigation, cart access and payment-dock
geometry when scrolling. This is a scoped regression check, not a declaration
of WCAG compliance for every page/control/theme combination.

The mobile check requires the whole primary PAY button to be within the viewport
(2px tolerance), not merely intersecting it. It excludes the product-only tab
and an open payment dialog. Phone cart lists scroll internally; the payment
footer remains available, and the floating support widget does not intercept
the cart's payment controls.

The first correction audit identified real low-contrast header shortcuts,
avatars, notification badges and payment controls; those presentation styles
were corrected. Two audit defects were also corrected and covered by
functional self-tests: conditional-disabled class detection and accidentally
including successful records in the failures list. Actual failures are not
waived or hidden.

Run the no-browser helper checks with:

```sh
node scripts/premium-ui-assertions.self-test.mjs
node scripts/premium-ui-boundary-check.mjs
bash scripts/rc-safe-run -- php vendor/bin/phpunit tests/Unit/PremiumUiPresentationContractTest.php
```

Earlier no-browser verification passed: **5 PHPUnit tests / 80 assertions**,
the functional assertion self-tests, JavaScript/PHP syntax checks, the exact-base
presentation boundary check, and `git diff --check`.

The original screenshot runner now invokes the browser assertions on non-baseline
captures and resets scroll position before dashboard evidence. The offline final
gallery is generated by `python scripts/premium-ui-gallery.py`; it refuses to
generate if any of the 30 required new captures is missing.

No backend, route, fiscal, permission, workflow or deployment code was changed
by this correction. No merge, deployment, approval or manual workflow run is
part of this review.