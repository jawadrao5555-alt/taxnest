# Audited owner reconciliation for held FBR POS invoices

Backlog #194; recovery dependency for #197/#198. Owner-only, company-scoped
review of existing USIN, total and environment. Acceptance requires the actual
fiscal number from checked IMS/portal evidence; rejection release requires IMS
log evidence (portal absence cannot prove rejection). No new fiscal POST is
made by this form. It neither invents Code100 nor changes automatic central or
local-environment proof to verified. Operator evidence is a separate audit fact.

Row locking, company/POS/environment + bill fingerprints and request UUID
idempotence prevent stale forms overwriting late callbacks or current settings.
Existing fiscal numbers cannot be removed/replaced. Audit and bill state commit
or roll back together; audit records are retained on migration rollback. Only
held bills or expired agent claims are eligible; provisionals and active claims
are excluded. Same original retailer USIN is retained when retry is allowed.

Guarded PHP 8.4 disposable SQLite: 8 tests / 27 assertions PASS. Coverage includes
acceptance/rejection, idempotence, confirmation requirement, stale callback and
configuration changes, known number protection, environment mismatch, owner
permission and tenant isolation. No regulator traffic or live credentials.

Actual accepted/rejected recovery journey is added to the existing loopback
MariaDB/Chrome transactional fixture for desktop and 390px mobile. Native full
migration and full PHPUnit CI must pass. Local Chrome/native execution is not
claimed (local native/browser binaries unavailable); pending CI is not a pass.
Syntax and whitespace checks pass. This is owner-attested reconciliation, not
an automated query to FBR. Owner must inspect genuine evidence before release.
