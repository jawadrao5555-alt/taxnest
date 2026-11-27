# Admin and whole-SaaS diagnostics

Implementation kickoff only. No application changes or tests have been completed in this PR.

Source planning: #316 #319 #320 #321

## Required implementation and acceptance
- [ ] Implement missing seven-day evidence coverage with explicit Unknown/Partial states
- [ ] Correlate Day Close, payment/refund/rider evidence across tenant-scoped reads
- [ ] Complete remaining company subscription/payment and admin visibility requirements
- [ ] Apply role/tenant security, safe support reporting and release-state verification

## Already implemented / excluded
Merged #315, #327, #325 and #312

## Release gates
- [ ] Compare actual main code against each source requirement and remove already-implemented work
- [ ] Add production code and focused regression tests
- [ ] Validate native database, browser/Agent as applicable and all required exact-head CI
- [ ] Obtain explicit owner approval and release through existing relay
- [ ] Verify deployed version and field acceptance separately

Do not merge this planning-only kickoff as a feature.
