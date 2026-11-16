# Retained FBR refund-event diagnostics

This read-only source is deliberately narrower than general offline-event coverage.
The production `AgentCoreProjectorRegistry` accepts `customer.refund.posted` with
schema `local-core.customer-ledger.v1` and command `refund.record`. Its ledger
projector records `return_transaction_id` and a refund aggregate mapping with
cloud type `fbr_pos_transaction`. Direct-projector unit-test aliases
`sale.refunded` and `stock.restored` are not registered ingestion contracts and
are not accepted by this adapter. PRA sale projection is never mapped into FBR.

Registry 15 adds `fbrpos.projected_refund_events`; bill evidence version 8 adds
`projected_refund_events`. The exact retained chain requires projected status,
canonical integer result ID, exact event company/branch scope, same-tenant return,
its same-campus original sale, and the exact opaque refund aggregate mapping.
MySQL numeric coercion and case-insensitive aggregate aliases cannot create links.
No invoice-number, amount, timestamp, name, UUID or cross-product ID heuristic is
used. Rows contain only immutable event IDs and source timestamps. No payload,
amount, customer, actor, device credential or raw error is returned.

Missing schema is UNAVAILABLE. Missing/deleted/forged retained chain is
INCONSISTENT_RETAINED_LINKS, never inferred zero. Empty retained evidence is
NO_RETAINED_EVENTS/NO_RETAINED_LINKS, not proof no offline work occurred. Source
windows, explicit campus filters and immutable ID cursors support late arrivals;
re-reading is required, not a completeness claim. General/unuploaded/unattributed
offline events, physical printing and regulator acceptance remain unknown.

Synthetic tests cover exact chain, PRA/FBR equal numeric IDs, tenant/campus
forgery, unsupported schema/commands/status, deleted parent/return/mapping,
coercion/case aliases, source routing, date windows and late-ID cursor. Native
MariaDB execution is separately required; SQLite proof does not certify its JSON
or collation semantics. No enrollment, dispatch, real refunds or live data changed.
