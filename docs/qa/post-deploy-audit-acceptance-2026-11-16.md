# Post-deploy category audit acceptance register

Base: `cccb857ab71d0f9814b1b1bae5dc5cb29c04e207` (main fetched 16 November).
Scope source: owner consolidated attachment and [read-only audit](https://chatgpt.com/space/page_b7504e450e9c8191b3cb15bad326b2b6).
This branch excludes unmerged #394. All fixtures must be fictional; no production writes.

Statuses below are implementation inventory, NOT final acceptance. No old green run establishes this HEAD's readiness.

| ID | Requirement | Baseline / current disposition | Required acceptance |
|---|---|---|---|
| A01 | Separate School/Hospital products, nav, permissions, terminology | Main has independent company/category guards and separate ledgers; shared UI wording under correction | Cross-category/campus/company negative cases, desktop/mobile |
| A02 | Admin dashboard remove company local collections block | Implementing; retain Company Usage/detail reporting | Dashboard exclusion + authorized detail preservation |
| A03 | School rates and payment reference | Implementing additive fields, immutable historical entries | Rates future-only; bank/card reference replay/conflict; preservation |
| A04 | School multi-head clarity, student accounts and history search/filter/paging | Implementing | Older outstanding discovery, filters, duplicate-safe collection |
| A05 | School dashboard totals/outstanding/campus/setup/help | Implementing | Total vs cash/noncash; active scope; actual navigation |
| A06 | School receipts, partial payments, closing | Present on main; rate/reference changes need regression | Frozen receipt/retry IDs, counted cash/variance/close immutability |
| A07 | Hospital services/rates/patient lookup/multiline/partial/reference/history/reprint | Present on main; adding dashboard outstanding/context and verifying synthetic flow | HI search, settled/partial, scoped balance, receipts, closing |
| A08 | PRA feature dependency JavaScript context error | Implementing root callback binding fix | Dependency label renders, no console error, extras unchanged |
| A09 | Company category readonly/admin switch compatibility | Baseline controls present; inspect/test | Nonadmin denial, package/product/cross-category history safety |
| A10 | Admin Usage selector/filter/category control and financial semantics | Implementing | Local principal/receipts/outstanding separate retail/fiscal/platform revenue; unknown close not zero |
| A11 | Package limits/feature text/signup/checkout/public consistency | Inspecting canonical presentation; preserve stored purchased snapshots | New/legacy policy and entitlement regression |
| A12 | Bin restore/permanent deletion lifecycle | Main has retention guard and tests; synthetic acceptance pending | Disposable staging only; historical retention and authorization |
| A13 | Franchise throttle/two-step/password/session/audit/isolation | Implementing identified missing controls | Real auth flows, revoked sessions, cross-franchise denial |
| A14 | Distributor vs franchise model | Existing separate modules; document and verify | Distributor cannot inherit company portal access |
| A15 | Public source/download/update/private distribution plan | Investigating; no visibility change authorized | Dependency inventory, source/history secret scan without publishing secrets, owner action plan |
| A16 | Public fiscal/ERP/printing/Play Protect promises | Correcting category-specific copy | School/Hospital OFF, no universal QR/device/install guarantee |
| A17 | 43-video full review/new narration/captions/previews | Metadata support exists; source media/full frame+audio review not yet supplied/verified | Per-video reviewed release/audience/date/captions + owner preview; no publication |
| A18 | Isolated demos category selection/limited roles/reset/expiry/no dispatch | Inspecting existing sandbox and local-only shortcut | Synthetic routes, isolation, expiry, external side-effect denial; no production shortcut |
| A19 | Desktop/mobile actual pixels/console | Pending final implementation | Exact-HEAD screenshots visually reviewed; no fabricated mobile/device evidence |
| A20 | Native concurrency/preservation/retry/closing | Pending final implementation | Exact-HEAD MariaDB + full PHPUnit + FBR retail/PRA/DI regression |
| A21 | All required CI including dependency audit/Node/Android/Agent | Pending publish | Exact-HEAD result links, failures diagnosed not skipped |

## Separate dependencies / not represented as completed

- #394 real School/Hospital official fiscal activation remains a separate PR and external-authority/sandbox evidence problem. No adapter or ON claim in this audit branch.
- Full School ERP / clinical Hospital HMIS / admissions / medical records are future products, not this standalone billing scope.
- Physical printers/devices, installer signing/reputation, backup restoration, CCI external import and connected reporting-client authorization require actual external evidence; synthetic tests cannot certify them.
- Full narration/frame review of all 43 media items and new finished tutorials must remain pending until actual media is inspected and previews reviewed. Raw CI recordings are not completed tutorials.

## Release boundary

Draft until actionable scope and final required verification are reconciled. Owner alone approves merge, relay and deployment. No live company settings/bills/payments/closing/deletion or repository visibility changes.
