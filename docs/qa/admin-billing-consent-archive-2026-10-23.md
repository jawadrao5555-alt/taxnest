# Admin billing classification, final decisions and consent archive
Owner-confirmed design — 23 October 2026.

**Specification only; no application implementation or functional acceptance yet.**
Priority: normal routine queue. Do not displace #270, then #272.

## Owner requirement
Admin dashboard currently shows stored invoice_mode=pra even for bills with no
fiscal number. Company owners can use local and fiscal billing, may delete local
data daily, and should explicitly choose whether TaxNest retains an optional
support/dispute archive of those deleted local bills.

Read-only admin display must use actual fiscal identity and final disposition:
- Fiscal number present: **Reported**, show the number.
- No fiscal number, explicitly local-final: **Local Final**.
- Awaiting admin/company policy choice: **Pending Decision**.
- Chosen fiscal reporting, awaiting response: **Reporting Pending**.
- Fiscal attempt failed: **Reporting Failed**.
Do not misrepresent a pending/failed submission as a final local invoice.
An unnumbered-bill Local/Unreported grouping can include these substates, but
must show them and never use that broad grouping as deletion eligibility.
Keep product (PRA POS/FBR POS), actual submission status and configured routing
separate from the user-facing Reported/Local decision.

## Final decision and automatic policy
Reuse existing company day-close/finalization settings and permissions wherever
they already provide the requested behavior; do not create a second policy engine
or reinterpret saved choices.
- Admin-authorized **Report**: queue the allowed fiscal submission once; only a
  positive result/fiscal number establishes Reported. Retry/reconcile uncertain
  results without duplicate regulator submissions.
- **Local Final**: finalize locally; no regulator submission.
- **Delete/Cancel**: apply authorized existing cancellation/deletion semantics.
  Never silently restock a reporting purge or delete a return/credit note.
- A saved automatic company policy can resolve an eligible bill; a manual-review
  policy waits for the authorized admin. No global auto-delete default.
- Already-numbered/submitted invoices cannot become Local/Delete because a later
  setting changed; preserve existing fiscal correction/credit-note restrictions.
- Concurrent owner choice, day close, worker retry and Agent callback require
  atomic state checks/idempotency. A bill that gained fiscal identity while a
  delete/local decision was queued must be protected.
- Reporting OFF finals retain existing persisted invoice_mode/status semantics;
  do not rewrite fiscal-mode columns merely to repair an admin screen label.

## Admin screen
Filters: All / Reported / Local-Unreported, with explicit Pending Decision,
Reporting Pending, Reporting Failed and Local Final subfilters.
Company, product, branch, business-date/date range and cash/card filters must
compose consistently across rows, totals, pagination, preview and CSV.
Counts/amounts match the selected scope; expired/deleted optional archive entries
do not inflate live sales totals. Preserve tenant/product/branch isolation and
the current privileged admin boundaries. Do not expose secrets/raw fiscal payloads.

## Optional archive consent
Company OWNER chooses **Keep a TaxNest copy of deleted local bills for
support/disputes**, default **OFF**. A platform admin cannot silently opt a
company into retention.
- Explicit consent records actor, time, wording/policy version, scope and chosen
  expiry. Duration choices (suggested 30/90/365 days) require final product review.
- At each eligible deletion, only an active consent creates a read-only historical
  snapshot; make archive capture and deletion atomic, with a safe failure outcome.
- No archive is created for old already-deleted records that no longer exist.
  No retroactive blanket copying of live company history on an OFF→ON toggle.
- OFF→ON applies prospectively; ON→OFF offers clear stop-future-retention versus
  purge-existing-optional-copies behavior, with confirmation and visible result.
- Owner sees what copy remains, deletion time and expiry; privileged platform
  archive reads/exports are access-logged, tenant scoped and never re-send to PRA.
- Expiry cleanup uses bounded idempotent batches and indexed company/expiry fields.
  Do not promise instant removal from backups: document actual backup rotation,
  restoration behavior and how revoked/expired records remain suppressed after
  restore. Mandatory fiscal/accounting retention is a separate policy.
- Avoid hidden permanent shadow copies. Separate optional compressed archive
  storage from live billing queries; minimize customer personal data and enforce
  encryption/access controls appropriate to the stored content.
- Existing quota, stock, payments, customer-spend snapshots, numbering high-water
  marks, audit logs and accounting invariants remain intact. Inventory void and
  data cleanup are distinct operations.

## Known source facts, not a completed reproduction
AdminDashboardDetail::row currently returns invoice_mode directly as mode.
AdminDashboardDetail filters mode/fiscal_status as raw strings; the Blade table
renders fiscal_number plus mode. PosLocalBillsController already treats
reporting-OFF finals separately from submission-pipeline rows. PosController
delete/day-close paths preserve fiscal-number restrictions, immutable returns,
quota accounting, optional restocking and customer-spend snapshots. Reuse and
test these contracts before implementation.

## Required acceptance before Ready for Review
Disposable fictional company matrix: reported, explicit local, reporting-OFF
final, pending choice, pending/failed submission, uncertain remote acceptance,
credit note/return, archived/nonarchived, second company and second branch.
Test policy changes, callback-versus-delete race, duplicate actions, manual versus
automatic decisions, existing settings/legacy agents, stock/quota/numbering
invariants and exact filter/CSV totals.
Consent tests cover OFF, opt-in, expiry, actor permission, revoke, purge, storage
failure, cleanup retry, archive export denial and restore-after-revocation.
Actual 1366/390 admin/owner browser journeys and relevant PHP8.4/MariaDB concurrency
acceptance, then latest exact-HEAD eight mandatory checks including own validate
(and any promotion-triggered run) plus clean mergeability.
No real customer data, production credentials, physical print, approval, merge or
deploy from the agent. A docs-only CI result is not implemented feature proof.
