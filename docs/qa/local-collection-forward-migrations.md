# Local collection migrations are forward-only

The School `2026_11_15_030000` and Hospital `2026_11_15_040000` migrations only add schema. Their `down()` methods intentionally do not drop tables, columns, indexes or data. Existing and newly recorded fee accounts, balances, receipt snapshots, service catalogues/lines, payment references and permanent closing/handover evidence survive a code rollback.

Their `up()` methods detect already-present additions. A migration bookkeeping rollback followed by reapplication does not duplicate tables/indexes, rewrite receipt values, backfill historical balances or reset a catalogue. Compatibility tests populate pre-upgrade history and post-upgrade partial payments, then compare all persisted rows through repeated down/up/up cycles. Original pre-upgrade tenant/campus history comparisons remain intact.

## Operational boundary

This is data preservation, not a guarantee that old application code understands new multi-head accounts, partial balances or payment methods. Prefer a compatible roll-forward fix. If reverting code, separately verify its read/write compatibility with the retained schema and disable incompatible collection writes rather than reinterpret saved records. Do not use dropping these tables as a rollback plan.

Only these two migrations are made forward-only here. A broad rollback/reset may invoke older destructive migrations and is **not** safe merely because these two `down()` methods are no-ops. Any later schema removal/data conversion needs its own reviewed migration, verified backup/restore plan and applicable owner authorization. No production migration or live data change is performed by this patch.
