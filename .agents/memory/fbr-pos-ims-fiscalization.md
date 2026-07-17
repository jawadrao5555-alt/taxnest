---
name: FBR POS uses IMS Fiscalization, not DI
description: FBR POS (fbrpos guard) submits to FBR IMS POS Fiscalization (SRO 1279/2021), a different system/payload/token than Digital Invoicing — how the payload is built and why.
---

# FBR POS = IMS Fiscalization (SRO 1279/2021), NOT Digital Invoicing

**Rule:** FBR POS invoice submission uses the FBR **IMS POS Fiscalization** system, which is a
completely different API, payload model, and token than FBR **Digital Invoicing** (DI, `di_data/v1`).
DI and PRA POS submission are untouched by this — only the `fbrpos` guard path changed. Owner decision:
FBR POS uses IMS ONLY, no toggle/fallback.

**Why:** New POS registrations are rejected on the DI endpoint (`900908 Resource forbidden`) because a
DI token is not authorized on IMS. Owner wants FBR POS to be pure IMS.

**How to apply — all in `FbrService.php`, FBR-POS methods only:**
- Endpoints = IMS URLs (sandbox `esp.fbr.gov.pk:8244/FBR/v1/api/Live/PostData`, prod
  `gw.fbr.gov.pk/imsp/v1/api/Live/PostData`). Do NOT reuse DI `di_data/v1` URLs or the DI-only
  `fbr_production_url`/`fbr_sandbox_url` company overrides.
- Token = `company->fbr_pos_token` ONLY. There is NO fallback to DI tokens (`fbr_bearer_token` /
  env sandbox/production tokens). A DI token on IMS reproduces the 900908 error.
- Payload = IMS invoice model: flat header (POSID int from `fbr_pos_id`, USIN=`invoice_number`,
  DateTime `Y-m-d H:i:s` Asia/Karachi, Buyer NTN vs CNIC by digit-count 13=CNIC, TotalSaleValue/
  TotalTaxCharged/TotalQuantity/Discount/TotalBillAmount/PaymentMode int/InvoiceType 1) + `Items[]`
  (ItemCode/ItemName/Quantity/PCTCode≤8digits/TaxRate/SaleValue/TotalAmount/TaxCharged/Discount/
  FurtherTax/InvoiceType/RefUSIN).
- **Item amounts MUST come from the stored fiscal snapshots** `$item->subtotal` (net, excl tax, after
  item discount) and `$item->tax_amount`. NEVER re-derive from `unit_price × qty` — that silently
  over-reports **tax-inclusive** bills (cart `tax_inclusive` mode reverse-derives net into those
  columns; re-deriving adds tax on top of an already-inclusive price).
- **Bill-level discount:** header `Discount` = `$transaction->discount_amount` ONLY (bill-level
  manual + promotion, applied POST-tax in `store()`). Item SaleValues already net their own item
  discounts, so `TotalBillAmount = ΣSaleValue + ΣTax − billDiscount` (no double-subtract). This equals
  goods payable and EXCLUDES the app-only Rs 1 FBR service fee and loyalty redemption.
- **Success = Code `"100"`** (+ InvoiceNumber/FBRInvoiceNumber in the response). Store
  `fbr_response_code='100'`. Reuse `sendDirectToFbr` (Bearer + application/json) unchanged.
- **Pre-submit guard** in `submitFbrPosTransaction`: fail cleanly ONLY if POSID empty (no
  `fbr_pos_id`) — clear hash + failed log + `fbr_status='failed'` + return `{status:failed,errors}`.
  Return shape stays `{status, errors, fbr_invoice_number, fbr_response}` (6 consumers rely on it).
- **HS code / PCTCode is OPTIONAL for FBR IMS POS** — unlike DI where hsCode is mandatory. Retail POS
  items often have no HS code, so send PCTCode when present and blank otherwise; NEVER block the bill
  on a missing HS code. (Owner confirmed Jul 2026 — an earlier PCTCode-required guard was removed.)
