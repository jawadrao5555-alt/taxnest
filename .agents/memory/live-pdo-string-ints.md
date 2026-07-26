---
name: Live PDO returns numeric columns as strings
description: cPanel PROD MySQL PDO gives non-cast int columns back as STRINGS; dev gives ints — JS strict === id compares break only on live.
---

# Live PDO string ints vs dev ints

**Rule:** On the owner's cPanel PROD, Eloquent attributes for integer columns WITHOUT a model cast (and without primary-key auto-cast) come back as **strings** (`"56"`); Replit dev MySQL returns real ints. Any JSON endpoint that passes such an id to JS, where the frontend does a strict `===` compare against another id, works on dev and silently fails ONLY on live.

**Why:** Burned Jul 2026 — Table Board purple waiter tiles never showed on live: `incomingOrders` JSON had `"table_id":"56"` (string) while table feed `id` was int (PK auto-cast), so `o.table_id === t.id` never matched. Dev was green the whole time.

**How to apply:**
- In JSON serializers, explicitly cast ids: `(int) $o->table_id` (null-guard first). Model `$casts` also works.
- In Blade/JS, never strict-compare ids that crossed a fetch boundary — use `Number(a) === Number(b)` with a null guard.
- When a feature "works on dev but not live" with no errors, dump the LIVE JSON and check value TYPES, not just values.
- Debug trick: puppeteer-login to live and `fetch()` the API in-page to see the real payload types.
