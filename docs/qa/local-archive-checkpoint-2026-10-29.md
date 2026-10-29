# PR312 optional local-bill archive checkpoint — 29 October 2026

**Not a completed feature, deployment approval or acceptance claim.**

Published parent: e0993a4ff2702fbcf8946b3231958822df1fc3c5, tree875a1771b21863ed39707fdbb2995b1d0ecbc6bb.
CI40840527646 was 6/8: six lanes pass; full PHP failed three FbrPosPendingDeliveriesPanelTest promotion cases, so validate failed.

## Verified fixture correction in this commit

The synthetic minimal FBR transaction table omitted fbr_invoice_number, which is a foundational real FBR schema column. The new absent-fiscal-number SQL guard therefore matched no rows in that SQLite fixture. Reproduced all three failures locally. Add the real nullable fiscal identity column; preserve all existing assertions and the runtime guard. Add a test that an existing fiscal number rejects promotion with409 while preserving every stored field. Local group6 tests/30 assertions PASS.

## Local archive implementation — NOT published in this checkpoint

Work was written in disposable workspace /workspace/scratch/86a8512e2dc4/taxnest-312 but its environment became unavailable before final recovery/inspection, full testing and publication. Recover the files if present; otherwise reimplement from the contract and this checkpoint. Do not assume these runtime files exist in the published PR.

Prototype files:
- database/migrations/2026_12_20_090000_create_optional_local_bill_archive.php
- config/local_bill_archive.php
- app/Services/OptionalLocalBillArchive.php
- app/Http/Controllers/OptionalLocalBillArchiveController.php
- app/Console/Commands/LocalBillArchiveCleanup.php
- app/Console/Commands/LocalBillArchiveRestoreQuarantine.php
- tests/Feature/OptionalLocalBillArchiveTest.php
- shared/pos/fbr-pos/saas-admin optional-local-bill-archive Blade views
- local edits in routes/web.php, routes/console.php, both Customize views, admin dashboard-detail view, PRA/FBR controllers and PosMonthlyBillQuotaPathsTest.php.

Prototype design, requiring review:
- Four separate tables: support_bill_archive_prefs (absent/OFF by default), immutable consent events, encrypted optional snapshots and access audit. No foreign-key cascade into financial records.
- Real company owner only; deny managers and authenticated SaaS-admin impersonation from opt-in. Consent explicitly chooses retention1–365 days and a future consent expiry within a year; policy version/wording/actor/time recorded. Suggested duration UX still needs actual acceptance.
- Capture only eligible completed local/no-fiscal/non-return bills, under the existing deletion transaction and bill/prefs locks. Exclude pending/failed/uncertain fiscal-pipeline rows. Include safe allow-listed monetary header/item facts, exclude customer names/phone/address/private notes/raw fiscal payloads. No retroactive copying.
- Prospective encrypted/compressed snapshot with integrity hash; per-consent random AES256-GCM key encrypted with APP_KEY and kept in a private0700/0600 keyring separate from SQL backups. Payload cap1MiB. Capture/storage/key failure must roll back deletion/stock/quota effects.
- Stop future retention preserves existing copies to their original expiry. Purge destroys every company consent key before optional ciphertext deletion; SQL-only restore cannot decrypt purged copies with the current keyring. No claim of instant removal from backups.
- Expired copies excluded immediately from reads and live sales totals; bounded idempotent cleanup scheduled separately. Owner and super-admin exports explicitly company/product/branch scoped and access logged; JSON support copy never a new fiscal invoice.
- Full SQL+old-keyring restore can resurrect keys unless restoration is quarantined. Prototype includes an explicit confirmed local-support-copies:restore-quarantine command to disable consent/destroy optional keys and discard optional snapshots before restored storage is exposed. **Actual backup rotation is unknown. Operator restore integration, current-keyring preservation and restore acceptance remain unresolved; never promise automatic suppression for an arbitrary whole-volume restore.**
- Capture hooks were locally added to PRA per-bill delete/provisional delete/clear archived/day-close and FBR provisional delete/day-close. Re-audit all eligible deletion paths and concurrency before acceptance.

## Evidence before environment loss

- Optional archive service/controller/shared-form tests:10 tests/52 assertions passed as part of combined16 tests/82 assertions with the FBR fixture group.
- Expanded optional archive + PRA monthly quota/delete integration + FBR panel group:51 tests/234 assertions PASS.
- Tests cover defaultOFF/prospective consent, encrypted minimized payload, idempotency, deletion rollback, wrong tenant/fiscal/return denial, stop vs purge, expiry/bounded cleanup, SQL-restore key destruction, owner/admin-impersonation denial and audited company-scoped exports.
- These are local PHP8.4/SQLite results, not full/browser/native-MariaDB/installed-device acceptance. Do not extrapolate them into complete stock/quota/financial or restore safety.
- An attempted next edit would add real owner PRA/FBR1366/390 browser journeys and synthetic seeded bills for consent→delete→export→stop→purge. The exec transport disconnected; **execution of that final edit could not be established**. Inspect files before assuming it applied.
- Latest local service/controller PHP syntax checks passed before later edits; final syntax/build/diff/full suite must be rerun.

## Required next steps

1. Restore a usable disposable execution environment; recover uncommitted local archive files without overwriting other work.
2. Inspect/refine the prototype, including storage failure, grant/revoke/capture races, bounded cleanup, keyring permissions/symlinks, tax/item/payment snapshot completeness and restore quarantine/operator integration.
3. Complete actual owner/admin desktop390px UI journeys and native MariaDB transaction/concurrency acceptance; verify all deletion hooks and an untouched tenant/settings/history.
4. Publish the complete tested archive implementation on the existing PR branch preserving published history. This checkpoint intentionally publishes only the verified fixture fix and evidence documentation.
5. Run all own latest eight mandatory CI checks including validate, inspect reviews and clean mergeability, then promote and require any promotion-triggered fresh checks. Mark ready only after complete scope acceptance; owner still approves/merges/deploys.
