# PR312 full implementation acceptance — 29 October 2026

Status: implementation under verification; draft until exact-head CI and actual
browser/native database acceptance pass. This document supersedes the older
partial-implementation status only for code now present, not readiness evidence.

## Scope now implemented

- Product-specific reports, exact notification targeting and publication checks,
  persisted fiscal identity/disposition labels, and existing financial decision
  guards from the published parent are preserved.
- Payment filters use actual persisted methods, including debit/credit cards,
  QR payments and legacy values; rows/counts/CSV share the same filter.
- Default-OFF owner-only prospective local-bill support copies. Explicit chosen
  retention1–365days and consent expiry within one year; no preselected consent.
  SaaS admin/impersonation/managers cannot opt in. Immutable policy/actor/time.
- Eligible deletion captures compressed authenticated encrypted monetary/item/
  minimized payment facts inside the same transaction. Per-consent private key
  files are APP_KEY wrapped; public disks and symlink ancestors rejected. Missing
  promised key/storage failure aborts deletion and its stock/quota changes.
- PRA per-bill/provisional/archived cleanup/day-close and FBR provisional/day-close
  delete hooks preserve existing permissions and financial semantics. No fiscal
  pipeline/numbered/return copy, no copying of already-deleted or live history.
- Owner company/product/branch-scoped and super-admin read-only audited exports.
  Separate archive never inflates live sales reports, quota or invoice numbering.
- Stop keeps existing copies to original expiry; purge destroys consent keys then
  optional rows. Reads enforce expiry immediately; cleanup bounded/idempotent.
  Read/purge/capture/cleanup serialize on company preference lock. Source bills
  re-read under lock before capture so stale model state is not trusted.
- Restore quarantine command and existing rollback runbook require optional-key/
  copy removal and consent OFF before restored storage is exposed. Actual host
  backup rotation remains unknown; SQL-only and whole-volume limits documented
  honestly in docs/ops/optional-local-bill-archive-restore.md.

## Compatibility and proof matrix

DefaultOFF untouched tenant/settings; explicit prospective grant; reported,
local, reporting-OFF, pending/failed/uncertain fiscal rows and immutable returns;
secondtenant/secondbranch; owner/admin/manager/impersonation; original stored money
and minimized payload; tampering; missingkey rollback; grant/stop/purge/expiry/
cleanup retry; SQL plus restored old keyring followed by quarantine.

- PHP8.4.23 targeted reports/quota/FBR/archive group:70tests596assertions PASS on final targeted rerun. Actual HTTP provisional delete produced one support copy;
  missing key on final delete preserved the bill and original quota accounting.
- npm ci/build PASS (61modules). PHP/Node syntax and explicit-path whitespace
  checks pass. Local Node24 is not the mandatory Node22 lane.
- Full PHP suite started locally; final result to be recorded after completion.
- Local Chrome downloaded but exited SIGSEGV before page creation; Playwright CDN
  archive was unavailable. Actual default CI desktop390px journeys newly exercise
  owner OFF→grant→realprovisionaldelete→JSONexport→stop→purge independently for PRA
  and FBR with dedicated synthetic companies per viewport. A local crash is not
  browser acceptance; current-head CI must execute and pass those flows.
- Native probe uses independent real controller processes and exact blocked worker FOR UPDATE queries and absent completion
  markers while the parent held its transaction for fiscal-callback/delete and stop/capture. Existing socket-lab CI
  safety gates stay intact. Local PHP8.4.23/MariaDB10.11 full migrations and TCP-loopback supplemental probe PASS; after parent commit workers completed with correct fiscal/consent outcomes. This does not claim INNODB_LOCK_WAITS metadata was observed.

No production/customer data/credentials, physical printing, approval, merge,
deploy or relay dispatch. Draft removal is conditional on full acceptance, own
latest eight mandatory jobs including validate, review and clean mergeability.

Additional original failure reproduced: FBR immutable return with stale local/local
columns could be deleted. Regression first FAILED (delete succeeded); eligibility
now requires completed sale/nulllegacy type atomically in delete/promotion/day-close
paths, preserving older schemas without the return-type column. Fiscal guards
remain. Final targeted group result recorded after verification.

Final review correction: regular PRA DELETE previously removed pending/uncertain
unnumbered rows. Original pending regression first FAILED. Locked eligibility now
matches the existing local portal stream (completed local with local/NULL status,
or reporting-OFF PRA/NULL mode with NULL status). Pending/processing/submitting/
submitted-without-number/verification-pending/failed/config-error/unknown states
remain available for reconciliation/retry rather than hard deletion. Existing
local bill stock/quota paths unchanged; monthly quota group36tests180assertions
PASS including eight pipeline/unknown states. English/Urdu/Roman Urdu errors added.

Initial local full run (started before service/test finalization):5680tests,
45447assertions,1error/1failure,28existingdeprecations,7skipped. StaticPHP lacks
GLOB_BRACE (unrelated health test); the archive tamper test used an obsolete
in-memory-only mutation while the service had switched to authoritative DB reads.
Final test corrupts the actual stored ciphertext and passes; do not count the
initial full run as current-head green. Official full CI is still required.

Final affected group133tests1033assertions PASS. Browser journeys also sign in as
an independent synthetic super-admin at each viewport, verify no consent form,
read the exact company-scoped list, download and compare the exact owner export,
and require clean page/console/request/HTTP diagnostics. Owner session/settings
are unchanged; stop/purge testing then continues in its original context.
