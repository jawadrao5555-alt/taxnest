// TaxNest Suite Service Worker — Tax DI / Nest Pra Pos / Nest FBR Pos
// Strategy: Stale-while-revalidate for static assets, network-first for HTML, offline fallback.
const CACHE_VERSION = 'taxnest-20260905-agent-bridge-popup'; // refresh cached panel pages for the unified seven-day Agent/domain/reliability notice
const STATIC_CACHE = `${CACHE_VERSION}-static`;
const RUNTIME_CACHE = `${CACHE_VERSION}-runtime`;
// OFFLINE-FIRST SALE SCREEN (Jul 2026): dedicated cache for /pos/invoice/create
// served CACHE-FIRST (instant boot on shop internet) with background revalidate.
// The page carries a baked fingerprint (window.tnBootFp) and self-reloads via
// /pos/api/boot-check when stale. Purged on ANY logout AND any /login POST
// (per-user data is baked into the HTML — audit rule, July 2026).
const SALE_CACHE = `${CACHE_VERSION}-sale`;
// A cached sale page contains authenticated, per-user boot data. "200 + HTML" is
// not enough proof that it is a usable sale document: a partially rendered error
// page, login page, or interrupted response would otherwise be replayed forever
// by the cache-first navigation path. Keep the markers deliberately structural,
// not product-data based, so an intentionally empty catalogue remains valid.
const SALE_DOCUMENT_MARKERS = {
    '/pos/invoice/create': 'pra',
    '/fbr-pos/create': 'fbr',
};
const SALE_DOCUMENT_HEADER = 'x-taxnest-sale-document';
// OFFLINE-FIRST TABLES BOARD (Task 819, Aug 2026): dedicated cache for
// /pos/restaurant/tables — network-first, offline fallback to last snapshot.
// Tables-first shops navigate here after every KOT/payment;
// cache lets the board open even with no internet (last-known snapshot).
// Purged on logout so the next staff member never sees stale table statuses.
const TABLES_CACHE = `${CACHE_VERSION}-tables`;
const OFFLINE_PAGE = '/offline-splash';

// Task 865: durable cache name for per-client Tables serve-mode flags.
// Fixed (non-versioned) so it survives SW updates and is readable from page JS.
// Keys: 'cache-serve-{clientId}' — written (awaited) before the cached response
// is returned, so the flag is guaranteed present when the page reads it.
// The page gets its own clientId via a lightweight echo postMessage
// (TN_TABLES_QUERY_CLIENT_ID → e.source.id), then reads + consumes (deletes)
// the entry directly from the Cache API.
// Survives SW termination between navigate and page query — no in-memory state.
const TN_TABLES_META = 'tn-tables-meta';

const STATIC_ASSETS = [
    '/manifest.json',
    '/manifest-pos.json',
    '/manifest-fbrpos.json',
    '/icons/tax-di/icon-192.png',
    '/icons/tax-di/icon-512.png',
    '/icons/nest-pra/icon-192.png',
    '/icons/nest-pra/icon-512.png',
    '/icons/nest-fbr/icon-192.png',
    '/icons/nest-fbr/icon-512.png',
];

const OFFLINE_HTML = `<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TaxNest — Offline</title><style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:linear-gradient(135deg,#059669,#047857);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;color:#fff}.box{text-align:center;background:rgba(255,255,255,.08);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,.15);border-radius:20px;padding:50px 40px;max-width:420px;box-shadow:0 25px 60px rgba(0,0,0,.3)}.ico{font-size:64px;margin-bottom:20px}h1{font-size:28px;margin-bottom:10px;font-weight:700}.sub{opacity:.85;font-size:15px;margin-bottom:30px;line-height:1.6}.status{background:rgba(0,0,0,.2);padding:16px;border-radius:12px;border-left:4px solid #fbbf24;text-align:left;font-size:14px}.btn{margin-top:24px;display:inline-block;background:#fff;color:#059669;padding:12px 28px;border-radius:10px;font-weight:600;text-decoration:none;border:none;cursor:pointer;font-size:15px}</style></head><body><div class="box"><div class="ico">📡</div><h1>You're Offline</h1><p class="sub">Internet connection nahi hai. Cached pages khol sakte hain — ya net wapas aane par auto-reload hoga.</p><div class="status"><strong>Tip:</strong> Net aate hi yeh page khud refresh ho jayega.</div><button class="btn" onclick="location.reload()">Try Again</button></div><script>window.addEventListener('online',()=>location.reload());setInterval(()=>{if(navigator.onLine)location.reload()},5000)</script></body></html>`;
const SALE_RECOVERY_HTML = `<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TaxNest — Sale Screen Recovery</title><style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#f8fafc;color:#172033;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.box{max-width:440px;text-align:center;background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:34px;box-shadow:0 18px 42px rgba(15,23,42,.11)}h1{font-size:21px;margin:0 0 10px}p{color:#64748b;line-height:1.55;margin:0 0 22px}button{border:0;border-radius:10px;background:#4f46e5;color:#fff;font-weight:700;padding:12px 22px;font-size:15px;cursor:pointer}</style></head><body><main class="box"><h1>Sale screen needs a fresh copy</h1><p>The saved screen was incomplete, so it was not opened. Check the internet connection and try again.</p><button onclick="location.reload()">Try again</button></main></body></html>`;

