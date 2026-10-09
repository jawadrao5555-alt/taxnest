# Hotel / Guest House: ten-point re-audit and receipt corrections

## Deployed baseline and reproduction

Reviewed the source of deployed main `be65da1ab6805485e7f3bd8726bd9b4133b1bc99`, including PR168. Its Actions live marker verification passed; that proves deployed identity, not the owner's physical printer or every business operation. No production browser, DB, SSH or credentials were accessed.

Executed the shipped `hotel-bill-preview.js` in a Node VM with DOM/HTTP doubles. Four behavioral regressions failed before corrections and passed afterward: closing/reopening reset the cashier's payment/action; losing a committed confirmation response then reopening generated another UUID; fully prepaid checkout still exposed an amount field; an outstanding preview response reopened an explicitly closed dialog. These tests do not substitute for Laravel or real browser execution.

The compiled production CSS also gives `.grid` / `.block` the same specificity as its earlier hidden reset. Added a Hotel-shell-only `[hidden]` rule so issued receipts really hide desk controls and single-document picker labels. Real Chrome visibility assertions cover this rather than relying solely on the HTML hidden attribute.

## Ten-point matrix

| Owner's point | Review / correction | Verification |
| --- | --- | --- |
| One Bills / Tax Reports screen | Shared Hotel directory retained; no replacement of generic retail/restaurant reports | Existing HTTP filters/stream/branch/outlet tests; Chrome real bill and tax-detail table |
| Check-in / checkout receipt popup | Preserve cashier inputs; reuse original issued invoice after a remaining payment; hide zero-money controls | VM regressions; HTTP original-receipt/payment/paid-checkout test; Chrome partial and fully prepaid stays |
| Explicit Close | Discard late quote responses; prevent Escape on issued standalone receipt too | VM late-response test; Chrome draft/issued Escape and Close |
| Draft Edit / audited Cancel | Preserve fiscal locks; keep correction success/errors in its embedded modal | Existing draft/fiscal HTTP coverage; new actual modal cancellation; Chrome existing editor flow |
| Payment survives editing / reopening | Reopen retains method, action and amount; uncertain confirmation retains its exact payload and UUID | VM retention/retry tests; Chrome loses response after actual disposable server commit then retries |
| Readable desktop / mobile popup | Add explicit dimensions to the check-in form as well as receipt review; honor hidden controls | Chrome 1366px and 390px bounds / visibility |
| Print / Agent acknowledgement | Existing saved routing and attempt UUIDs retained; refresh displayed fiscal receipt only when safe from pending print jobs | Existing real enqueue/dedupe HTTP tests and Chrome print/lost-response/polling scenarios |
| Dashboard Back | Existing Hotel Bills/reports Dashboard targets retained | HTTP Hotel-vs-retail navigation; Chrome actual Dashboard Back |
| Filtered CSV / PDF | Existing calculations and filters retained; verify actual downloads from the filtered Hotel screen | Existing HTTP exports; Chrome invoice filter, downloaded CSV and PDF signature |
| Outlet / returns / money isolation | Preserve signed credit netting, deposits/refunds separation and unique stay balances; status polling now applies the same invoice access checks as receipt rendering | Existing HTTP native outlet/returns/tenant/branch/cashier tests plus authorized vs denied bill-status calls |

## Scope / compatibility

- Hotel and Guest House use the shared native Hotel shell. No tax rates, PRA configuration, company settings, receipt paper preference, printer routing or agent release version are changed.
- Lost-response recovery repeats the original operation; it never silently starts a new payment or bill. A definite 4xx refusal requires an updated preview.
- Already-reported invoices remain immutable. Receiving remaining money keeps the original receipt; paid checkout adds no payment and no second invoice.
- Draft Delete is the existing audited cancellation/correction, not database hard deletion. PRA-issued documents continue to require the established credit-note flow.
- Retail/restaurant markup and browser behavior are unchanged by the scoped visibility rule.

## Execution limits and owner handoff

PHP/Composer and a Chrome executable are unavailable in scratch. Local Node syntax and the four behavioral tests pass. The PR's required isolated PHP/MariaDB/Chrome workflows provide full execution evidence; see the PR body for exact-head results once complete.

Physical paper output and actual external PRA acceptance require the owner's live verification after owner approval and relay. Do not call this PR live before its deployment's `CI LIVE VERIFY: PASS`. Owner requested this Hotel PR before PR170/171; those verification PRs remain on hold and will need current-main alignment before their eventual approvals.
