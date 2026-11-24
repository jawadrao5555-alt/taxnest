# Phase 9: Universal API and PathCare integration

Planning only; runtime implementation and tests pending.

## Scope
Universal authenticated API/webhooks, staged CSV imports, mapping, idempotency, callbacks and reconciliation. PathCare only after authorized documentation; not a dependency for phases 1–8.

## Dependencies
Shared core and authorized external API contract; may proceed independently once interfaces are stable.

## Acceptance
- [ ] Implement scope with one shared laboratory core for hospital and standalone organizations.
- [ ] Verify entitlement, role, tenant and branch isolation using fictional fixtures.
- [ ] Verify financial and clinical integrity, failure paths and replay safety where applicable.
- [ ] Run focused and required regression CI; inspect desktop/mobile UI where applicable.
- [ ] Record exact-head evidence and unresolved external prerequisites.
- [ ] Owner review before merge/deploy. No real patient data or production fiscal submissions.

Issue #344 test-first policy remains applicable. Draft tracking PR is not a reviewable release candidate.