function saleDocumentLooksLikeLogin(response) {
    try {
        return response.redirected || /\/(?:pos|fbr-pos)\/login(?:[/?#]|$)/.test(new URL(response.url || '', location.origin).pathname);
    } catch (_) {
        return !!response.redirected;
    }
}

async function isValidSaleDocument(response, variant) {
    const contentType = response && response.headers && response.headers.get('content-type') || '';
    if (!response || !response.ok || response.redirected || !contentType.includes('text/html')) return false;
    try {
        const html = await response.clone().text();
        return html.length > 4096
            && html.includes(`data-tn-sale-document="${variant}"`)
            && html.includes('data-tn-sale-root')
            && html.includes('function restaurantPos()')
            && html.includes('window.tnBootFp');
    } catch (_) {
        return false;
    }
}

async function isValidCachedSaleDocument(response, variant) {
    if (!response || !response.ok || response.redirected) return false;
    // Current server responses are stamped after Laravel has rendered the full
    // universal view. Network writes still undergo the body-marker validation
    // below before cache.put(), so this fast header check is safe and avoids
    // decoding a multi-megabyte catalogue twice on every cache-first open.
    if ((response.headers.get(SALE_DOCUMENT_HEADER) || '') === variant) return true;
    // One-time upgrade path for a cache written by an older worker: accept it
    // only if the new structural markers are really present.
    return isValidSaleDocument(response, variant);
}

async function fetchSaleDocument(request, cache, variant) {
    const response = await fetch(request);
    const valid = await isValidSaleDocument(response, variant);
    if (valid) await cache.put(request, response.clone());
    return { response, valid };
}

function saleRecoveryResponse() {
    return new Response(SALE_RECOVERY_HTML, {
        status: 503,
        headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' },
    });
}

self.addEventListener('install', e => {
    e.waitUntil(
        caches.open(STATIC_CACHE).then(c =>
            Promise.all([
                c.addAll(STATIC_ASSETS).catch(()=>{}),
                c.put(OFFLINE_PAGE, new Response(OFFLINE_HTML, { headers: { 'Content-Type': 'text/html' } }))
            ])
        )
    );
    // NOTE: Don't auto-skipWaiting — let the client (pwa-update toast / pwa-refresh-btn) opt-in via SKIP_WAITING.
    // This prevents jarring mid-task reloads. First-time installs activate immediately anyway (no controller to wait for).
});

self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => !k.startsWith(CACHE_VERSION)).map(k => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', e => {
    const req = e.request;
    const url = new URL(req.url);
    if (url.origin !== location.origin) return;

    // Session hygiene: ANY logout (DI /logout, /pos/logout, /fbr-pos/logout, admin, franchise —
    // GET or POST) purges all cached authenticated pages so the next user on a shared
    // terminal can never see the previous session's pages, even offline.
    if (url.pathname.includes('/logout')) {
        e.waitUntil(Promise.all([caches.delete(RUNTIME_CACHE), caches.delete(SALE_CACHE), caches.delete(TABLES_CACHE), caches.delete(TN_TABLES_META)]));
        return;
    }

    // User-switch hygiene: a LOGIN submit means a (possibly different) user is
    // starting a session — drop the cached sale screen, it bakes per-user data
    // (PRA toggle, role gates, discount limit, grid prefs).
    if (req.method === 'POST' && url.pathname.includes('/login')) {
        // Tables board is cache-first + bakes per-session data — must be purged
        // alongside the sale screen on user-switch so a new login never sees the
        // previous session's table snapshot (cross-user exposure on shared terminals).
        e.waitUntil(Promise.all([caches.delete(SALE_CACHE), caches.delete(TABLES_CACHE), caches.delete(TN_TABLES_META)]));
        return;
    }

    if (req.method !== 'GET') return;

    // OFFLINE-FIRST SALE SCREEN: exact match only — query-string variants
    // (?table_id, ?edit_bill, ?updated) stay network-only via skipPatterns.
    // Cache-first + background revalidate, except a browser hard reload which
    // deliberately prefers a fresh network document. Both cache reads and writes
    // validate sale-specific boot markers; login/error/partial HTML must never
    // become the active offline copy. Offline with no valid copy → recovery page.
    if (req.mode === 'navigate' && SALE_DOCUMENT_MARKERS[url.pathname] && url.search === '') {
        e.respondWith((async () => {
            const c = await caches.open(SALE_CACHE);
            const variant = SALE_DOCUMENT_MARKERS[url.pathname];
            let cached = await c.match(req);
            if (cached && !(await isValidCachedSaleDocument(cached, variant))) {
                await c.delete(req);
                cached = undefined;
            }
            const network = () => fetchSaleDocument(req, c, variant);
            // Chrome marks a hard refresh as cache:'reload'. Some terminals send
            // Cache-Control:no-cache instead, so honor that signal too. A live
            // document always wins; a broken 5xx/network result safely falls back
            // to the last validated screen rather than leaving a blank POS.
            const forceFresh = req.cache === 'reload'
                || /(?:^|,)\s*no-cache\s*(?:,|$)/i.test(req.headers.get('cache-control') || '');
            if (forceFresh) {
                try {
                    const fresh = await network();
                    if (saleDocumentLooksLikeLogin(fresh.response)) await c.delete(req);
                    if (fresh.valid || !cached || saleDocumentLooksLikeLogin(fresh.response)) {
                        return fresh.valid || saleDocumentLooksLikeLogin(fresh.response)
                            ? fresh.response : saleRecoveryResponse();
                    }
                    return cached;
                } catch (_) {
                    return cached || (await caches.match(OFFLINE_PAGE)) || saleRecoveryResponse();
                }
            }
            if (cached) {
                network().then(fresh => {
                    // A background auth redirect means this session is no longer
                    // usable. Delete the personal document immediately; its own
                    // boot check will redirect the already-open page to login.
                    if (saleDocumentLooksLikeLogin(fresh.response)) c.delete(req);
                }).catch(() => {}); // background revalidate — cache updated for next boot
                return cached;
            }
            try {
                const fresh = await network();
                return fresh.valid || saleDocumentLooksLikeLogin(fresh.response)
                    ? fresh.response : saleRecoveryResponse();
            } catch (_) {
                return (await caches.match(OFFLINE_PAGE)) || saleRecoveryResponse();
            }
        })());
        return;
    }

    // TABLES BOARD (Task 819): exact match on /pos/restaurant/tables (no query string).
    // Network-FIRST: table statuses must always be fresh after every KOT/payment
    // transition — cache-first would return stale status on the very navigation this
    // flow triggers. Cache is updated on every successful network response so that an
    // offline fallback exists; on fetch failure serve the last snapshot (with the
    // auto-reload banner handled inside tables.blade).
    //
    // Task 865: when serving from TABLES_CACHE, write a durable per-client flag to
    // TN_TABLES_META cache (awaited before returning the response — guaranteed present
    // when the page reads it). The page gets its own clientId via a lightweight echo
    // postMessage (TN_TABLES_QUERY_CLIENT_ID → e.source.id), then reads and consumes
    // the flag directly from the Cache API. Survives SW termination between navigate
    // and page query; keyed by clientId so concurrent tabs don't interfere.
    if (req.mode === 'navigate' && url.pathname === '/pos/restaurant/tables' && url.search === '') {
        const resultingClientId = e.resultingClientId;
        e.respondWith((async () => {
            const c = await caches.open(TABLES_CACHE);
            try {
                const res = await fetch(req);
                const ct = res.headers.get('content-type') || '';
                if (res.ok && !res.redirected && ct.includes('text/html')) {
                    c.put(req, res.clone());
                    // Fresh network serve: remove any stale cached-serve flag for this client.
                    if (resultingClientId) {
                        caches.open(TN_TABLES_META)
                            .then(mc => mc.delete(self.location.origin + '/__tn_tables_meta_' + resultingClientId))
                            .catch(() => {});
                    }
                }
                return res;
            } catch (err) {
                // Offline / server unreachable: serve last-known snapshot.
                // tables.blade auto-reloads on 'online' event.
                const cached = await c.match(req);
                if (cached) {
                    // AWAITED before `return cached` — flag is durably stored before
                    // the browser parses the cached HTML and scripts execute.
                    // Survives SW termination; page reads from Cache API directly.
                    if (resultingClientId) {
                        try {
                            const mc = await caches.open(TN_TABLES_META);
                            await mc.put(self.location.origin + '/__tn_tables_meta_' + resultingClientId,
                                new Response('1', { headers: { 'Content-Type': 'text/plain' } }));
                        } catch (_) {}
                    }
                    return cached;
                }
                return (await caches.match(OFFLINE_PAGE)) || Response.error();
            }
        })());
        return;
    }

    // Never cache: auth, API, admin, agent, FBR submit, payment posting, livewire, debugbar
    // '/pos/receipt-settings' (Task 1377): a runtime-cached copy of this page is
    // served whenever the network blips, and its form then POSTs an OLD field set —
    // toggles that did not exist when the copy was cached are silently ignored or
    // wiped (owner 21 Aug 2026: "QR uncheck karne ke baad bhi QR chhap raha hai",
    // local bill lost its tax line). '/fbr-pos/receipt-settings' was already skipped
    // for the same reason; the PRA page was missed.
    //
    // Task 1393 — SETTINGS PAGES ARE NEVER CACHED, AND ALWAYS IN SIBLING PAIRS.
    // Every page below renders a form that rebuilds whole option blocks from
    // checkbox presence, so serving an outdated copy is enough to switch a shop's
    // options off. Note the patterns are substring matches and '/pos/x' does NOT
    // match '/fbr-pos/x' (the char before "pos" is '-', not '/') — that asymmetry
    // is exactly how the PRA receipt page was missed while its FBR twin was listed.
    // When a new settings page is added, add BOTH panels' paths here.
    const SETTINGS_PAGES = [
        '/pos/receipt-settings',            '/fbr-pos/receipt-settings',
        '/pos/business-profile',            '/fbr-pos/business-profile',
        '/pos/printer-settings',            '/fbr-pos/printer-settings',
        '/pos/customize',                   '/fbr-pos/customize',
        '/pos/pra-settings',                '/fbr-pos/settings',
        '/pos/features',                    // PRA-only (Customize wizard; FBR has no twin)
        '/pos/restaurant/kitchen-settings', // PRA-only (KOT settings)
    ];
    const skipPatterns = ['/api/', '/login', '/logout', '/register', '/admin/', '/agent/', '/livewire/', '/_debugbar/', '/setup-', '/sanctum/', '/broadcasting/', '/pos/invoice/create', '/pos/v2/invoice/create', '/pos/create-invoice', '/fbr-pos/create', '/edit-failed', '/pos/restaurant/kds', '/pos/waiter', '/proof-bill', '/pos/customers', '/pos/riders/tracking', '/fbr-pos/held/', '/fbr-pos/transaction/', '/return', '/pos/restaurant/tables', '/track/', ...SETTINGS_PAGES];
    if (skipPatterns.some(p => url.pathname.includes(p))) return;

    // HTML pages: network-first → cache → offline page
    if (req.mode === 'navigate') {
        e.respondWith(
            fetch(req).then(res => {
                if (res.ok) {
                    const copy = res.clone();
                    caches.open(RUNTIME_CACHE).then(c => c.put(req, copy));
                }
                return res;
            }).catch(() => caches.match(req).then(r => r || caches.match(OFFLINE_PAGE)))
        );
        return;
    }

    // Vite hashed build assets (immutable): cache-first (no revalidation)
    if (url.pathname.startsWith('/build/')) {
        e.respondWith(
            caches.match(req).then(cached => cached || fetch(req).then(res => {
                if (res.ok) {
                    const copy = res.clone();
                    caches.open(STATIC_CACHE).then(c => c.put(req, copy));
                }
                return res;
            }))
        );
        return;
    }

    // Other static assets (icons, fonts, images, stray css/js): stale-while-revalidate
    const isStatic = /\.(css|js|png|jpg|jpeg|svg|gif|webp|woff2?|ttf|ico)$/i.test(url.pathname)
        || url.pathname.startsWith('/icons/')
        || url.pathname.startsWith('/img/');
    if (isStatic) {
        e.respondWith(
            caches.match(req).then(cached => {
                const fetchPromise = fetch(req).then(res => {
                    if (res.ok) {
                        const copy = res.clone();
                        caches.open(STATIC_CACHE).then(c => c.put(req, copy));
                    }
                    return res;
                }).catch(() => cached);
                return cached || fetchPromise;
            })
        );
        return;
    }

    // Default: network-first, fall back to cache
    e.respondWith(
        fetch(req).catch(() => caches.match(req))
    );
});

