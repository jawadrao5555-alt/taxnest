# POS receipt, sharing, numbering and public menu follow-ups

This draft combines the four confirmed POS requests from 21 November 2026. The source issues retain detailed acceptance. Application implementation and end-to-end verification are pending.

| Source | Delivery and acceptance |
| --- | --- |
| [#424](https://github.com/jawadrao5555-alt/taxnest/issues/424) | Offer Full Bill and Customer Name + Grand Total on the manual WhatsApp action, including Reprint. The short message uses Walk-in Customer when no name is saved. Verify both formats and recipient handling on finalized fictional bills. |
| [#425](https://github.com/jawadrao5555-alt/taxnest/issues/425) | Reuse the nine Receipt and nine KOT designs from #324 with separate Local and PRA choices. Add confirmed receipt-field and dotted-separator visibility, compact payment beside the fiscal QR and consistent preview, print and shared-receipt output. Reconcile duplicate and obsolete Receipt Settings controls while preserving stored choices and supported printer behavior. |
| [#426](https://github.com/jawadrao5555-alt/taxnest/issues/426) | Issue new Local serial and daily-display numbers with B in place of L, preserving the numeric suffix format and sequence behavior. Verify legacy L references, concurrent issuance, rollback, preview and reprint. PRA and token-only numbering retain their own rules. |
| [#427](https://github.com/jawadrao5555-alt/taxnest/issues/427) | Add bulk selection and Select All to the Public QR Menu editor, with clear filtered selection scope. Add optional labeled WhatsApp, menu-card and web links on the same QR destination. Verify validation, mobile display, tenant isolation and existing QR continuity. |

Review each implementation checkpoint independently inside this draft. Use fictional tenants and prototype data, and run the required CI before release review. Keep source issues open until their implementation is verified after deployment.

Dual POS sessions and the offline Agent/Waiter work are tracked in [#428](https://github.com/jawadrao5555-alt/taxnest/pull/428).
