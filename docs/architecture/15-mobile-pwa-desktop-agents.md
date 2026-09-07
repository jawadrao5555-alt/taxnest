# 15 — Mobile, PWA, Desktop Agents

## Android / companion apps (FACT)

| Directory | Product | Entry |
|-----------|---------|-------|
| `pos-app/` | NestPOS | `/pos/login` WebView shell |
| `di-app/` | Digital Invoice | `/login` |
| `fbr-pos-app/` | FBR POS | `/fbr-pos/login` |
| `caller-app/` | NestPOS companion | `/api/caller-app/v1` |
| `rider-app/` | NestPOS delivery | `/api/rider-app/v1` |
| `waiter-app/` | NestPOS restaurant | `/pos/waiter` |

Play Store docs under `docs/play/`.

## PWA

**FACT (`replit.md`):** Three PWAs share one `/sw.js`. Bump `CACHE_VERSION` on relevant deploys. `window.tnPwaUpdateHold` defers reload mid-sale.

## Desktop Agent (`pra-agent/`)

**FACT:** Electron app `taxnest-pra-agent` (package version in `package.json`, recently ~1.13.x).

Responsibilities:
- Heartbeat every **30s** (README may say 60s — **code wins**)
- PRA pending invoice fetch + submit + result
- Printer inventory + silent print claim loop
- Optional Local Core / offline LAN
- Live Ops allow-listed commands
- Self-update via GitHub Releases

Auth: company `agent_api_key`.

## Realtime gateway

**FACT:** `agent-realtime-gateway/` — loopback HTTP + WS wake only; Laravel authenticates via `/api/agent/realtime-auth`; print queue remains source of truth.

## Agent Core v2

**FACT:** Routes `api/agent/v2/*` → `AgentCoreController` behind feature flag `agent.core.enabled`.

## Naming collision

**FACT:** DB table `agents` = **distributor/sales** partners. Desktop Sync Agent = Electron + `companies.agent_*` + `pos_agent_devices`. Never confuse them.
