# Smart DI import safeguards — draft PR #188

Depends on PR #186. Latest parent `879a6379` is merged into this branch without
rewriting history. Existing mappings/presets and standard Excel/CSV remain
unchanged. `FEATURE_SMART_DI_IMPORT_SAFEGUARDS` defaults off.

## Compatibility matrix

| Flow | Behavior |
|---|---|
| Standard Excel/CSV, flag off, no smart source | Existing validation, grouping and draft creation |
| Mapped daily source, flag on, explicit source system | Existing mapping/parser then mandatory source review |
| Observed Voyage detailed blocks | Original IDs/dates/outlets and 413 source rows preserved |
| Unknown SKU/outlet/HS/MRP/conversion | Verified company mapping required; whole invoice blocked |
| Packaging / damage / negative values | Packaging needs reviewed exclusion reason; damage/negative remains blocked |
| Another company with same source IDs | Separate mappings and fingerprints |
| Overlapping uploads / concurrent receipt insertion | Unique source fingerprint, atomic draft/audit receipt |
| Interrupted draft creation / retry | Existing same-batch receipt returns immutable invoice; absent groups retry |
| Submitted/locked invoice receipt | Stored status/number shown; import never edits or submits it |

## Workflow

1. Enable only in local disposable QA until owner review and exact-HEAD CI pass.
2. Existing Bulk Import modal: enter source system, e.g. `cci-voyage`; upload.
3. The observed block format is recognized conservatively. Changed columns or
   unrecognized extra worksheets require review. Flat exports use existing
   mapping UI; map original identity, SKU/outlet, line gross/discount/VAT/net
   and repeated invoice gross/discount/VAT/net totals. Missing IDs are blocked
   rather than inventing a fallback.
4. Source review: company administrator saves evidence-backed, append-only SKU
   and buyer mapping versions. No public API or external registration request
   is made. Registered/unregistered status is a human-verified snapshot.
5. Verify missing line financial allocations against evidence. Original cells,
   report net/VAT %, original amounts and allocation evidence remain audited.
   Refresh review after mappings/allocations and packaging exclusion reasons.
6. All rows of each invoice must pass source reconciliation and existing DI
   validation. Lossy two-decimal unit-price conversion blocks review.
7. Explicit approval stores an immutable snapshot/hash. Create/resume drafts.
   Each unique source receipt, complete source audit and draft commit together.
   Deleting a draft cannot release the source identity for repeat import.
8. Existing invoice review and existing DI payload/FBR submit path remain the
   only submission route. This importer makes no FBR request. Source review
   displays existing FBR number/status/latest stored response.

## Validation and current limitations

Local PHP/Composer are absent; ordinary `apt-get update` failed due to container
permissions. Local PHP AST syntax checks, mapping component regression,
`npm ci`, asset build, fixture extraction/reconciliation and diff checks run.
PHP behavioral tests, MariaDB races and authenticated Laravel/Chrome journey
must run in CI/disposable QA; AST checks are not PHP execution.

Sanitized pilot retains 73/413/31 and all invoice footer arithmetic. It must
remain blocked until all 31 SKU mappings, outlet identities/status, MRP/HS,
case conversions and line VAT allocations are verified. No blanket purchase
HS, tax rate, printed MRP or buyer registration assumptions are authorized.

Source profile multi-layout versioning/approval history is still owned by
#186/future format-profile work; this PR versions verified SKU/buyer mappings.
Negative/credit-note posting needs separate existing note workflow, not
conversion to positive sales. No merge, deploy or actual FBR posting occurred.
