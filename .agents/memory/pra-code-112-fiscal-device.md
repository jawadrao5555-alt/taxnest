---
name: PRA Code 112 & Fiscal Device mode
description: Why new PRA POS IDs fail with Code 112 on cloud PostData and how the fiscal-device (localhost:8524) mode works
---

# PRA Code 112 → Fiscal Device mode

**Rule:** PRA response Code 112 ("Bulk data upload functionality is no more available") means PRAL retired the cloud `ims.pral.com.pk/ims/{env}/api/Live/PostData` API for that POS ID. Old/grandfathered POS IDs keep working on it; NEW registrations must use PRAL's local Windows service ("IMS Software Fiscal Device") on the shop PC.

**Why:** Diagnosed July 2026 with a new company (POS ID on production, correct token, direct connection) — settings were correct, every submission returned 112 while old companies kept succeeding on the same cloud URL.

**New flow facts (verified via PRAL spec + official C# sample):**
- Endpoint: `POST http://localhost:8524/api/IMSFiscal/GetInvoiceNumberByModel` — payload is the SAME invoice model as PostData, sent as a **single JSON object (NOT array-wrapped)**.
- Health check: `GET http://localhost:8524/api/IMSFiscal/get` → "Service is responding".
- Response model: `{InvoiceNumber, Code: '100', Response}` — Code may come back as number OR string; compare tolerantly.
- No auth needed (localhost only); the PRAL service itself syncs data up to PRA.

**How to apply in TaxNest:** per-company `companies.pra_connection_mode` ('cloud' default | 'fiscal_device'). Fiscal mode: server NEVER direct-submits (queues `pra_status='pending'`), AgentController hands the desktop agent the localhost:8524 endpoint, PRA Settings save force-enables agent + auto-generates agent_api_key. The TaxNest Desktop Agent must run on the SAME PC as PRAL's fiscal-device service. Every read of the column uses `?? 'cloud'` so pre-migration prod never breaks.

**Grandfathered cloud confirmation (Jul 2026):** live company "PIZZA MASTER" (company_id 23, POS ID 195994, cloud mode, production env) still succeeds on cloud PostData — a real fiscal bill submitted from live returned fiscal number + status 'submitted' instantly. Owner explicitly approved that one test bill (Rs 10 item, POS-2026-00017 / 195994FGKP18253160). Old POS IDs on cloud are fine; only NEW registrations hit 112.

**Go-live checklist for a fiscal-device company:** PRAL IMS Fiscal Device installed & responding on shop PC → TaxNest Agent installed with API key → PRA Settings: Connection Mode = Fiscal Device → prod migration run BEFORE saving settings (new column).
