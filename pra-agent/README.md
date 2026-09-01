# TaxNest PRA Sync Agent

A standalone Windows desktop application that auto-syncs your NestPOS invoices to **PRA (Punjab Revenue Authority)** from your local Pakistani PC — no relay, no proxy, no SSH required.

## How It Works

```
┌──────────────────┐    HTTPS     ┌──────────────────┐    HTTPS    ┌─────────────┐
│  TaxNest Server  │  ◄────────►  │  Your PC (PK IP) │  ────────►  │   PRA API   │
│ taxnest.pk   │   poll/30s   │  TaxNest Agent   │  submit     │  pra.gop.pk │
└──────────────────┘              └──────────────────┘             └─────────────┘
```

1. Agent polls TaxNest server every **30 seconds** for pending PRA invoices
2. Submits them directly to PRA from your local Pakistani IP
3. Reports success/fail back to TaxNest server
4. Sends heartbeat every **60 seconds** so the panel shows ONLINE status

---

## Build the `.exe` Installer (on a Windows PC)

### Pre-requisites
- Windows 10 / 11 (64-bit)
- [Node.js 20+ LTS](https://nodejs.org/en/download/) installed
- Internet connection (to download Electron binaries — ~150 MB)

### Steps

```powershell
# 1. Clone or download the pra-agent folder to your Windows PC
git clone https://github.com/jawadrao5555-alt/taxnest.git
cd taxnest\pra-agent

# 2. Install dependencies (downloads Electron — first time only)
npm install

# 3. Build the Windows .exe installer
npm run build:win
```

After ~3 minutes you'll find the installer at:

```
pra-agent\dist\TaxNest-Agent-Setup-1.0.0.exe
```

### Distributing to Companies

Upload the `.exe` to your TaxNest server:

```bash
# On the cPanel terminal:
mkdir -p ~/public_html/taxnest/public/downloads
# Then upload TaxNest-Agent-Setup-1.0.0.exe via cPanel File Manager into:
#   public_html/taxnest/public/downloads/TaxNest-Agent-Setup.exe
```

Companies download it from the **POS → PRA Sync Agent** page.

---

## Installation (End User on Pakistani PC)

1. Download `TaxNest-Agent-Setup.exe` from the POS panel
2. Double-click to install (accepts standard NSIS installer)
3. Launch **TaxNest PRA Agent** from Start Menu / Desktop
4. Paste the **3 credentials** shown on the POS panel:
   - **Server URL:** `https://taxnest.pk/api/agent`
   - **Company ID:** (your numeric ID, e.g. `13`)
   - **API Key:** (the `tnk_…` key you generated)
5. Click **Test Connection** → should show ✅
6. Click **Save & Connect** → agent starts syncing
7. Close the window — agent runs silently in the **system tray**

The agent **auto-starts on Windows boot**. To quit, right-click the tray icon → **Quit Agent**.

---

## Files

```
pra-agent/
├── main.js          # Electron main process (window, tray, IPC)
├── preload.js       # Secure context-bridge for renderer
├── index.html       # Renderer UI (config + status)
├── src/agent.js     # Sync engine (poll, submit, heartbeat)
├── package.json     # Build config (electron-builder NSIS)
└── assets/          # Icons (icon.ico for Windows)
```

## Server-side API (already deployed)

| Endpoint                          | Method | Description                          |
|-----------------------------------|--------|--------------------------------------|
| `/api/agent/heartbeat`            | POST   | Agent → server (every 60s)           |
| `/api/agent/pending-invoices`     | GET    | Server → agent (returns queue)       |
| `/api/agent/submit-result`        | POST   | Agent → server (PRA result)          |

All endpoints require `Authorization: Bearer <agent_api_key>`.

---

**This agent is exclusively for the NestPOS PRA module.** It has no relationship with the Digital Invoice (DI) or FBR submission flows — those use server-side FBR API directly and are not IP-restricted.