- **Anti-churn:** `SyncFbrPosOfflineInvoicesJob` must skip companies missing `fbr_pos_token` OR
  `fbr_pos_id`, else guard-failed bills get re-picked every tick → a fresh `FbrPosLog` row per bill
  per 2-min tick.

**Items table gotcha:** the relation `$transaction->items()` maps to `fbr_pos_transaction_items`
(NOT `fbr_pos_items`, which does not exist).

**Auth errors on `/imsp/v1`:** `900901 Invalid Credentials` = the token itself is invalid/empty/
malformed to the FBR gateway (distinct from `900908 Resource forbidden` = valid token, not authorized
for that API). Since the empty-token guard already fires before send, a 900901 means a NON-empty token
was sent and rejected → causes: wrong/expired/mistyped token, sandbox token on the production URL (or
vice-versa — env must match the token), copy-paste whitespace/newline in the token (now defended by
`trim()` on both save and read), or the NTN not yet enrolled for IMS POS on the FBR portal. Token
column is TEXT (not truncated). This is a CONFIG issue, not a code bug — the endpoint/path is correct.
FBR-confirmed #1 cause of 900901 is **sandbox token on the production URL** (WSO2 routes by token type). A business
gets a **production token ONLY after passing FBR sandbox certification**, so a live 900901 usually means they only
hold a sandbox token yet → steer them to test on Sandbox first, then FBR issues the prod token.

**Disambiguation probe (proven technique):** to tell a *DI-only* token apart from a *POS-enrolled* token, POST a
throwaway `{}` (or minimal body) with the SAME token to BOTH gateways and compare the fault:
- `imsp/v1/api/Live/PostData` → `900908 Resource forbidden` **and** `di_data/v1/di/postinvoicedata` → HTTP 200 with a
  DI business-validation error (e.g. errorCode `0012` buyer-type) ⇒ the token is a **Digital Invoicing token, NOT a
  POS token**. It authenticates fine but its WSO2 application is only subscribed to `di_data/v1`, never `imsp/v1`.
  Fix is 100% on FBR's side: the owner must enroll/subscribe THIS token's application for the IMS POS (POS
  fiscalization, SRO 1279/2021) service tied to their POS Registration No — a DI token can NEVER submit POS bills.
- `900908` on BOTH endpoints ⇒ token valid but NTN not production-enrolled for either service.
- `900901` on both ⇒ token itself invalid/expired/sandbox-on-prod.
A real prod submission for X-WAY SHOES (POSID 196339) with the owner-supplied production token confirmed the first
case: 900908 on IMS, 200-with-DI-validation on DI ⇒ it is a DI token, hence every FBR POS bill fails.
**UPDATE 17 Jul 2026:** the token now in the `FBR_POS_PRODUCTION_TOKEN` secret returns **900901 on BOTH** DI
production AND DI sandbox validate ⇒ invalid everywhere (token was changed/rotated after the 11 Jul probe — likely
the IRIS-grid POS Registration code was pasted instead of a WSO2 bearer token). Control probe same payload with
ZIA's DI token (`FBR_PRODUCTION_TOKEN_C18`) → HTTP 200 + business error 0401 (token not bound to X-WAY's NTN) ⇒
gateway + payload format fine; blocker is purely a valid X-WAY token. Validate endpoints
(`di_data/v1/di/validateinvoicedata[_sb]`) are safe non-committing probes — no invoice is created.

**TWO different FBR credentials people confuse (proven, high-value):**
1. The **POS Registration token** shown in the IRIS *"Point of Sale Registration"* grid (POS-ID row, "Token"
   column, UUID form). This is **NOT** an OAuth Bearer/access token. Putting it in `Authorization: Bearer`
   yields `900901 Invalid Credentials` on **BOTH** sandbox (`FBR/v1`) and production (`imsp/v1`). It only
   identifies the POS; the POSID already carries that identity in the payload — the registration token is never
   the auth header.
2. The **API Access Token** — the SAME kind/source as the Digital Invoicing token (generated in FBR's API/PRAL
   token area). THIS is the Bearer token: it actually authenticates (proven — it returns `900908`, not `900901`),
   and its WSO2 application must be **subscribed to the `imsp/v1` POS API** to stop returning 900908.
