# Phase 5: Panels, collection centres and inventory

Planning only; runtime implementation and tests pending.

## Scope
Doctors, lawful referral records, corporate panels, reference labs, collection centres, batch/expiry inventory, purchases and consumption.

## Dependencies
Phase 4 accepted interfaces; rebase on current main before implementation.

## Acceptance
- [ ] Implement scope with one shared laboratory core for hospital and standalone organizations.
- [ ] Verify entitlement, role, tenant and branch isolation using fictional fixtures.
- [ ] Verify financial and clinical integrity, failure paths and replay safety where applicable.
- [ ] Run focused and required regression CI; inspect desktop/mobile UI where applicable.
- [ ] Record exact-head evidence and unresolved external prerequisites.
- [ ] Owner review before merge/deploy. No real patient data or production fiscal submissions.

Issue #344 test-first policy remains applicable. Draft tracking PR is not a reviewable release candidate.
