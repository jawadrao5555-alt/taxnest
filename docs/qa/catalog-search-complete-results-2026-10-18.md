# Complete PRA POS catalog search

Reported: category shows Deal No 1–16; typing a matching search only shows 1–12. Reproduced locally with the actual `onSearchInput`, token/rank and barcode methods extracted unchanged from universal.blade.php: 12 results instead of 16. A second behavioral reproduction shows an exact barcode after the cutoff losing scanner priority. Existing prefix and any-word fallback behavior passes.

Compatibility before implementation: all matching products/deals/services searchable globally irrespective of selected category; preserve per-company prefix/any-word setting, ranking, non-saleable filtering, exact scanner priority, keyboard navigation and mobile scroll. Do not change products, prices, category, company data, entitlements or saved settings. Render in batches rather than hiding matches or mounting an unbounded catalog in the dropdown.

Node tests exercise actual production methods with synthetic catalog only. Native PHP/Chrome/MariaDB executables unavailable locally. Focused real authenticated desktop/390px browser regression is required in CI; no live company/account access. Owner controls merge and deploy. Evidence below records what was actually run, not a live-company verification.

After fix: five actual-method Node regressions PASS, including all 16 deals and exact barcode beyond the former cap. CI requires these under egress blocking. Added real authenticated sale-screen browser assertions in the existing table-order journey at desktop/390px: synthetic in-memory 16-deal catalog, unrelated selected category, visible/scrollable deals 13–16 and keyboard reachability. No catalog writes. Full exact-HEAD CI/browser execution pending.
