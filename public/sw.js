// TaxNest Suite Service Worker — Tax DI / Nest Pra Pos / Nest FBR Pos
// Strategy: Stale-while-revalidate for static assets, network-first for HTML, offline fallback.
const CACHE_VERSION = 'taxnest-v41';
const STATIC_CACHE = `${CACHE_VERSION}-static`;
const RUNTIME_CACHE = `${CACHE_VERSION}-runtime`;
const OFFLINE_PAGE = '/offline-splash';

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
        e.waitUntil(caches.delete(RUNTIME_CACHE));
        return;
    }

    if (req.method !== 'GET') return;

    // Never cache: auth, API, admin, agent, FBR submit, payment posting, livewire, debugbar
    const skipPatterns = ['/api/', '/login', '/logout', '/register', '/admin/', '/agent/', '/livewire/', '/_debugbar/', '/setup-', '/sanctum/', '/broadcasting/', '/pos/invoice/create', '/pos/v2/invoice/create', '/pos/create-invoice', '/fbr-pos/create', '/edit-failed', '/pos/restaurant/kds', '/pos/waiter'];
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
});