// === PUSH NOTIFICATION SUPPORT (mall-grade alerts) ===
self.addEventListener('push', e => {
    let data = { title: 'TaxNest', body: 'New notification', icon: '/icons/tax-di/icon-192.png', tag: 'general', url: '/' };
    if (e.data) {
        try { data = { ...data, ...e.data.json() }; } catch (_) { data.body = e.data.text(); }
    }
    e.waitUntil(self.registration.showNotification(data.title, {
        body: data.body,
        icon: data.icon,
        badge: data.badge || data.icon,
        tag: data.tag,
        data: { url: data.url, ...(data.data || {}) },
        requireInteraction: data.requireInteraction || false,
        silent: false,
        vibrate: [200, 100, 200],
        actions: data.actions || []
    }));
});

self.addEventListener('notificationclick', e => {
    e.notification.close();
    const targetUrl = (e.notification.data && e.notification.data.url) || '/';
    e.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clientList => {
            for (const client of clientList) {
                if (client.url.includes(targetUrl) && 'focus' in client) return client.focus();
            }
            if (self.clients.openWindow) return self.clients.openWindow(targetUrl);
        })
    );
});

// Notify clients when a new SW version is ready
self.addEventListener('message', e => {
    if (e.data && e.data.type === 'SKIP_WAITING') self.skipWaiting();
    // Sale screen detected it is stale (boot fingerprint mismatch) — drop the
    // cached copy so its imminent reload fetches fresh from the network.
    if (e.data && e.data.type === 'TN_DROP_SALE_CACHE') e.waitUntil(caches.delete(SALE_CACHE));
    // Task 644 (ZFC, Aug 2026): SALE_CACHE re-prime after a browser-data clear.
    // The very first navigation after a clear is NOT SW-controlled (the SW
    // registers post-load), so SALE_CACHE stayed empty and the SECOND open was
    // still a full network fetch on shop internet. The sale screen posts this
    // when it boots with no controller — fetch+cache it once in the background
    // so the next open is offline-first again. URL is whitelisted (never cache
    // arbitrary pages into SALE_CACHE); redirects/non-HTML rejected same as the
    // navigate path above.
    if (e.data && e.data.type === 'TN_PRIME_SALE_CACHE') {
        e.waitUntil((async () => {
            try {
                const saleUrls = ['/pos/invoice/create', '/fbr-pos/create'];
                const c = await caches.open(SALE_CACHE);
                await Promise.all(saleUrls.map(async (u) => {
                    const cached = await c.match(u);
                    if (cached && await isValidCachedSaleDocument(cached, SALE_DOCUMENT_MARKERS[u])) return;
                    if (cached) await c.delete(u);
                    const res = await fetch(u, { credentials: 'same-origin' });
                    if (await isValidSaleDocument(res, SALE_DOCUMENT_MARKERS[u])) await c.put(u, res.clone());
                }));
            } catch (err) { /* best-effort — normal second-load prime still applies */ }
        })());
    }
    // Task 823 (Aug 2026): TABLES_CACHE re-prime after a browser-data clear.
    // Same gap as TN_PRIME_SALE_CACHE above: the first /pos/restaurant/tables
    // visit after a clear is NOT SW-controlled, so TABLES_CACHE stayed empty
    // and a Tables-first shop going offline right after a reset hit the
    // offline splash instead of the cached board. tables.blade posts this when
    // it boots with no controller — fetch+cache once in the background. Fixed
    // URL (never cache arbitrary pages); redirects/non-HTML rejected same as
    // the navigate path above.
    if (e.data && e.data.type === 'TN_PRIME_TABLES_CACHE') {
        e.waitUntil((async () => {
            try {
                const url = '/pos/restaurant/tables';
                const c = await caches.open(TABLES_CACHE);
                if (await c.match(url)) return; // already primed
                const res = await fetch(url, { credentials: 'same-origin' });
                const ct = res.headers.get('content-type') || '';
                if (res.ok && !res.redirected && ct.includes('text/html')) await c.put(url, res.clone());
            } catch (err) { /* best-effort — next online visit still primes via navigate path */ }
        })());
    }
    // Task 865: lightweight echo — page asks "what is my client ID?" so it can
    // look up its own per-client cache-serve flag in TN_TABLES_META without the
    // SW needing any in-memory state (survives SW termination). The flag itself
    // is stored durably in the Cache API and consumed (deleted) by the page after
    // reading. e.source.id equals e.resultingClientId recorded at navigate time.
    if (e.data && e.data.type === 'TN_TABLES_QUERY_CLIENT_ID') {
        if (e.ports && e.ports[0]) {
            e.ports[0].postMessage({ type: 'TN_TABLES_CLIENT_ID_RESP', clientId: e.source && e.source.id });
        }
    }
});
