# TaxNest premium presentation review

## Scope and baseline

- Branch: `replit/taxnest-premium-ui-20260917`.
- Exact starting commit: `4b7c3193f8bc16ca79f358111831a0fbc61faefc`.
- Presentation-only review. This branch does not authorize a merge, deployment,
  auto-merge, or manual GitHub Actions dispatch.
- All screenshots use a disposable synthetic MariaDB browser fixture on port
  5911. No production company is used for verification.

## Visual audit (before implementation)

1. **Admin:** dense dark metric cards give almost every statistic equal weight.
   Company tabs, management tables and audit information have little visual
   separation. Narrow navigation and table behavior need particular attention.
2. **PRA and FBR sale screens:** compact horizontal product rows do not provide
   the requested image-first product browsing experience. Category controls,
   quantity controls, cart totals and payment actions need clearer hierarchy.
3. **Native workspaces:** hotel front desk, service work orders and health
   dashboards use separate headings, cards and tables; they need a consistent
   visual language without changing their distinct functions.
4. **Shared shell:** navigation, actions, forms, empty states, dark mode and
   responsive spacing need one reusable presentation layer.
5. **Constraints:** existing saved themes, grid preferences, wide-cart mode,
   locale behavior, Alpine handlers and permission-driven visibility are
   functional contracts, not redesign opportunities.

## Implementation plan communicated before template changes

1. Capture real before screenshots of admin, PRA billing, FBR retail billing,
   hotel, services and health at desktop and 390px widths.
2. Add scoped shared CSS tokens and reusable presentation classes using the
   existing fonts and TaxNest identity, including dark and reduced-motion modes.
3. Recompose the admin dashboard into readable metric groups and a company
   workspace, with a consistent navigation rail.
4. Give both existing universal sale screens image-led product cards, category
   tiles, a clear cart rail, quantity controls and payment hierarchy. Preserve
   every existing event handler and computation.
5. Integrate the shared shell and native-workspace components across hotel,
   restaurant, retail, services and health, retaining existing routes and data.
6. Check protected-file boundaries, run presentation contracts, verify actual
   browser interactions and responsive screenshots, and submit a separate PR.

## Review guides

- [Acceptance and evidence](ACCEPTANCE.md)
- `scripts/premium-ui-boundary-check.mjs`: compares changed paths and sale
  executable scripts/Alpine contracts against the exact base.
- `tests/Unit/PremiumUiPresentationContractTest.php`: asset, shell, dark mode,
  responsive and reduced-motion contracts.
- `scripts/premium-ui-fixture-extension.php`: guarded synthetic catalog extension.
- `scripts/premium-ui-browser.mjs`: local-only screenshot runner; requires the
  isolated fixture and a supported local Chromium runtime.
- `python scripts/premium-ui-gallery.py`: generates a self-contained offline
  review at `.local/premium-ui-review.html` from the canonical screenshots.

There are no new runtime package, external-font or CSS-CDN dependencies.