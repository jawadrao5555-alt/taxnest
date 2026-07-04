---
name: Credential ledger anti free-trial abuse
description: How registered_credentials blocks duplicate free-trial signups and which account-creation paths must record vs block.
---

# Credential ledger (anti free-trial abuse)

`registered_credentials` (unique `(credential_type, credential_value)`) is a persistent
ledger of every credential ever used to open an account. It SURVIVES account/company
deletion (backfill included soft-deleted companies). Types: email, phone, ntn, username, cnic.
Normalization via `CredentialLedgerService::normalize` — email/username lowercased+trimmed,
phone/cnic digits-only, ntn upper-alnum. Backfill and runtime lookups must use the SAME
normalization or rows won't match.

## The rule
- **Public self-registration = BLOCK + record.** DI `RegisteredUserController@store`,
  `PosAuthController@register`, `FbrPosAuthController@register`: after `$request->validate`,
  call `CredentialLedgerService::firstUsed([...])`; if it returns a type, throw
  `ValidationException::withMessages([$field => $msg])` (map via `rejectionFor`). Record the
  creds only after the user/company is created.
- **Admin-created accounts = record ONLY, never block** (admin is the recovery path). Applies
  to BOTH admin company-create paths: `AdminCompanyController@store` and
  `AdminController@storeCompany` (the `/admin/companies` form). The latter records both
  `admin_email` and the distinct company `email`.

**Why:** owner wants to stop people deleting an account and opening a fresh free trial to
dodge subscription. NTN uniqueness is the strongest barrier; email/phone/username are extra.

**How to apply:** any NEW account-creation path you add MUST record to the ledger (and, if it
is public/self-serve, also block). If you add a create path and forget to record, that account
can later self-register a free trial — a silent bypass. Keep block+record in sync.

## Known intentional side-effect
Blocking is cross-product: an existing paying DI customer CANNOT self-register a POS/FBR-POS
account with the same email/NTN — admin creation is the only path for them. This is by design
(confirmed acceptable for launch). Minor theoretical TOCTOU between `firstUsed` and `record`
under concurrent signups; NTN unique index is the real guard.