So the correct FBR POS setup = the **API access token** (not the registration-grid token) whose application is
subscribed to the POS/IMS service. If someone pastes the registration-grid token → 900901 everywhere; if they
paste a DI-only API token → 900908 on IMS. Both are FBR-portal config, not a TaxNest bug. Verify a token's
identity by fingerprint (`printf %s "$TOK" | sha256sum | cut -c1-8`) to prove the stored secret == the value on
screen (rules out copy typos before blaming the value). The settings token
mask (`$maskedPosToken`) is DISPLAY-ONLY (blade placeholder + status line); the save path only writes on
`$request->filled(...)` and always stores the full encrypted token, and getFbrPosToken sends the full decrypted
token — masking never touches what is stored or sent, so it can NEVER cause 900901. `testConnection()` now parses
the response BODY for `fault.code` (json + `\b9009\d{2}\b` regex fallback) so it reports 900901/900908/900902
accurately instead of only checking HTTP 401.

**Sandbox-verify (spec ambiguity, not bugs):** whether FBR expects header `Discount` = ALL discounts
(item+bill) vs bill-only, and whether item `TotalAmount` = SaleValue+Tax vs SaleValue+Tax−Discount.
Current code is internally consistent (SaleValue already net; item Discount informational). Settle by
sending one sandbox bill that has BOTH an item discount and a bill discount.

## Consolidated (proven Jul 2026): IRIS POS-registration "Token" = ACCESS CODE, not a cloud Bearer
Evidence across X-WAY SHOES (POSID 196339):
- Registration-grid token (UUID) -> 900901 on BOTH imsp/v1 and FBR/v1 => it is NOT a WSO2 gateway access
  token. It is the **Access Code** for INSTALLING the local FBR Software Fiscal Component
  (download.fbr.gov.pk/IMS_Setup/FBRIMS.zip -> localhost:8524/api/IMSFiscal/GetInvoiceNumberByModel;
  Test/Production chosen at install). NEVER usable as `Authorization: Bearer` on the cloud.
- A real WSO2 access token behaves differently: DI token -> 900908 (valid, not IMS-subscribed);
  FBR's doc IMS sandbox token -> HTTP 200 `Code 104 "not authorized"` (passed gateway, reached IMS app,
  not authorized for that POSID). HTTP 200 + Code 104/100 PROVES imsp/v1 endpoint + our IMS payload are
  correct; only the token identity/authorization varies.
- Cloud IMS response ladder: 900901 = not a gateway token | 900908 = gateway token not subscribed to imsp/v1
  | Code 104 = subscribed token not authorized for this POSID | Code 100 = success.
Two REAL integration modes for an FBR Tier-1 POS:
  A. LOCAL fiscal component — install FBRIMS.zip on the shop PC with POS Reg No + the grid Access Code,
     Production; Desktop Sync Agent posts to localhost:8524. (= our fiscal_device mode)
  B. CLOUD — needs a proper WSO2 access token subscribed to imsp/v1 AND authorized for the POSID, issued
     from FBR's API/token portal (NOT the grid token). Then the existing cloud code works.
Separate DI system: pdi/DigitalInvoicing uses a `bposid` model, DIFFERENT from IMS (POSID/USIN/Items).
  DI sandbox esp.fbr.gov.pk:8244/DigitalInvoicing/v1/PostInvoiceData_v1 authenticates with FBR's DI sandbox
  token (HTTP 200 + field-validation). The v1.1 doc's prod path gw.fbr.gov.pk/pdi/v1/api/DigitalInvoicing/...
  returns 404 (stale). Never conflate IMS with DI.

## UPDATE (proven LIVE, Jul 2026): activated token authenticates, then cloud IMS = Code 112 -> local fiscal device
- Corrects the earlier "grid token = Access Code, not a cloud token" theory. The earlier 900901 was simply an
  INACTIVE / wrong token. Once FBR issues a PROPERLY-ACTIVATED POS token, it authenticates cleanly at
  gw.fbr.gov.pk/imsp/v1 (HTTP 200, no 900901).
