# Consolidated PRA POS planning — 27 October 2026

Owner requested one separate planning PR combining #280, #281 and #261. This contains specifications only; it does not implement screens, APIs, migrations, renderers or fiscal behavior and is not a feature deployment candidate.

Base: deployed #303, `a7875f3cb27f1f73b7dc9ea0ebd01c3e2dc43de1`.

| Source | Pinned head | Contract |
|---|---|---|
| #280 | `0dabc5ed86473bd9591453ff94f8381ae1330c7c` | KOT: current + eight optional styles |
| #281 | `b345322eb018de43e0767c2a0d8c94ead2c25897` | Receipts: current + eight optional styles |
| #261 | `0df59a0af08fe0ef4cf0b5b01f0265f5482bbe05` | Staff/family discount proposal and owner revision/approval |

## Retained requirements

- KOT: existing/current option plus eight new options = nine. Local and Fiscal independently select the same or different style.
- Receipts: existing/current option plus eight new options = nine; independent Local/Fiscal selections. Preserve enabled logo/QR/footer/paper width and the opt-in typography delivered in #303.
- Existing companies keep their exact saved designs/settings. Only an authorized user's explicit selection and Save applies a change. Missing fields keep legacy fallback; preview/cancel neither persist nor print.
- Preserve quantities, relevant item notes, mixed fulfillment, add-on/reprint semantics, financial snapshots and factual Local/Fiscal identity. No fabricated fiscal reference or verification QR.
- Staff/family discount: cashier proposes 1–99% or a cash amount; owner/admin may revise then approve/reject before finalization. Normal PRA reporting-OFF finals retain NULL fiscal status; reporting-ON follows normal submission only after approval.
- Prevent duplicate/stale approvals, cross-tenant/role access and double discount. Preserve existing bills, numbering, stock, returns and snapshots.

## Source contracts

- [KOT styles](kot-nine-styles-2026-10-25.md)
- [Receipt styles](receipt-nine-styles-2026-10-25.md)
- [Staff/family discount](../specs/staff-family-discount-approval-2026-10-23.md)

#301 remains the broader six-workstream/47-item master tracker. These three specifications do not implement that programme. #278 reports, #279 notification targeting and #273 POS billing decisions/consent archive remain separate scopes; coordinate overlapping status/discount/history requirements rather than claiming they are duplicates. HOLD #237 remains unchanged.

## Acceptance and release boundary

All source heads/history are preserved. Original PRs remain open as references to this consolidated planning record. Future focused implementation PRs must reproduce/retest actual desktop/390px previews and output, legacy/custom settings, two tenants, Local/Fiscal truth, explicit Save, authorized access and financial/concurrency behavior, and pass their own mandatory current-head checks before owner release approval. Specification CI does not prove any feature implemented.

Validation here: source scope and pinned identities checked; Markdown paths and whitespace checked. Application/browser tests are N/A for this documentation-only consolidation. No company setting/data mutation, physical printing, approval, merge or deploy performed.
