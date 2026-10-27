# Admin bulk consolidation — 27 October 2026

Owner requested one separate Admin bulk for these eight PRs on deployed #303, `a7875f3cb27f1f73b7dc9ea0ebd01c3e2dc43de1`. All pinned source heads/history are retained; original PRs remain open as references.

| Source | Pinned head | Scope |
|---|---|---|
| #294 | `1d6fb6995ed1506d3bb8c6243b69a28c7ffe286a` | Shared themes and readable notices |
| #291 | `a12d5bed254cc4c339df88b2f269ba330ed0cb23` | Company/package compatibility |
| #292 | `6ea722ad16d1c1caae9163fd8e71d6c0d7cd1879` | Real staff activity and read-only usage |
| #293 | `f50f8812dd3c1076b04d79a849658d980cb58a5a` | Failed admin login limits |
| #232 | `46acb4779c9ed5537b2cfd806fecc6028d4bba48` | Company directory accessibility |
| #231 | `640ce9b8adb30d003288e591be49b23dad1266ba` | Subscription list regression coverage |
| #230 | `bd045c976b6a4cb8a90b57c32a037a6c7c59b6b0` | Suggestions identity regression coverage |
| #229 | `b3bc407cd774326b6ee20cfe6c5a3cb25ff86b7c` | Live Ops readability |

## Integration and behavior

- Combine six saved admin palettes, opaque semantic notices, native company directory filter labels/focus, and Live Ops control contrast. Preserve semantic directory/ledger colours, permissions, owner approval commands and existing theme choice.
- Reject cross-product/business package assignment under the company lock before replacing a subscription; preserve supported aliases and same-product annual pricing.
- Usage Monitoring reads real staff/session evidence without writing fabricated activity timestamps. Missing/unavailable counters are distinct from zero and shop sales volume is distinct from TaxNest income.
- Limit failed admin passwords by normalized identity/IP and rotating-identity IP budget, preserve sessions/intended redirects and remember-me, flash email only. Configured persistent/shared cache is a deployment prerequisite.
- #230 and #231 are behavioral regression locks on existing suggestions identity/filter and subscription empty/pagination behavior, not confirmed product-bug fixes or new billing features.
- Current main's PRA #303 code, browser flows and mandatory checks are retained. Conflicting CSS blocks are combined; no blanket replacement of shared Admin styling.
- Combined PHP order reproduced a pre-existing request-static SaleCampaign memo leaking a prior test's 25% sale into fresh pricing fixtures: 14 failures, while those18 pricing tests passed independently. Clear that per-request memo in shared TestCase setup, alongside existing static schema/entitlement resets. Production pricing is unchanged and original campaign/annual assertions remain strict.

## Scope boundaries

This is partial implementation of #295/#296. Two-step authentication, Accounts/receipts/ledger, company timeline,72-hour trial,grace lifecycle,promotions,recipes and AI work in master #301 remain separate. No historical activity can be reconstructed from previously invented cached timestamps. No company configuration, subscription/financial history, price, notification read state or POS design is migrated.

## Local evidence

PHP8.4.27, Node24, actual Chromium151, disposable loopback MariaDB10.11 TCP:
- Combined targeted PHP suite **121 tests /566 assertions pass** after the test-isolation correction; the original failing ordered pricing path is retained.
- Locked Vite build, JS/YAML syntax and whitespace checks pass.
- Actual directory and read-only Live Ops journeys pass desktop1366/mobile390 across6 palettes ×2 mode classes, AA4.5:1 contrast, filter labels/keyboard focus/action hit targets and existing GET filtering/empty states. Local fixture substitutes locked compiled CSS/Alpine for blocked external CDN assets; no unexpected page/HTTP/console failure suppressed.
- Broader local dashboard invoice-PDF journey reaches valid actual PDF responses but times out waiting for native preview-print readiness in local Headless Shell. Full Chrome CI remains required; its strict PDF/CSP/download/isolation assertions are unchanged. No broad browser pass claimed.

Keep DRAFT until the combined head's own ALL8 checks including validate, full PHP, exact native MariaDB10.6, actual desktop390 browser,Node22,locked audit/build,workflow/release proof and current clean mergeability pass and review is complete. Source greens are historical, not combined acceptance.

No production access, approval, merge, deploy, relay dispatch or Live Ops command performed. One ordinary Admin announcement replaces overlapping source announcements.