- BUT cloud bulk PostData then returns `Code 112 "Bulk data upload functionality is no more available"` --
  IDENTICAL to PRA. FBR has RETIRED cloud bulk upload for POS. A valid token does NOT change this.
- => FBR POS (exactly like PRA) MUST submit via the LOCAL fiscal component: install FBRIMS.zip on the shop PC
  -> http://localhost:8524/api/IMSFiscal/GetInvoiceNumberByModel (single-object payload) -> our Desktop Sync
  Agent (fiscal_device mode). Direct server->cloud submission is a dead end for POS (Code 112).
- Operative blocker ladder is now: 900901 (token not activated/wrong) -> [activate token] -> Code 112 (bulk
  cloud retired) -> [use local fiscal device]. Do not chase cloud PostData after Code 112.

## IMPLEMENTED (Jul 2026): FBR POS fiscal_device mode — mirrors PRA, DO NOT rebuild
The Code-112 resolution is BUILT. Key non-obvious facts:
- `companies.fbr_connection_mode` ('cloud'|'fiscal_device', default cloud); `Company::agentServesFbr()` =
  fbr_pos_enabled && mode==fiscal_device. Enable via FBR POS Settings → Submission Mode → Fiscal Device → Save
  (auto force-enables agent + mints `tnk_`+48 agent_api_key).
- **Reuses the SAME Desktop Sync Agent (pra-agent) + Agent API (`/api/agent/*`) + `agent_*` columns as PRA —
  ZERO agent code changes.** The agent generically POSTs each `invoice.payload` to whatever `pra_endpoint` the
  server returns; for FBR that = `localhost:8524/api/IMSFiscal/GetInvoiceNumberByModel`, `pra_mode='fiscal_device'`,
  `pra_token=''`. The company's FBR cloud token is NEVER shipped to the agent.
- In fiscal_device mode the server NEVER cloud-submits: `submitFbrPosTransaction` short-circuits to
  `'queued_agent'` (leaves `fbr_status='pending'`) AFTER the already-submitted check but BEFORE the hash-lock;
  `store()` queues; `RetryFbrPosSubmissionJob` treats `queued_agent` as terminal.
- **Agent invariant (preserves the FBR three-branch write rule):** the agent ONLY ever receives reporting-ON
  PENDING finals. Deliberate 'local' provisionals (`fbr_status='local'`) and reporting-OFF finals (NULL) are
  NEVER handed out — `pendingInvoices` filters status ∈ (offline,pending,failed) AND `invoice_mode != 'local'`.
- `AgentController`'s 3 methods branch on `agentServesFbr()`; FBR branch writes `fbr_pos_transactions`
  (submitted/failed + fbr_invoice_number + fbr_response_code), NO PRA regex; success = agent-reported success
  AND non-empty invoice number. **Shared-agent constraint:** one company cannot run PRA-fiscal AND FBR-fiscal at
  once (agentServesFbr routes ALL agent endpoints to the FBR branch).
- Shop-PC install guide: `pra-agent/FBR-FISCAL-DEVICE-SETUP.md`. X-WAY SHOES (NTN 2595908-5, POSID 196354) is
  prod-only (not in dev DB) — e2e proven in dev on company 16.
- **LIVE STATE (17 Jul 2026): X-WAY SHOES (company 27) SWITCHED to fiscal_device on prod** (owner-approved).
  Its 4 failed bills FPOS-2026-00001..00004 were re-queued → all now `pending` awaiting agent pickup;
  `tnk_` agent key minted (visible on /fbr-pos/settings while impersonating). REMAINING: install FBRIMS.zip
  (needs POS Reg No + IRIS grid Access Code, Production) + Desktop Sync Agent (with the tnk_ key) on the
  shop's Windows PC — owner-side step, e.g. via AnyDesk.
