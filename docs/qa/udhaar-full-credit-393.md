# Udhaar Book reconciliation (#328 → #393)

The shipped November 6 delivery ledger, not the parked branch, remains authoritative. New delivery decisions accept the full due amount or zero (full credit); old saved partial amounts are not rewritten. Full credit reduces rider cash liability and keeps company customer receivable. Web portal and Android input reject partial new decisions. Local bills may create this retained ledger too.

Delivery decisions share the bill transaction/lock. Same web delivery replay returns success; changed amount conflicts. Android event retries compare the retained financial decision before accepting duplicate success, including omitted/changed money. Collection requires a same-company company-admin/POS-admin at both route and service; company/account locks serialize balance reductions and request IDs make retries single. Return offsets are idempotent by original account + return ID.

Bill cleanup does not cascade into the already-shipped financial ledger. The shop view renders customer/rider/date/amount and recovery history, not source invoice number/items. SaaS company purge authority and existing archive/retention consent remain unchanged. No production cleanup was performed.

Focused local PHP 8.4 SQLite: RiderAppMarkDeliveredTest, PosRiderPortalDeliveredInvariantTest, PosRiderSettleInvariantTest, PosRiderAutoReturnTest and PosPaymentBucketsTest: 58 tests / 416 assertions passed, including repeated return offset. Covers full received/credit, partial rejection, post-delete collection, tenant/role denial, changed decision, lost-response replay, returns and existing settlement invariants. Native MariaDB concurrent recovery, rendered browser actions and Android compilation/physical device evidence remain pending; SQLite is not concurrency evidence.

No migration or historical rewrite is needed. Rollback code only preserves recorded credits and collections; do not delete the retained ledger or revert financial decisions to cash. A pre-change Android app may still present partial amount input; server now returns the clear full-received/full-credit validation error until updated.
