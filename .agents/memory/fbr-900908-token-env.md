---
name: FBR error 900908 (Resource forbidden) — token/env, not a bug
description: What FBR DI error 900908 means and how to diagnose it for DI + FBR POS submissions.
---

# FBR 900908 "Resource forbidden : Access failure for API: /di_data/v1"

FBR's Digital Invoicing gateway returns `900908` when it rejects the **Bearer token's authorization** for that API. It means a token WAS sent (non-empty, decrypt succeeded) and FBR said forbidden — so it is NOT "token missing" and NOT an app code bug. It is a per-company token / environment / FBR-enablement config issue.

**FBR POS uses the DI endpoint.** FBR POS bills submit to `https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata` (prod) / `..._sb` (sandbox). Environment = `fbr_pos_environment ?? fbr_environment`. Token = dedicated `fbr_pos_token` if set, else it FALLS BACK to the DI `fbr_production_token` / `fbr_sandbox_token`. The "FBR POS Fee (SRO 1279/2021)" line on the receipt is just the Rs 1 fee label — the actual integration path is DI, not the old SRO-1279 POS invoice-number API.

**Common causes of 900908:**
1. Env ↔ token mismatch — a sandbox token sent to the production URL (or vice versa). Sandbox tokens only work on the `_sb` endpoints.
2. Invalid / expired / truncated token (copy-paste with a space or missing chars).
3. FBR has not enabled that NTN for PRODUCTION Digital Invoicing yet — you must pass all sandbox scenarios first, then FBR issues the production token and whitelists the NTN. Jumping straight to production ⇒ 900908.
4. No dedicated `fbr_pos_token` ⇒ app falls back to the DI token, which may not be authorized for that NTN/POS.

**Fix (on the live company):** FBR POS → Settings → set correct Environment (Production for live) + paste the matching valid token, confirm FBR has enabled that NTN for production, then retry from the Fail Queue. Bill is already saved locally (pending) — no data loss.
