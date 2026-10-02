# Notification reliability — implementation checkpoint

Base: 063859f56343fca4a0226086b6693710e3decc8e. Separate from draft PR144.

## Source reproduction
Both layouts count every unread notification, but render take(1) and mark only its ID on dismissal. Refresh therefore advances through a seven-notification queue. Deployment insertion uses exact deployment SHA, while the committed Hotel notice persists across deployments; different SHAs can create identical customer messages. Reannouncement updates created_at and deletes seen history. The shared seen endpoint previously preferred the POS guard even on FBR routes.
Screenshots show repeated Hotel titles; no production query was performed to attribute every row.

## Implemented in this checkpoint
- Routine unread updates remain in bell history without automatic modal interruption. Only explicitly featured updates may pop up.
- Resolve seen identity from the actual POS/FBR route, preserving panel/category filters and unique read records.
- Use firstOrCreate for concurrent acknowledgements; database failures no longer receive a false success response.
- Target the committed room/Front Desk notice to the hotel category, retaining existing company settings and records.
- HTTP regressions cover seven normal updates on both panels with refresh, featured acknowledgement leaving normal updates unread, and dual-session FBR acknowledgement.

## Remaining in this same draft PR
- Separate per-deployment internal proof from customer content identity so reused release text cannot create duplicate customer notifications. Preserve freshness gates; do not bypass them.
- Add content-aware publishing idempotency, revision-based explicit reannouncement and an audited archive path for existing duplicates. Preserve historical seen records.
- Check save responses on popup dismissal and provide a practical retry path.
- Restaurant businesses receive restaurant notices; hotel companies receive room notices and restaurant notices only if their Restaurant Outlet is enabled. Review family and category predicates together; do not recategorize existing tenants automatically.
- Add real browser acceptance for the original refresh/dismiss flow and concurrent publishing verification.

## Compatibility / verification
Normal notifications stay unread until deliberately read; bell and detail history remain. Featured notices, master switch, pending-company suppression, read-only impersonation, and existing audience filters remain supported. Tests exercise PRA and FBR users from separate tenants plus a dual-login browser.
No PHP/Composer runtime is installed in this partial local workspace, so no PHPUnit, MariaDB HTTP or Chrome result is claimed locally. Fresh CI is required. Draft is not ready for approval or owner relay. No production access, cleanup, merge, deploy or workflow rerun.
