---
name: PRA sale screen offline-first boot
description: SALE_CACHE cache-first serving of /pos/invoice/create, boot fingerprint staleness check, purge rules, and the accepted one-revision-stale race
---

# Offline-first boot for the PRA POS sale screen (Jul 2026)

**What:** `/pos/invoice/create` (EXACT pathname, `req.mode==='navigate'`, empty query string) is served CACHE-FIRST from `SALE_CACHE` in `public/sw.js` with background revalidate — instant boot on slow shop internet, and the screen even opens fully offline (offline bill queue handles submits). Query variants (`?table_id`, `?edit_bill`, `?updated`) stay network-only via skipPatterns. Owner asked for "load once and stay" because the desktop shell (live-site window) was slow to open the screen.

**Freshness:** the rendered page bakes `window.tnBootFp` = server fingerprint {u:user_id, c:company_id, s:filemtime(universal.blade.php), cat:md5(products/services/deals count+max updated_at + TODAY's date — deals have day windows), set:md5(company.updated_at, user.updated_at, praReportingEnabled, effectiveRules, pos_role, user grid prefs)}. `bootFpCheck()` (in universal.blade.php x-data) fetches `GET /pos/api/boot-check` ~1.5s after init:
- redirected/401/419/non-JSON → `location.replace(pos.login)` (dead session).
- mismatch → postMessage `TN_DROP_SALE_CACHE` to SW, then one reload. NEVER mid-sale (cart/edit/paymodal/receipt/submitting busy-guard) EXCEPT user/company change = security reload.
- offline/fetch fail → silent no-op, cached screen keeps working.

**Purge rules (audit-rule compliance — HTML bakes per-user data):** SALE_CACHE is deleted on (a) ANY */logout request (same branch as RUNTIME_CACHE), (b) any POST to */login (user switch on shared terminal), (c) TN_DROP_SALE_CACHE message. Accepted gap unchanged: closing the browser without logout keeps caches until next login/logout.

**Accepted race (do NOT "fix" into a loop):** the background-revalidate `c.put` can land AFTER `TN_DROP_SALE_CACHE` + reload, resurrecting a one-revision-stale copy; the sessionStorage one-shot guard (keyed on the SERVER fingerprint) then blocks a second reload — tab stays ONE revision stale until next change/boot. Deliberate trade: loop protection > perfect freshness; self-heals.

**Also accepted staleness:** customers list + heldOrders baked copy can be one boot-cycle old — new waiter orders arrive via the existing 20s `/pos/api/incoming-orders` poll, so nothing operational is missed.

**How to apply:** if the sale screen ever bakes NEW user/company-variant or price-relevant data, add it to `posBootFingerprint()` (PosController) or it will be served stale from cache. If it ever bakes branch-scoped data, add branch id to the fingerprint. Never cache query-string variants or add other authenticated screens to SALE_CACHE without these same rails.


## Loop-proof reload (28 Jul 2026, ZFC "loading bar bar")
- Old flow (postMessage TN_DROP_SALE_CACHE + 400ms reload) was a RACE: slow/failed network → reload landed on the same stale copy → loop.
- New contract in bootFpCheck: fetch fresh HTML (cache:'reload') FIRST, page itself `caches.put()`s it into the live `*-sale` cache (name discovered via caches.keys().find(endsWith('-sale')) — never hardcode the version), THEN reload. Network fail → NO reload, cached screen keeps working. sessionStorage one-shot guard set only after fresh copy secured.
- posBootFingerprint: now()->toDateString() joins `cat` ONLY when company has deals (dealsAgg not '0:...') — deal-less shops no longer force-reload every morning.

## Reload loop root cause (30 Jul 2026 — ZFC "bar bar load")
Boot fingerprint's `set` hashes `companies.updated_at`. Desktop Agent heartbeat wrote `agent_last_seen` via Eloquent `update()` every ~minute → updated_at bumped → every agent-running shop's cached sale screen looked stale → perpetual reload (+ waiter-order toast re-fired each boot).
**Rule:** ALL agent telemetry writes (heartbeat, pendingInvoices beats, submitResult beats, reportPrinters) must go through `AgentController::telemetryUpdate()` (timestamps=false) — never a bare `$company->update()`. Any NEW agent endpoint that touches companies must use it too.
**Residual fragility:** fingerprint still hashes raw company->updated_at — any frequent non-POS write to companies will recreate the loop; long-term fix = explicit POS-config revision.
