# PRA POS remaining operations

Implementation kickoff only. No application changes or tests have been completed in this PR.

Source planning: #304 #261 #319

## Required implementation and acceptance
- [ ] Reproduce unresolved checkout/payment, Recall, KOT and receipt issues on current main
- [ ] Implement staff/family discount proposal, revision and owner approval without double-discount
- [ ] Validate printer spool versus physical output and Agent installed-version recovery
- [ ] Reconcile counter cash, refunds and rider settlement without duplicate ledger entries

## Already implemented / excluded
Merged #324, #329, #330, #333, #334; parked #328 and HOLD #237

## Release gates
- [ ] Compare actual main code against each source requirement and remove already-implemented work
- [ ] Add production code and focused regression tests
- [ ] Validate native database, browser/Agent as applicable and all required exact-head CI
- [ ] Obtain explicit owner approval and release through existing relay
- [ ] Verify deployed version and field acceptance separately

Do not merge this planning-only kickoff as a feature.
