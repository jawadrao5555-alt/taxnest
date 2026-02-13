const CACHE_NAME = 'taxnest-v2';
const OFFLINE_URL = '/offline';
const ASSETS_TO_CACHE = [
    '/',
    '/manifest.json',
    '/icons/icon-192.png',
    '/icons/icon-512.png'
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => cache.addAll(ASSETS_TO_CACHE))
    );
    self.skipWaiting();
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => 
            Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', event => {
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).catch(() => caches.match('/'))
        );
        return;
    }
    event.respondWith(
        caches.match(event.request).then(cached => cached || fetch(event.request))
    );
});
