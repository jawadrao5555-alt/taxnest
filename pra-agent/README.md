# TaxNest PRA Sync Agent

Desktop companion app that runs on a company's local Pakistani PC to submit POS invoices directly to PRA — no relay or proxy needed on the server.

## Architecture

```
TaxNest Server (any country)
        ↓ (pull pending)
   Local PC Agent (Pakistan IP)
        ↓ (submit)
       PRA (PRAL IMS)
        ↓ (response)
   Local PC Agent
        ↓ (report success)
TaxNest Server
```

## How it works

1. Company logs into TaxNest, goes to **PRA Sync Agent** menu, generates an API key.
2. Downloads & installs the agent on a Pakistan-based PC.
3. Enters Server URL + Company ID + API Key in the agent.
4. Agent runs in the system tray, polls every 30 sec for pending invoices.
5. Pulls payload + PRA token from server, submits to PRA from the local IP.
6. Reports success/failure back to the server.

## Build instructions (developer)

### Prerequisites
- Node.js 18+ installed
- Windows PC (for `.exe` build) or Mac/Linux for those targets

### Install dependencies
```bash
cd pra-agent
npm install
```

### Run in development
```bash
npm start
```

### Build Windows installer (.exe)
```bash
npm run build:win
```
Output: `dist/TaxNest Agent Setup 1.0.0.exe`

### Build for all platforms
```bash
npm run build
```

## Deploy to companies

After building, upload the installer to:
```
public_html/taxnest/public/downloads/TaxNest-Agent-Setup.exe
```

Then companies can download from the **PRA Sync Agent** page in their dashboard.

## Configuration

Companies enter:
- **Server URL**: `https://taxnest.com.pk/api/agent`
- **Company ID**: shown in their dashboard (e.g. `13`)
- **API Key**: generated in their dashboard (e.g. `tnk_a3f9b2e1c8d7...`)

## Features

- ✅ System tray (runs in background)
- ✅ Auto-start on Windows boot
- ✅ Heartbeat every 60 sec (online status)
- ✅ Sync poll every 30 sec
- ✅ Live status UI with stats
- ✅ Test connection button
- ✅ Encrypted local config (electron-store)
- ✅ Auto-retry on failures

## Server API

All endpoints require `Authorization: Bearer <api_key>` header.

### POST `/api/agent/heartbeat`
Body: `{ version, company_id }`
Response: `{ ok, company: { id, name, pra_pos_id, pra_environment }, server_time }`

### GET `/api/agent/pending-invoices`
Response: `{ count, invoices: [{ transaction_id, invoice_number, payload, created_at }], pra_endpoint, pra_token }`

### POST `/api/agent/submit-result`
Body: `{ transaction_id, success, pra_invoice_number, response, error }`
Response: `{ ok }`

## Security

- API key is per-company (regenerate anytime from dashboard)
- Server enforces company-level isolation (transaction_id must belong to company)
- Agent stores credentials in OS-secured electron-store
- All communication over HTTPS
