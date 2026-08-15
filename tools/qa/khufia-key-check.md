# Khufia key (Ctrl+Alt+Shift+L) — repeatable browser check (Task 738)

Run this after any big change to `resources/views/pos/universal.blade.php`,
`resources/views/layouts/pos-app.blade.php`, or `public/sw.js` — the khufia
flow has no visible UI, so regressions are silent.

## 0. Setup (idempotent — always run first)

```
env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
    -u PGPASSWORD -u PGDATABASE php tools/qa/khufia-qa-setup.php
```

Standing QA users (company 11, NestPOS Enterprise Store). Password =
`KHUFIA_QA_PASS` in `.local/qa-creds.env` (never commit — public repo):

| user | role | scope | counterpart |
|---|---|---|---|
| khufia.manager@taxnest.com | pos_manager | both | — |
| khufia.pra@taxnest.com | pos_cashier | pra | — (target) |
| khufia.local@taxnest.com | pos_cashier | local | → khufia.pra |
| khufia.unlinked@taxnest.com | pos_cashier | local | NONE |

Login page: `/pos/login` (fields: `login`, `password`). Dev base:
`https://$REPLIT_DEV_DOMAIN:5000` (or http://127.0.0.1:5000 for curl with
`X-Forwarded-Proto: https` header).

## Mechanics under test

- pos-app layout registers a capture-phase `keydown` listener: Ctrl+Alt+Shift+L
  POSTs `/pos/api/local-check-toggle` (manager/owner) or
  `/pos/api/identity-switch` (cashier), then reloads ONLY when something changed.
- Status dot: fixed 8px circle at bottom-left (`bottom:8px;left:8px`,
  `border-radius:9999px`). Teal `#14b8a6` = manager local-check ON;
  gray `#9ca3af` = station identity switched. No dot = neither.
- On identity switch the page posts `{type:'TN_DROP_SALE_CACHE'}` to the
  service worker (drops the offline-first SALE_CACHE so the reload can't serve
  the OLD cashier's baked sale screen).

## Flow A — manager local-check toggle round-trip

1. [New Context] fresh browser context.
2. [Browser] Log in at `/pos/login` as `khufia.manager@taxnest.com`.
3. [Browser] Navigate to `/pos/transactions`.
4. [Verify] Default PRA-only: NO "Local" tab/filter in the stream tabs, and NO
   status dot (no fixed 8px circle bottom-left).
5. [Browser] Press Ctrl+Alt+Shift+L (send to `body`, not an input). Wait for
   the page to reload itself.
6. [Verify] "Local" tab now visible on `/pos/transactions`; teal dot present
   (`div[style*="border-radius:9999px"]` with background `#14b8a6`).
7. [Browser] Press Ctrl+Alt+Shift+L again; wait for reload.
8. [Verify] Local tab hidden again; no dot.
9. [Browser] Log out.

## Flow B — khufia.local identity switch round-trip

1. [New Context] fresh browser context.
2. [Browser] Log in as `khufia.local@taxnest.com` → lands on
   `/pos/invoice/create`.
3. [Verify] Sale screen shows cashier "Khufia Local QA"; NO status dot.
4. [Browser] Press Ctrl+Alt+Shift+L; wait for reload.
5. [Verify]
   - Page now shows "Khufia PRA QA" (fresh baked page — NOT the cached
     Local page; if it still says "Khufia Local QA" the SALE_CACHE drop broke).
   - Gray dot present (background `#9ca3af`).
   - Optional deep check: `await caches.keys()` contains no `*-sale` cache
     entry immediately after the switch message (before the reload re-caches).
6. [Browser] Press Ctrl+Alt+Shift+L again; wait for reload.
7. [Verify] Back to "Khufia Local QA"; no dot.
8. [Browser] Log out.

## Flow C — khufia.unlinked total silence

1. [New Context] fresh browser context.
2. [Browser] Log in as `khufia.unlinked@taxnest.com` → `/pos/invoice/create`.
3. [Browser] Press Ctrl+Alt+Shift+L. Wait ~3 seconds.
4. [Verify]
   - NO reload happened (page stayed put — e.g. a JS marker set before the
     keypress, `window.__tnMark = 1`, is still `1`).
   - No dot, no toast, no error dialog; the only network evidence allowed is a
     200 JSON `{"switched":false}` on `/pos/api/identity-switch`.
   - No console errors.

## Playwright / automation gotchas (learned Task 708 + 738)

- **Session ID rotates on identity switch** — `Auth::login()` migrates the
  session. Browsers are fine; raw HTTP clients MUST save the Set-Cookie from
  the switch POST or the next request is logged out.
- Use `keyboard.press('Control+Alt+Shift+L')` with focus on `body`. The
  listener checks `e.code === 'KeyL'` plus all three modifiers; it is
  capture-phase so sale-screen shortcut guards don't eat it.
- The reload is async (fetch → then reload): wait for a navigation/load event,
  not a fixed sleep, before asserting.
- The dot has `pointer-events:none` and no text — assert via the style
  selector, never via click/getByText.
- Dev preview is served through the Replit proxy; the SW only registers on the
  https origin. If `navigator.serviceWorker.controller` is null (first visit),
  the SALE_CACHE deep-check is vacuous — reload once to let the SW claim, or
  rely on the "fresh baked name" assertion which holds either way.
- The sale screen is offline-first (cache-first via SALE_CACHE): the baked-name
  assertion in Flow B step 5 is the REAL regression tripwire for sw.js changes.
- curl repro of the whole switch round-trip (no browser) lives in the recipe in
  `.agents/memory/pos-local-check-identity-switch.md`.
- **Flaky-proxy environments (isolated task envs):** the Replit proxy can drop
  the post-toggle reload (`chrome-error://chromewebdata` / HTTP 502) even
  though the server log shows BOTH the toggle POST and the reload GET
  succeeded. Worse, a recovery `page.goto` may be served the STALE pre-toggle
  page by the SW runtime cache — always recover with a unique query string
  (`/pos/transactions?_qa=<nanoid>`). If the browser leg stays unusable, fall
  back to the curl verification below (it proves everything except the
  keydown listener, which Flow B exercises identically).

## Flow A curl fallback (no browser)

```
B=http://127.0.0.1:5000; H='X-Forwarded-Proto: https'
T=$(curl -s -c jar -H "$H" $B/pos/login | grep -o 'name="_token" value="[^"]*"' | sed 's/.*value="//;s/"//' | head -1)
curl -s -o /dev/null -b jar -c jar -H "$H" -d "_token=$T" \
  --data-urlencode "login=khufia.manager@taxnest.com" --data-urlencode "password=$KHUFIA_QA_PASS" $B/pos/login
curl -s -b jar -c jar -H "$H" $B/pos/transactions | grep -c "tab=local"   # expect 0
curl -s -b jar -c jar -H "$H" -X POST -H "X-Requested-With: XMLHttpRequest" \
  -H "Accept: application/json" $B/pos/api/local-check-toggle              # expect {"on":true}
curl -s -b jar -c jar -H "$H" $B/pos/transactions | grep -c "tab=local"   # expect 1
curl -s -b jar -c jar -H "$H" $B/pos/transactions | grep -c "14b8a6"      # teal dot, expect 1
# toggle again -> {"on":false}, both greps back to 0
```

## Pass criteria

All three flows green. Any of these = regression: Local tab visible to manager
by default; toggle/dot missing; switch lands on wrong identity or stale cached
page; unlinked cashier sees ANY visible reaction.
