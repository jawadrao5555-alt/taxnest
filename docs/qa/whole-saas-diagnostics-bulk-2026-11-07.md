# Whole-SaaS diagnostics bulk — P1

Base: 363a0d6823eae113336a6cd6069f160fc7a1a441 (merged #362). Priority: P1, owner requested 7 November 2026.

This is an implementation workstream opened as a Draft. Initial content records source reconciliation and acceptance; runtime implementation and acceptance are not complete. No approval-ready or deployed claim follows from documentation CI.

Source trackers: #337 / #319. Delivery order: second batch after billing; may be developed independently where paths do not overlap.

## Current source evidence

- Reporting/DiagnosticCoverage declares partial capabilities and explicit missing operations for DI connection, FBR POS operations, Healthcare, ERP and shared/admin families. It correctly does not claim tenant health from route inventory.
- #327/#349 already supply source reconciliation, retained print attempts and pairing-bound offline Agent evidence. Reuse these paths and preserve unknown/uncertain states.
- PraAgentDiagnosticEvidence and LiveOpsDiagnosticsService are existing integration points. No new unrestricted SQL, shell or parallel authentication surface is authorized.

## Implementation acceptance

- [ ] Build a versioned adapter registry using existing scoped report infrastructure; register actual supported product/source operations and explicit unavailable gaps.
- [ ] Report earliest/latest event and receipt time, retention window, sequence gaps, clock skew, upload failure, capacity and completeness per source.
- [ ] Extend durable bounded collection to remaining applicable streams; seven-day completeness requires observed source evidence, not just a retention setting.
- [ ] Correlate bill/order/KOT/print/payment/stock/fiscal attempts with real identifiers; preserve deleted/archived source uncertainty.
- [ ] Provide company/product/branch/business-day typed read-only reports with pagination and bounded exports.
- [ ] Distinguish disabled, no activity, stale, unknown and failed; a healthy state requires fresh applicable positive evidence.
- [ ] Show actionable failed checks/jobs and exact release evidence without automatic replay, customer messages, settings changes or deployment.
- [ ] Test all adapters, second tenant, revoked scope, future/incompatible schema, offline restart/gaps, retention expiry, bounded load and report failure isolation.

## Verification and preservation

Synthetic seven-day timelines prove algorithms only. Installed-device collection, physical printing and field soak remain separate evidence. Collection failure must not alter checkout, fiscal queues or printing. Required hosted PHP/native/browser/Agent gates must verify final HEAD. Initial source inspection complete; runtime implementation and acceptance pending.
