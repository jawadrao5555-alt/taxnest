<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link rel="stylesheet" href="{{ asset('css/mobile.css') }}?v=1.2">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>PWA Diagnostics — TaxNest</title>
    <link rel="icon" href="/icons/tax-di/icon-192.png">
    <style>
        *,*::before,*::after{box-sizing:border-box;font-family:'Inter',system-ui,-apple-system,sans-serif}
        body{margin:0;background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%);color:#f1f5f9;min-height:100vh;padding:24px}
        .wrap{max-width:780px;margin:0 auto}
        .header{display:flex;align-items:center;gap:14px;margin-bottom:28px}
        .header img{width:56px;height:56px;border-radius:12px;box-shadow:0 8px 20px rgba(5,150,105,.4)}
        .header h1{margin:0;font-size:24px;font-weight:800}
        .header p{margin:4px 0 0;font-size:13px;color:#94a3b8}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;margin-bottom:24px}
        .card{background:rgba(30,41,59,.7);backdrop-filter:blur(8px);border:1px solid rgba(148,163,184,.15);border-radius:14px;padding:18px}
        .card .label{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;font-weight:600;margin-bottom:8px}
        .card .value{font-size:18px;font-weight:700;display:flex;align-items:center;gap:8px}
        .dot{width:10px;height:10px;border-radius:50%;display:inline-block}
        .dot.ok{background:#10b981;box-shadow:0 0 12px #10b981}
        .dot.bad{background:#ef4444;box-shadow:0 0 12px #ef4444}
        .dot.warn{background:#f59e0b;box-shadow:0 0 12px #f59e0b}
        .detail{font-size:12px;color:#cbd5e1;margin-top:6px;word-break:break-all}
        .section{background:rgba(30,41,59,.7);border:1px solid rgba(148,163,184,.15);border-radius:14px;padding:20px;margin-bottom:18px}
        .section h2{margin:0 0 14px;font-size:15px;font-weight:700;color:#f8fafc}
        .row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px dashed rgba(148,163,184,.15);font-size:13px}
        .row:last-child{border:none}
        .row .k{color:#94a3b8}
        .row .v{color:#f1f5f9;font-weight:600;text-align:right;max-width:60%;word-break:break-all}
        .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
        .btn{padding:10px 16px;border-radius:10px;border:none;cursor:pointer;font-size:13px;font-weight:600;transition:all .2s}
        .btn-primary{background:linear-gradient(135deg,#059669,#047857);color:white}
        .btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(5,150,105,.4)}
        .btn-ghost{background:rgba(148,163,184,.15);color:#cbd5e1}
        .btn-ghost:hover{background:rgba(148,163,184,.25)}
        .footer{text-align:center;font-size:11px;color:#64748b;margin-top:24px}
        .scope-badge{display:inline-block;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:700;text-transform:uppercase;margin-left:6px}
        .scope-badge.di{background:rgba(5,150,105,.2);color:#34d399}
        .scope-badge.pos{background:rgba(124,58,237,.2);color:#a78bfa}
        .scope-badge.fbrpos{background:rgba(30,58,95,.4);color:#60a5fa}
    </style>
</head>
<body>
<div class="wrap">
    <div class="header">
        <img src="/icons/tax-di/icon-192.png" alt="">
        <div>
            <h1>PWA Diagnostics</h1>
            <p>Real-time status of installation, notifications & offline cache</p>
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <div class="label">Service Worker</div>
            <div class="value"><span class="dot" id="d-sw"></span><span id="v-sw">Checking…</span></div>
            <div class="detail" id="x-sw"></div>
        </div>
        <div class="card">
            <div class="label">Installed App</div>
            <div class="value"><span class="dot" id="d-install"></span><span id="v-install">Checking…</span></div>
            <div class="detail" id="x-install"></div>
        </div>
        <div class="card">
            <div class="label">Notifications</div>
            <div class="value"><span class="dot" id="d-notif"></span><span id="v-notif">Checking…</span></div>
            <div class="detail" id="x-notif"></div>
        </div>
        <div class="card">
            <div class="label">Push Subscription</div>
            <div class="value"><span class="dot" id="d-push"></span><span id="v-push">Checking…</span></div>
            <div class="detail" id="x-push"></div>
        </div>
    </div>

    <div class="section">
        <h2>Offline Cache</h2>
        <div id="caches-list"><div class="row"><span class="k">Loading…</span><span class="v">—</span></div></div>
    </div>

    <div class="section">
        <h2>Environment</h2>
        <div class="row"><span class="k">User Agent</span><span class="v" id="e-ua">—</span></div>
        <div class="row"><span class="k">Network</span><span class="v" id="e-net">—</span></div>
        <div class="row"><span class="k">Display Mode</span><span class="v" id="e-dm">—</span></div>
        <div class="row"><span class="k">VAPID Server Key</span><span class="v" id="e-vapid">—</span></div>
        <div class="row"><span class="k">Local Notify Helper</span><span class="v" id="e-tnnotify">—</span></div>
    </div>

    <div class="actions">
        <button class="btn btn-primary" onclick="testNotif()">Send Test Notification</button>
        <button class="btn btn-ghost" onclick="checkUpdate()">Check for Updates</button>
        <button class="btn btn-ghost" onclick="clearCaches()">Clear All Caches</button>
        <button class="btn btn-ghost" onclick="location.reload()">Refresh</button>
    </div>

    <div class="footer">TaxNest PWA Diagnostics &middot; v12 &middot; Built {{ date('Y-m-d') }}</div>
</div>

<script>
const VAPID_PUBLIC = @json(config('services.vapid.public') ?: null);

function set(id, html){ const el=document.getElementById(id); if(el) el.innerHTML=html; }
function dot(id, s){ const el=document.getElementById(id); if(el){ el.className='dot '+s; } }

(async function(){
    set('e-ua', navigator.userAgent.slice(0,80));
    set('e-net', navigator.onLine ? '<span class="dot ok"></span> Online' : '<span class="dot bad"></span> Offline');
    set('e-dm', (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone) ? 'Standalone (installed)' : 'Browser tab');
    set('e-vapid', VAPID_PUBLIC ? '<span class="dot ok"></span> Configured' : '<span class="dot warn"></span> Not set (local notifications only)');
    set('e-tnnotify', (typeof window.tnNotify === 'function') ? '<span class="dot ok"></span> Available' : '<span class="dot warn"></span> Not loaded on this page');

    if ('serviceWorker' in navigator) {
        try {
            const reg = await navigator.serviceWorker.getRegistration();
            if (reg) {
                dot('d-sw','ok');
                set('v-sw','Active');
                set('x-sw','Scope: ' + reg.scope + (reg.active?.scriptURL ? '<br>Script: ' + reg.active.scriptURL.split('/').pop() : ''));
            } else {
                dot('d-sw','bad'); set('v-sw','Not registered'); set('x-sw','Reload after login to install.');
            }
        } catch(e){ dot('d-sw','bad'); set('v-sw','Error'); set('x-sw',e.message); }
    } else { dot('d-sw','bad'); set('v-sw','Unsupported'); }

    const installed = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
    if (installed) { dot('d-install','ok'); set('v-install','Installed'); set('x-install','Running as standalone app'); }
    else { dot('d-install','warn'); set('v-install','Not installed'); set('x-install','Click install button on login page'); }

    if ('Notification' in window) {
        const p = Notification.permission;
        if (p === 'granted') { dot('d-notif','ok'); set('v-notif','Allowed'); }
        else if (p === 'denied') { dot('d-notif','bad'); set('v-notif','Blocked'); set('x-notif','Reset in browser site settings.'); }
        else { dot('d-notif','warn'); set('v-notif','Not asked yet'); set('x-notif','Visit dashboard to be prompted.'); }
    } else { dot('d-notif','bad'); set('v-notif','Unsupported'); }

    if ('serviceWorker' in navigator && 'PushManager' in window) {
        try {
            const reg = await navigator.serviceWorker.ready;
            const sub = await reg.pushManager.getSubscription();
            if (sub) {
                dot('d-push','ok'); set('v-push','Subscribed');
                set('x-push','Endpoint: ' + sub.endpoint.slice(0,60) + '…');
            } else {
                dot('d-push','warn'); set('v-push','Not subscribed');
                set('x-push', VAPID_PUBLIC ? 'Server push ready — visit dashboard to subscribe.' : 'Local-only mode (no VAPID).');
            }
        } catch(e){ dot('d-push','bad'); set('v-push','Error'); set('x-push',e.message); }
    } else { dot('d-push','bad'); set('v-push','Unsupported'); }

    // Caches
    if ('caches' in window) {
        try {
            const names = await caches.keys();
            if (!names.length) { document.getElementById('caches-list').innerHTML='<div class="row"><span class="k">No caches</span><span class="v">—</span></div>'; return; }
            let html = '';
            for (const n of names) {
                const c = await caches.open(n);
                const ks = await c.keys();
                html += '<div class="row"><span class="k">'+n+'</span><span class="v">'+ks.length+' items</span></div>';
            }
            document.getElementById('caches-list').innerHTML = html;
        } catch(e){ document.getElementById('caches-list').innerHTML='<div class="row"><span class="k">Error</span><span class="v">'+e.message+'</span></div>'; }
    }
})();

async function testNotif(){
    if (!('Notification' in window)) { alert('Notifications not supported on this browser'); return; }
    if (Notification.permission === 'default') {
        const p = await Notification.requestPermission();
        if (p !== 'granted') { alert('Permission required'); return; }
    }
    if (Notification.permission === 'denied') { alert('Notifications blocked. Enable in site settings.'); return; }
    new Notification('TaxNest Test Notification', { body: 'If you can read this, notifications work!', icon: '/icons/tax-di/icon-192.png', tag: 'pwa-test' });
}
async function checkUpdate(){
    if (!('serviceWorker' in navigator)) return alert('Service worker not supported');
    const reg = await navigator.serviceWorker.getRegistration();
    if (!reg) return alert('No service worker registered');
    await reg.update();
    alert('Checked for updates. If a new version was found, the update toast will appear.');
}
async function clearCaches(){
    if (!confirm('Delete all offline cache? You may need to reload.')) return;
    if ('caches' in window) {
        const names = await caches.keys();
        await Promise.all(names.map(n => caches.delete(n)));
    }
    alert('Caches cleared. Reloading…');
    location.reload();
}
</script>
</body>
</html>
