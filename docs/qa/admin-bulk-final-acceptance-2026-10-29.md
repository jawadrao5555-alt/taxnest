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

Final immutable-return audit found the PRA provisional wash lacked the return
predicate already present on final-local wash. Original save-policy reproduction
FAILED because the stale local/local credit note became archived. Move the
existing schema-guarded immutable-return predicate into the shared wash base so
both save/delete sets and per-bill actions protect it. Original final-local and
legacy pre-return-column behavior remain unchanged. New actual performDayClose
integration enables owner consent and verifies both save/delete policies, exact
return/items/settings preservation, no return copy, and a decryptable one-item
copy only for the genuinely deleted sale. Final focused day-close/local-series/
archive/quota matrix:142 tests /954 assertions PASS. All six capture callsites
were traced inside actual deletion transactions, with items captured before bulk
deletion. Full current-head CI remains required after this correction.

## Consent renewal and repeatable-read acceptance

A final native regression reproduced a promised-copy loss under continuous active
consent: actual deletion's plain item read fixed an old InnoDB RR view; an
independent owner renewal committed grant2; locked preferences saw grant2 but
plain grant lookup could not see it in the old view. Original actual-controller
native test FAILED with the bill deleted and its new-grant copy missing.

Capture now reads the exact company/id/action grant with FOR UPDATE after the
preference lock. Enabled preferences with missing/inconsistent active grant
metadata fail closed before deletion. OFF/absent preferences and valid expired
consent keep their existing behavior. No global/session production isolation or
company setting changes; the native test alone forces/asserts RR.

The identical real-controller two-connection renewal test now PASSES and requires
a decryptable copy tied to the newly committed 90-day grant. Existing fiscal
callback/delete and stop/capture races remain PASS. Committed socket/loopback
safety checks are unchanged; local execution used the temporary protected TCP
counterpart because Unix socket creation is unavailable here. Feature archive
matrix27 tests/147 assertions PASS including nine inconsistent-enabled-grant
rollback cases. Final focused archive/day-close/local-series/quota matrix151
tests/981 assertions PASS. PHP syntax, scoped whitespace and focused code review
PASS. The new source head still requires its own latest eight mandatory CI jobs;
all prior successful implementation/promotion runs are historical after this fix.
