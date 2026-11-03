# FBR POS and Digital Invoice remaining integrity

Implementation kickoff only. No application changes or tests have been completed in this PR.

Source planning: #313 #318 #264 #266

## Required implementation and acceptance
- [ ] Complete DI billing cycle rules and renewal/receipt tests
- [ ] Implement compatible Smart UOM and packing conversion with frozen invoice snapshots
- [ ] Reconcile FBR POS returns, discounts, fiscal payload and pharmacy source evidence
- [ ] Verify idempotent reporting and cross-tenant isolation without live fiscal submissions

## Already implemented / excluded
Merged #331 and #335; HOLD #228 evidence

## Release gates
- [ ] Compare actual main code against each source requirement and remove already-implemented work
- [ ] Add production code and focused regression tests
- [ ] Validate native database, browser/Agent as applicable and all required exact-head CI
- [ ] Obtain explicit owner approval and release through existing relay
- [ ] Verify deployed version and field acceptance separately

Do not merge this planning-only kickoff as a feature.
