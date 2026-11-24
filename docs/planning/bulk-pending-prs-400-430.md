# Consolidation of pending PRs #400–#430

The seven open source PRs in the inclusive range were checked against GitHub. This register is the initial draft and records the full source contracts and pending acceptance. For each requirement, identify the deployed baseline or the implementing commit in this PR and record verification evidence.

| Source PR | Source head at intake | Contract | Production evidence |
| --- | --- | --- | --- |
| #401 | `16445f41f1549b6c9b4b21a04572251651324c8d` | Dashboard, company usage collections, and Agent Health. Reconcile #412/#418 and finish any remaining company, branch, date, totals, and permissions checks. | Pending |
| #402 | `bae51874d3eb8c9352490ba0f41f3706b4e0dbaf` | Live Ops across PRA, FBR POS, DI, School, Hospital, Healthcare and future ERP modules. Reconcile #413, then cover branch isolation, evidence freshness and supported remediation controls. | Pending |
| #403 | `b5a0e8ff41dd2b2cc5e17ac904122e6e86c55c13` | Track each audit follow-up through release evidence. Reconcile reporting, company usage, packages/PRA, franchise, website, tutorials and demos. Keep private source material in its existing restricted location. | Pending |
| #417 | `e536ec10690c15387865f1143ad4a4d2807c18d0` | Complete all restaurant POS, onboarding and Rider outcomes, including the full #321 and #381 source contracts. Test signed install-over and actual phone behavior. | Pending |
| #423 | `c3eccf1ab54affaa853e4993940b700fbf5d934a` | Complete the #264, #374 and #316 contracts for billing periods, renewals, lifecycle, entitlements, accounting and isolated migration against the #422 baseline. | Pending |
| #428 | `6480552baaee8d843a1c7b69be84a15b03e77e82` | Carry dual Agent POS sessions and held-order stream controls. Complete and verify offline authority for both logins, cold start, LAN Waiter edits and recovery, Windows physical printing and duplicate-safe reconnect sync. | Pending |
| #429 | `6ce9a3a2d1e9f24df213c9cf4a476a0238722ce7` | Implement #424–#427 under this source contract. Verify receipt preview, printer, reprint, WhatsApp and shared receipt together with POS/Agent changes. | Pending |

## Source issues carried by #429

- #424: Manual WhatsApp sharing and reprint with Full Bill or Customer Name + Grand Total. Verify walk-in fallback and recipient selection.
- #425: Nine existing receipt and nine KOT designs, with separate Local and PRA settings, optional highlighted receipt fields and dotted separators, and payment beside the fiscal QR. Verify preview, print, reprint and shared receipt against saved settings and supported printers.
- #426: Start new Local bill prefixes with B while preserving sequence format, existing L bill lookup and reprint, and concurrent issuance behavior.
- #427: Public QR Menu product bulk select and Select All, plus optional validated WhatsApp, menu-card and web links. Verify selection scope, existing QR destinations and tenant isolation.

## Integration and closure

- #428 held-order polling and table lookup use the signed-in cashier's billing stream. The polling cache key also follows the stream. Fictional coverage checks Local, PRA, unrestricted and unclassified legacy orders plus the tenant boundary.

- #428 offline sale recovery. A repeated local sale returns its stored receipt only to the original authenticated company, branch, device and cashier. Fictional tests cover a restarted journal, a genuine replay and separate cashier/branch scope. POS 2 independent native authority and physical reconnect remain open.

- #428 desktop reconnect. If a cashier signs in again while an earlier pairing confirmation is still open, the Agent checks the current session when the earlier confirmation finishes. The stale confirmation cannot refresh the primary POS offline scope. Fictional regressions cover both PRA and FBR POS. Physical reconnect and independent POS 2 offline billing remain open.

- #428. The desktop window enforces the POS 1 boundary for offline snapshots and automatic Agent pairing, including when POS 2 is opened with shared options. The fictional desktop regression checks both session partitions. POS 2 still needs independent offline cashier authority and physical reconnect acceptance.

- #423 / #264 checkout, `c0584f84`. The selected billing-cycle PHP block now ends before receipt markup. The [DI browser step](https://github.com/jawadrao5555-alt/taxnest/actions/runs/45006927203/job/137160479530) passed the actual quarterly selection and receipt-form journey at desktop and 390px. Further lifecycle and commercial acceptance follows the billing register.
- #428, same application revision. Six local Node tests across the POS slot, launch UI and offline snapshot test files passed. The slot test verifies distinct Electron session partitions, a slot-specific print bridge, and independent reopen behavior using a window double. The [Windows pairing](https://github.com/jawadrao5555-alt/taxnest/actions/runs/45006927150) and [update recovery](https://github.com/jawadrao5555-alt/taxnest/actions/runs/45006927181) workflows passed. POS 2 independent offline cashier operation and physical device evidence remain open.
- #428 desktop capability notice. Agent settings identify the separate POS 2 login and desktop printing and direct cashiers to POS 1 for Agent Offline Mode and LAN Waiter operations. The preload regression runs the POS 2 bridge with a fictional receipt and checks the exposed methods and IPC calls. POS 2 still needs its own server-issued local cashier authority, distinct from the browser's existing offline queue. Windows acceptance must cover both logins, cold start, print, changed-login recovery and duplicate-safe reconnect.
- #424 manual WhatsApp verification. The fictional receipt regression exercises both message formats, a named and unnamed buyer, the saved recipient, and the reprint share-link request. The two-line reprint message uses the walk-in label when needed and skips the public link request. Full-bill reprint now reports when the browser blocks the WhatsApp window. WhatsApp sending and physical reprint remain device acceptance items.
- #425 per-stream receipt visibility. STRN, customer name, phone and delivery address now have individual settings on the 58mm and 80mm receipt templates. Inherited values retain the legacy rendering on both widths. The synthetic preview includes delivery details, and the render regression checks that Local settings leave PRA details and the fiscal QR intact. Device printing, scan readability and the remaining receipt controls are still open.
- #425 receipt settings cleanup. Primary Local and PRA display settings no longer repeat in Advanced unless a company has a saved override. Existing overrides remain visible and editable, and omitted fields keep their saved values.
- #425 optional POS reference. The 58mm and 80mm receipt templates can omit the supplemental POS number when a confirmed fiscal or calling number remains visible. The sales record, offline fallback, and sole printed receipt identifier stay available.

- #427 bulk menu save. A missing, newly inactive or out-of-company product selection rejects the whole update before removing pins. The fictional stale-picker case keeps the original pin order. Valid replacements are committed together under a company row lock.

1. Reconcile each source against current main. Carry forward scoped code and acceptance criteria, including the complete source registers. Link baseline releases when a requirement already shipped.
2. Review Admin and Live Ops, Billing and Lifecycle, and POS and Agent in independent checkpoints within this PR. Exercise the combined sequence from held order and KOT through payment, numbering, receipt, Day Close and reconnect. Verify billing quotes, payment receipts, access, reversal and legacy terms together.
3. Maintain per-source acceptance evidence for the final reviewed revision. After production deployment, record the deployed SHA and successful verification for each requirement. Include real device or fiscal verification wherever the source contract requires it.
4. Close each of the seven source PRs only after its complete scope is verified in production. Apply the same requirement-level rule to #424–#427 and any inherited source whose existing contract authorizes closure. Leave a link back to this PR and to the deployment evidence. Recheck any source that changed after its listed intake head before closing it.
