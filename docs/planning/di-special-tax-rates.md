# DI Phase 4 — Verified per-unit and mixed-rate invoice handling

Builds on the Phase 2 rules and Phase 3 product profiles. [#443](https://github.com/jawadrao5555-alt/taxnest/pull/443) displays mixed rates and other unsupported values as workbook references.

## Deliverables

- [ ] Inventory the workbook's currently reference-only rates, sale types and units. For each, record the official tax treatment and DI API representation, or record the specific unresolved requirement.
- [ ] Implement verified per-unit and percentage-plus-fixed-amount calculations using the existing invoice calculation service. Apply the confirmed monetary base, reporting quantity and unit conversion, and reconcile rounding across the draft, backend, PDF and FBR payload.
- [ ] Allow only verified and implemented special combinations to populate calculate-and-submit fields. Preserve issued invoice snapshots, standard percentage calculations, and the existing path for unsupported entries.

## Acceptance

- [ ] Use fictional examples for a percentage rate, a fixed amount per unit, a mixed rate, decimal quantity and packing conversion. Reconcile draft totals, stored values, PDF and exact payload fields.
- [ ] Confirm applicable FBR sandbox scenarios with authorized evidence. Keep credentials, invoice identifiers and live taxpayer evidence private.

**Status:** Planning draft. Implementation and acceptance are pending. IRIS Annex-C verification remains tracked in [#442](https://github.com/jawadrao5555-alt/taxnest/pull/442).
