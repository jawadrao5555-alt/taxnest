---
name: Silent printer routing (Tareeqa 2)
description: POS silent printing via Desktop Agent print-job queue — invariants for KOT stamping, fallback, and the Electron print window.
---

# Silent printer routing — durable rules

- **KOT `kot_printed_at` stamping happens at RESULT time, not render time** (agent path). `printJobContent` only records `printed_item_ids` on the job; `printJobResult` success stamps them (whereNull-guarded). **Why:** a physical print can fail after render (printer off/driver error) — render-time stamping made later delta tickets skip items the kitchen never saw. Popup/iframe path (`?auto_print=1` route) still stamps at render — that render IS the print there.
- Empty delta render (items already covered) → content endpoint returns **204**; agent marks job done without printing a blank page.
- **Electron `printHtml` must remove the `did-finish-load` listener in its done()** (removeAllListeners) — a stale `once` listener surviving a timeout fires on the NEXT job's load → duplicate physical print.
- Agent print options: `{silent, deviceName, printBackground, margins:{marginType:'none'}}` and **NO pageSize** — thermal drivers must keep their own roll setting.
- Templates auto-print only when `?auto_print=1` is in `location.search` — agent loads HTML via **data: URL**, so template scripts stay dormant by design.
- Every silent path falls back to the classic popup/iframe on ANY enqueue failure (409 disabled/no_printer/agent_offline, network). Feature default OFF per company (`pos_printer_settings` JSON).
- `pos_kitchen` confined accounts needed `pos/api/print-jobs` added to the PosAuth allowlist — any new endpoint the KDS calls must be added there too.
- Print-succeeded-but-result-lost → stale requeue reprints after 2 min = ACCEPTED risk for bills; deltas are protected by the stamping design.
- Dev testing gotcha: setting `agent_last_seen` via MySQL `NOW()` looks offline to Laravel (timezone mismatch) — use the real `/api/agent/heartbeat` endpoint instead.
- **KOT "ADDED ITEMS" banner ≠ delta param** (client bug Jul 2026): the universal screen sends `delta=1` on EVERY KOT incl. the FIRST — keying the banner on `$delta` alone stamps "ADDED ITEMS" on brand-new orders. Rule: banner only when the order has previously STAMPED rows (`kot_printed_at` NOT NULL) that are NOT on this ticket. Popup/iframe path is safe (stamping is a raw DB update after the items relation is eager-loaded — in-memory rows stay NULL within the request). KNOWN caveat: multi-station FIRST send via the agent can still misfire (station #1's result-time stamp lands before station #2 renders) — deterministic fix would pass an `addition=1` flag computed at ENQUEUE time; not done (near-zero multi-station users).
