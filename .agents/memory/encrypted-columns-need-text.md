---
name: Encrypted DB columns must be TEXT, not VARCHAR(255)
description: Crypt::encryptString output overflows varchar(255) even for tiny plaintext; use TEXT.
---

# Any column storing Crypt::encryptString() output must be TEXT

`fbr_pos_token` was `varchar(255)`. A raw 36-char UUID FBR POS token encrypts (Laravel
`Crypt::encryptString`, AES-256-CBC, JSON {iv,value,mac,tag} base64) to **256 chars** —
one over the limit — so saving FBR POS settings 500'd on PROD with
`SQLSTATE[22001] Data too long for column 'fbr_pos_token'`.

**Why:** Laravel encryption inflates even a short secret past 255 bytes; the ciphertext
length grows with plaintext, so longer tokens overflow by far more. varchar(255) is never
safe for an encrypted value.

**How to apply:** Store every `Crypt::encryptString()` column as `TEXT` (the DI columns
`fbr_sandbox_token` / `fbr_production_token` already are — mirror them). NOTE: not all
`companies` credential columns are encrypted — `pra_production_token` is stored RAW
(PosController), and `fbr_pos_id` is the plain numeric POS id; only the encrypted ones
need TEXT. Fix stale prod schema with a fresh idempotent migration doing a guarded
`ALTER TABLE ... MODIFY col TEXT NULL` (safe to re-run under `migrate --force`); the
`->max:255` request validation on the RAW input is fine and unrelated to the column size.
