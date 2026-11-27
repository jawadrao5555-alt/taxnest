# TaxNest HR & Attendance — Phase 01: Foundation & employee directory

Status: planning scaffold only; no functional implementation or CI acceptance yet.

## Scope
tenant isolation, company setup, employee identities, roles, policy versions.

## Architecture constraints
- Standalone-first, multi-company SaaS; no changes to existing POS, DI, School or Hospital behavior.
- Strict tenant isolation and explicit role-based authorization.
- Future TaxNest integration via versioned adapters; do not infer account authorization from matching CNIC, email or phone.
- Preserve original attendance evidence, consent and payroll audit trail.
- No live biometric, CNIC or salary test data in public repository.
- Respect applicable labour law and OS location permissions.

## Acceptance checklist
- [ ] Detailed schema/API design reviewed
- [ ] Implementation committed
- [ ] Tenant isolation and negative security tests
- [ ] Unit/feature tests and CI green
- [ ] Cross-product regression review
- [ ] Internal fictional company acceptance
- [ ] Owner review before Ready for Review

## Dependencies
Phase 01 establishes common contracts. Subsequent phases are independent branches from main and must rebase on approved foundation before merging. No production deployment from this draft.
