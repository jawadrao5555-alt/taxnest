---
name: FBR error 900908 (Resource forbidden) — token/env, not a bug
description: What FBR DI error 900908 means and how to diagnose it (DI path). FBR POS no longer uses DI — see fbr-pos-ims-fiscalization.md.
---

# FBR 900908 "Resource forbidden : Access failure for API: /di_data/v1"

FBR's Digital Invoicing gateway returns `900908` when it rejects the **Bearer token's authorization** for that API. It means a token WAS sent (non-empty, decrypt succeeded) and FBR said forbidden — so it is NOT "token missing" and NOT an app code bug. It is a per-company token / environment / FBR-enablement config issue.

**Scope note (IMPORTANT):** this applies to **Digital Invoicing (DI)** only. **FBR POS no longer uses the DI endpoint or DI token** — it now submits to FBR IMS POS Fiscalization (SRO 1279/2021) with a dedicated `fbr_pos_token` and NO DI fallback. See [FBR POS = IMS Fiscalization not DI](fbr-pos-ims-fiscalization.md). (Historically FBR POS did submit to `di_data/v1` and fell back to the DI token; that was removed by owner decision.)

**Common causes of 900908 (DI):**
1. Env ↔ token mismatch — a sandbox token sent to the production URL (or vice versa). Sandbox tokens only work on the `_sb` endpoints.
2. Invalid / expired / truncated token (copy-paste with a space or missing chars).
3. FBR has not enabled that NTN for PRODUCTION Digital Invoicing yet — you must pass all sandbox scenarios first, then FBR issues the production token and whitelists the NTN. Jumping straight to production ⇒ 900908.

**Fix (DI, on the live company):** set correct Environment (Production for live) + paste the matching valid token, confirm FBR has enabled that NTN for production, then retry. For FBR POS the analogous 900908/403 cause is a wrong/missing dedicated `fbr_pos_token` or a POSID not yet whitelisted on IMS — fix in FBR POS → Settings.
