# Smart DI saved format approval history

Issue #185 follow-up on deployed #191. Existing mapping saves overwrite a
preset in place, losing the previous rules and who confirmed them. This change
keeps the existing preset as the current editable configuration and appends
review snapshots on explicit saves when FEATURE_SMART_DI_IMPORT_MAPPING is on.

## Compatibility

| Flow | Behavior |
| --- | --- |
| Existing template/legacy importer, flag off | Existing preset behavior retained; no invented approvals |
| Existing preset, first explicit smart save | Previous rules retained as unapproved historical snapshot, then new approved version |
| New explicit smart save | Approved version 1 with original headers, format, mapping/defaults, approver/time and hash |
| Reordered/normalized headers | Same header fingerprint; recognition retained |
| Additional/removed/relabelled columns or file type | Versioned preset not recommended; direct apply blocked; held file remains for correction |
| Manual edits without explicit save | Existing rules/history unchanged |
| Second tenant | Company-scoped history lookup returns 404 |
| Preset deletion | History survives; no cascade deletion |
| Concurrent saves | Company-row transaction lock serializes version allocation; unique version constraint |

The History button shows paginated original snapshots. Old records are not
retroactively approved. Format approval does not verify SKU/buyer/tax evidence
or create/submit invoices; the separate source-review approval gate remains.
The ORM rejects update/delete of history records; database administrators can
still mutate records and database integrity is not a cryptographic trust claim.

## Limits

This slice records header/file-type profiles for the existing mapped import.
It does not add general multi-sheet data ingestion, detailed-block layout
learning, automatic selection of an old profile version, or negative credit
note posting. Those require separate parsing/adjustment work. Actual CCI SKU,
HS/MRP/conversion, outlet registration and item VAT allocations remain unknown
until independently verified; full pilot acceptance is not claimed.

## Validation

Local PHP 8.4 loopback-egress-guarded Smart DI/legacy/parser tests: 67 tests,
794 assertions, no failures/errors; 28 existing PHPUnit deprecations. Real
controller tests cover old rules retained, two explicit saves, deletion audit,
second tenant, changed header blocking/recovery and flag-off compatibility.
Existing real Blade component regression passes. Authenticated desktop/390px
format approval + History screen has been added to the existing disposable
MariaDB/Chrome CI journey; current-SHA CI is required before owner approval.

Local full-suite attempt is not a passing certification: the first failure is
MadadgarLocalEngineTest::test_answers_common_questions_locally (three common
Roman Urdu prompts declined). It is outside the importer; exact baseline
comparison is recorded in the PR. Full CI on the new SHA remains mandatory.
Asset build and JavaScript syntax/component regression pass.