- **Verified 17 Jul 2026 evening (server side fully OK, agent NOT connected):** polling
  `/api/agent/pending-invoices` with the tnk_ key returns all 4 bills, mode fiscal_device, POSID 196354,
  localhost:8524 endpoint, spec-correct IMS payloads. GOTCHA: any manual curl poll UPDATES
  `agent_last_seen`, so "Last Seen X min ago" on settings can be a prior session's verification poll, not
  a real agent — confirm by watching it AGE (3→4→5 min) without refreshing. Pre-switch cloud 900901
  failure rows in FbrPosLog (17 Jul 13:17) are history, not a live guard bug — no cloud attempts since the
  switch. Bills stay 'pending' untouched until a real agent + FBRIMS run on the shop PC.

## Web-based POS answer (official, Jul 2026): SDC "locally OR on Cloud" — central Windows VPS is compliant
Owner's requirement: shopkeeper gives only POS ID + Access Code (+token), zero shop-PC installs (TaxNest is web POS).
- FBR's own FiscSolution doc (attached_assets/2019121116123534681FiscSolution_03-12-2019*.pdf) states the Software
  Data Controller "will be installed locally **or on Cloud** on the same POS System/Within local Network" — so
  hosting FBRIMS on a TaxNest-managed Windows cloud VPS (with the Desktop Sync Agent beside it) is officially
  sanctioned. Shopkeeper installs NOTHING; agent polls taxnest.com.pk over HTTPS from anywhere. Zero code changes.
- **Why:** pure token-only cloud submission is permanently dead (Code 112 — bulk retired; no token unlocks it), so
  the VPS-hosted fiscal component is the ONLY fully-web path.
- **How to apply:** per client: FBRIMS install needs that client's POS Reg No + IRIS-grid Access Code (Production);
  the cloud token is NOT needed in fiscal_device mode. FBRIMS binds localhost:8524 → assume ONE instance per
  Windows machine/VM (multi-instance unverified); agent config is per-company too → simplest = one small Windows
  VPS (~$10–25/mo) per client, sellable as a "cloud fiscal bridge" service.

## Replit-IP cloud probe (Jul 12 2026): reconfirms ladder; stored secrets are NOT POS gateway tokens
Live PostData probe from dev egress IP with real company-16 payload (fake POSID → zero fiscal risk):
- env secret `FBR_POS_PRODUCTION_TOKEN` → 900901 on BOTH FBR/v1 + imsp/v1 ⇒ it is the IRIS grid
  token/Access Code, NOT a WSO2 gateway token. Never wire it as a cloud Bearer.
- env secret `FBR_PRODUCTION_TOKEN_C18` → 900908 on both ⇒ valid gateway token, DI-only subscription.
- Neither reaches Code 112, consistent with the proven ladder. "IP whitelist for Cloud POS" advice in
  Tier3-era docs is pre-Code-112 (~2020) — IP is NOT the blocker; cloud bulk is retired regardless of IP.

## Universal-cheap verdict (web-researched Jul 2026): shop's OWN counter PC = Rs 0, industry standard
Owner rejected per-client VPS cost → exhaustive web search confirmed NO universal cloud path exists:
- Tier3's "Cloud Based POS - Using WEBAPI" doc = the SAME retired `imsp/v1 PostData` endpoint (pre-Code-112, ~2020);
  web-POS vendors claiming "just enter store ID" (XStak etc.) are marketing — under the hood it's SDC or dead cloud.
- 2025-26 "licensed integrator" regime (SRO 709, Circular 1/2025) is for DI/e-invoicing, NOT the POS IMS chain;
  PRAL 150XF gives free integration HELP but same architecture. One SDC = one store POS ID (FBR hard rule); SDC
  multi-user covers multiple counters of the SAME store only. Multi-FBRIMS-per-machine: unverified hearsay only.
- **Cheapest universal answer:** every browser-billing shop already has a counter PC → 15-min remote (AnyDesk)
  install of FBRIMS + Desktop Agent there = Rs 0/month, cashier stays 100% web, offline-safe (bills queue while PC
  off). VPS remains the fallback for shops with no Windows PC. Pitch client ask as: POS ID + Access Code + AnyDesk.
