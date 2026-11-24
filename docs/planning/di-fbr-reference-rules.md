# DI Phase 2 — FBR reference filtering and validation

Phase 1 is [#443](https://github.com/jawadrao5555-alt/taxnest/pull/443). This phase depends on its picker. Start integration from a base that includes Phase 1. The [published FBR DI API documentation](https://fbr.gov.pk/di-technical-assistance/173967/173970) is the reference for lookup contracts.

## Deliverables

- [ ] Use the invoice date and the selected seller branch's origin province to resolve the Sale Type and applicable rate. Use FBR reference identifiers for lookups while retaining the exact labels required by the submission payload.
- [ ] Show the applicable Schedule/SRO options for the selected rate and the related item serials for that Schedule/SRO and date. Use the HS code and applicable annexure context for the FBR unit lookup.
- [ ] Recheck affected selections when the invoice date, branch, HS code, Sale Type, rate or SRO changes. Explain invalid or unconfirmed selections and check them server-side before sending a new invoice.
- [ ] Reuse the existing FBR integration and workbook search. Cache successful reference results by the full lookup context. Identify an unavailable lookup and preserve work in the draft so the user can retry.

## Acceptance

- [ ] Exercise valid, incompatible and missing combinations on Create and Edit Invoice with fictional company data and faked FBR responses. Cover a changed date, branch, parent selection and a failed lookup.
- [ ] Verify that issued invoice snapshots and existing working integrations keep their recorded values. Confirm official behavior in the authorized sandbox before claiming FBR acceptance.

**Status:** Planning draft. Implementation and acceptance are pending. IRIS Annex-C verification remains tracked in [#442](https://github.com/jawadrao5555-alt/taxnest/pull/442).
