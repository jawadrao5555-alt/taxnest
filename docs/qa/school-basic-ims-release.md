# School Basic + IMS focused release candidate

Base: current main cccb857ab71d0f9814b1b1bae5dc5cb29c04e207. Existing basic student lookup, fee charge, full/partial collection, balance, finalized receipt/reprint and permissions already exist on main and remain unchanged except immutable new School receipt routing.

This PR owns the shared fiscal safety foundation: company/category/route OFF controls, immutable routing schema and read-only recovery/context, and proven IMS callback response classification. School payment-event preparation uses the owner's received-amount rule (10000 full or4000+6000 receipts); private account/charge/student ledger is not a separate fiscal event. Stable local receipt identity is not claimed to be a regulator idempotency key.

**Draft; not yet an externally testable School IMS adapter.** Required category mapping/tax/enrollment, authorized sandbox setup and verified timeout recovery are missing. No network enabled, no actual fiscal acknowledgement writer/QR or silent-print success claimed. Product semantics are owner-specified; official validation is separate. DI extraction/adapter work is deferred, not a release prerequisite.

Shared migration adds three nullable route columns without backfill and preserves columns on rollback. Including Hospital columns makes the dependency additive; it does not activate or change Hospital billing. Historical null/route records remain unchanged. Detailed UI/reports/default-rate work stays preserved in #394/#395, not lost or automatically included here.

Deployment order (owner only): School shared foundation first after scope+exact-head acceptance. Hospital focused PR initially bases on the same latest main and identical shared foundation, but must rebase onto the actual merged School main, remove overlap and rerun exact-head full/native/browser checks before owner release. Do not deploy Hospital-first or assume a prior green SHA covers the rebased branch. No merge/deploy/relay performed.
