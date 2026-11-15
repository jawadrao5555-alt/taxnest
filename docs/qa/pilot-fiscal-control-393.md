# School / Hospital administrative fiscal control

This change is deliberately **not a fiscal integration**. Existing School and
Hospital dispatch denials remain unchanged. A persisted SaaS `super_admin` can
inspect a company-specific OFF/blocked status and explicitly confirm OFF.
Tenant owners, cashiers, other administrator roles, demoted sessions and
read-only impersonation cannot change it. Retail companies are excluded.

An ON request is rejected with HTTP 422 (`category_route_unverified`) and audited.
No requested-ON preference, route, payload, tax assumption, credential or future
activation is stored. OFF clears only the selected pilot company's raw reporting
flag. Receipts, historical invoices, payments, closing snapshots, existing fiscal
acknowledgements and queues are not rewritten. Audit failure rolls back OFF.

## External blocker before functional ON

Each category needs authoritative applicability and route documentation, the
exact payload/authentication contract, and authorized sandbox access with
accepted/rejected/timeout/duplicate evidence. These are not interchangeable with
retail IMS or PRA settings. Future implementation also needs reviewed immutable
new-bill eligibility, idempotent claims, uncertain-response quarantine, valid
acknowledgement-only fiscal number/QR handling and preservation of existing ACKs.
Hospital installments must not become separate fiscal invoices by inference.
No current or historical local bill may be automatically promoted by a later ON.

Keep the PR draft until required exact-head checks and official integration
requirements are resolved. No live submission, merge or deployment is authorized
by this control implementation.
