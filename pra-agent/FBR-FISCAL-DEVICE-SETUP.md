# FBR POS — Fiscal Device (Local) Setup Guide

This guide is for **FBR POS** shops that must submit through the **local FBR IMS Fiscal
component** on the shop PC instead of the cloud.

## Why Fiscal Device mode?

FBR has **retired cloud bulk upload** for POS. A valid, properly-activated POS token now
authenticates fine at `gw.fbr.gov.pk/imsp/v1` (no more 900901), but the cloud submission then
returns:

```
Code 112 — "Bulk data upload functionality is no more available"
```

This is identical to what PRA did. The only supported path for a Tier-1 POS is now the
**local FBR IMS Fiscal component** running on the shop PC, reached by the **TaxNest Desktop
Sync Agent** — the exact same agent NestPOS already uses for PRA.

## How it works

```
┌──────────────────┐    HTTPS      ┌───────────────────────────┐      HTTP        ┌───────────────────┐
│  TaxNest Server  │  ◄─────────►  │  Shop PC (Pakistan)        │  ─────────────►  │  FBR IMS Fiscal   │
│  taxnest.pk  │   poll/30s    │  TaxNest Desktop Agent     │  localhost:8524  │  Component (FBRIMS)│
└──────────────────┘               └───────────────────────────┘                  └───────────────────┘
```

1. A cashier finalises a reporting-ON bill → TaxNest queues it (`fbr_status = pending`);
   the server does **not** call FBR.
2. The Desktop Agent polls the server every 30 seconds and pulls pending FBR bills.
3. For each bill it POSTs to the local component:
   `http://localhost:8524/api/IMSFiscal/GetInvoiceNumberByModel` (single-object payload).
4. FBR returns `Code 100` + an **InvoiceNumber**; the agent reports it back and the FBR
   invoice number appears in TaxNest automatically.

> The server never sends the company's FBR cloud token to the agent — fiscal-device
> submission is authorised entirely by the local component's install-time credentials.

---

## Step 1 — Turn on Fiscal Device mode in TaxNest (one click)

1. Log in to the **FBR POS** panel as the company admin.
2. Go to **FBR POS → Settings** (FBR Integration).
3. Under **Submission Mode**, choose **Fiscal Device — Local component on shop PC**.
4. Click **Save Settings**.
   - This automatically enables the Desktop Agent and generates an **API Key**
     (`tnk_…`). Copy it — you'll paste it into the agent in Step 3.

---

## Step 2 — Install the FBR IMS Fiscal component on the shop PC

> **Shortcut (agent v1.2.0+):** if the TaxNest Desktop Agent is already installed (Step 3),
> its **"FBR IMS Fiscal Service"** card detects when the service is missing and offers a
> one-click **Install FBR IMS** button — the agent downloads `FBRIMS.zip` from FBR's official
> server, extracts it, and launches the FBR installer for you. You then only do the
> activation entries in point 3 below. Manual steps:

1. On the shop PC (Windows, Pakistan connection), download the FBR fiscal component:
   `https://download.fbr.gov.pk/IMS_Setup/FBRIMS.zip`
2. Unzip and run the installer.
3. During install, enter:
   - **POS Registration No** (your POSID, e.g. `196354` for X-WAY SHOES)
   - **Access Code** (the token shown in the IRIS *Point of Sale Registration* grid for that POSID)
   - **Mode:** **Production**
4. Finish. The service listens on `http://localhost:8524`.
5. **Health check** — open in the PC's browser:
   `http://localhost:8524/api/IMSFiscal/get` → should respond that the service is running.

---

## Step 3 — Install & connect the TaxNest Desktop Sync Agent

The **same** agent used for PRA is reused — no separate FBR build.

1. Download **TaxNest-Agent-Setup.exe** from the POS panel (POS → Sync Agent) or from your
   TaxNest server's `/downloads` folder.
2. Install and launch it on the **same shop PC** as the FBR IMS component.
   (Tip: you can install the agent FIRST and let its "FBR IMS Fiscal Service" card
   install the FBR component for you — see the shortcut in Step 2.)
3. Paste the three credentials:
   - **Server URL:** `https://taxnest.pk/api/agent`
   - **Company ID:** your numeric company ID
   - **API Key:** the `tnk_…` key from Step 1
4. Click **Test Connection** → should show ✅.
5. Click **Save & Connect**. The agent runs in the system tray and auto-starts on boot.

---

## Step 4 — Verify end-to-end

1. In FBR POS, create one small test bill (reporting ON).
2. Within ~30 seconds the bill's status moves from **Pending** to **Submitted** and shows an
   **FBR Invoice Number**.
3. If it fails, open the F11 **Failed** list in the sale screen to see the exact error, fix,
   and retry.

---

## Troubleshooting ladder

| Symptom | Meaning | Fix |
|---|---|---|
| Agent panel shows **Offline** | Agent not running / wrong key | Relaunch agent; re-check API Key + Company ID |
| `localhost:8524` health check fails | FBR IMS component not running | Restart the FBRIMS service on the shop PC |
| Cloud submit returns **Code 112** | You're still in **cloud** mode | Switch Submission Mode to **Fiscal Device** (Step 1) |
| `900901 Invalid Credentials` (cloud) | Token not activated / sandbox token on prod | Not relevant in fiscal-device mode — ignore; use local component |
| Component returns **Code 104** | Token/POSID not authorised for that POSID | FBR-portal issue — verify POS Reg No + Access Code at install |
| Component returns **Code 100** | ✅ Success | Invoice number flows back automatically |

---

## Notes

- Fiscal Device mode is **one setting per company** (`Submission Mode`). Digital Invoice (DI)
  and PRA POS are unaffected.
- The Desktop Agent is shared: a company runs the agent for **either** PRA fiscal-device **or**
  FBR fiscal-device, not both at once.
- Server-side API (already deployed): `POST /api/agent/heartbeat`,
  `GET /api/agent/pending-invoices`, `POST /api/agent/submit-result` — all require
  `Authorization: Bearer <agent_api_key>`.
