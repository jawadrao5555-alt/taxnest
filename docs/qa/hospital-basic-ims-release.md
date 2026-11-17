# Hospital Basic + IMS focused candidate

Latest main base is cccb857ab71d0f9814b1b1bae5dc5cb29c04e207. Basic patient lookup, service charges, full/partial received payments, balances, finalized receipts/reprints and scoped permissions already exist on main. Shared fiscal foundation is identical to the School focused branch at local764841b2; do not deploy this branch before School's reviewed shared foundation.

This branch adds Hospital received-payment preparation (full20000 or8000+12000) and per-finalized-payment immutable routing. New receipts use version2 with company/category/source/invoice binding and their own route (currently OFF); they do not inherit an old internal service bill's route. Version1 inherited records still require exact parent snapshot equality; legacy null remains unverified. Neither is migrated, backfilled or rewritten. Same payment retry returns the original receipt/snapshot. A new payment against a legacy bill is a distinct new event, never permission to submit the historical charge or prior receipts.

**Draft, not yet a testable real IMS integration.** Official Hospital event/schema/tax/enrollment and authorized sandbox/status-recovery evidence remain missing. Local identity is not claimed as regulator idempotency key. No speculative transport, callback persistence, QR or physical-print success is claimed. IMS is primary; DI optional/nonblocking. Private patient/service ledger is not in event output. Draft/view/reprint do not prepare new payment events.

Deployment order: owner approves/merges/deploys School shared foundation first only after its scope and exact-head checks pass. Rebase this Hospital PR onto actual merged School main, drop identical shared overlap, rerun complete exact-head tests and review before owner Hospital deployment. Never treat pre-rebase CI as release evidence. All #394/#395 work is preserved; detailed dashboards/reports/public demo enhancements are separate.

School prerequisite draft PR: https://github.com/jawadrao5555-alt/taxnest/pull/396 (shared remote0d12b06060821b1a61f617d79e987bce7ac06ad0). This is not owner approval or deployment authorization.
