# DI Phase 3 — Company product tax profiles and invoice autofill

Depends on the Phase 2 FBR lookup and validation flow and the invoice picker in [#443](https://github.com/jawadrao5555-alt/taxnest/pull/443).

## Deliverables

- [ ] Let authorized company staff save verified defaults per product using the existing product setup flow. Store HS/PCT code, unit, Sale Type, rate and applicable Schedule/SRO and item serial with their validation context.
- [ ] On Create and Edit Invoice, apply a product's saved defaults when it is selected. Recheck them for the invoice date and seller branch. Give the user a visible correction path when the stored combination is unavailable or outdated.
- [ ] Preserve existing product settings, packing conversion and issued invoice line snapshots. A change to a product default applies prospectively. Keep profile operations scoped to the authenticated company.

## Acceptance

- [ ] Verify create, edit, repeat selection, date and branch changes, and a historical reprint using fictional products. Include two companies and attempts to access or apply a profile belonging to the other company.
- [ ] Verify existing products without saved profiles still work. Verify profile edits through the actual browser forms and test validation failures without sending an invoice.

**Status:** Planning draft. Implementation and acceptance are pending. IRIS Annex-C verification remains tracked in [#442](https://github.com/jawadrao5555-alt/taxnest/pull/442).
