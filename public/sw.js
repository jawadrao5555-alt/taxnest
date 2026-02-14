const CACHE_NAME = 'taxnest-v5';
const OFFLINE_PAGE = '/offline-splash';
const ASSETS_TO_CACHE = [
    '/manifest.json',
    '/icons/icon-192.png',
    '/icons/icon-512.png'
];

const OFFLINE_SPLASH_HTML = `
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TaxNest - Offline</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', 'Fira Sans', 'Droid Sans', 'Helvetica Neue', sans-serif;
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        .offline-container {
            text-align: center;
            background: white;
            border-radius: 12px;
            padding: 40px 30px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            max-width: 400px;
        }
        
        .logo {
            font-size: 48px;
            margin-bottom: 20px;
        }
        
        h1 {
            color: #059669;
            font-size: 28px;
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .subtitle {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        
        .offline-icon {
            font-size: 64px;
            margin: 20px 0;
            opacity: 0.7;
        }
        
        .status-message {
            color: #374151;
            font-size: 14px;
            padding: 15px;
            background: #f3f4f6;
            border-radius: 8px;
            margin-top: 20px;
            border-left: 4px solid #fbbf24;
        }
        
        .retry-hint {
            color: #9ca3af;
            font-size: 12px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="offline-container">
        <div class="logo">🧾</div>
        <h1>TaxNest</h1>
        <p class="subtitle">Pakistan's Smart FBR-Compliant Tax & Invoice Management Platform</p>
        
        <div class="offline-icon">📡</div>
        
        <div class="status-message">
            <strong>You're Offline</strong>
            <p style="margin-top: 8px;">TaxNest requires an internet connection to work properly. Please check your connection and try again.</p>
        </div>
        
        <p class="retry-hint">This page will auto-refresh when your connection is restored.</p>
    </div>
    
    <script>
        window.addEventListener('online', () => {
            window.location.reload();
        });
    </script>
</body>
</html>
`;

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            return Promise.all([
                cache.addAll(ASSETS_TO_CACHE),
                cache.put(OFFLINE_PAGE, new Response(OFFLINE_SPLASH_HTML, {
                    headers: { 'Content-Type': 'text/html' }
                }))
            ]);
        })
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
            fetch(event.request).catch(() => caches.match(OFFLINE_PAGE))
        );
        return;
    }

    const url = new URL(event.request.url);
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(fetch(event.request));
        return;
    }

    event.respondWith(
        fetch(event.request)
            .then(response => response)
            .catch(() => caches.match(event.request))
    );
});
