{{--
PWA Push Notification helper.
- Asks user for notification permission once (after first load)
- Subscribes to push via VAPID (if configured) or registers for local notifications
- Exposes window.tnNotify(title, body, opts) for in-app local toasts (works even when minimized)
Usage on dashboards: <x-pwa-push scope="di" />  scope: di | pos | fbrpos
--}}
@props(['scope' => 'di'])
@php
    $vapidPublic = config('services.vapid.public_key', env('VAPID_PUBLIC_KEY', ''));
@endphp
<script>
(function(){
    const SCOPE = @json($scope);
    const VAPID_PUB = @json($vapidPublic);
    const PROMPT_KEY = 'tnPushAsked_' + SCOPE;

    // Public local-notification helper — usable from anywhere via window.tnNotify(...)
    window.tnNotify = function(title, body, opts){
        opts = opts || {};
        if (!('Notification' in window)) return;
        if (Notification.permission !== 'granted') return;
        try {
            navigator.serviceWorker.getRegistration().then(reg => {
                const fn = reg ? reg.showNotification.bind(reg) : (t,o)=>new Notification(t,o);
                fn(title, {
                    body: body || '',
                    icon: opts.icon || '/icons/' + (SCOPE==='pos'?'nest-pra':SCOPE==='fbrpos'?'nest-fbr':'tax-di') + '/icon-192.png',
                    badge: opts.badge,
                    tag: opts.tag || 'tn-' + Date.now(),
                    data: { url: opts.url || location.pathname },
                    requireInteraction: !!opts.sticky,
                    vibrate: [200,100,200]
                });
            });
        } catch(_) {}
    };

    // Listen for app-fired events to surface as notifications
    window.addEventListener('tn:notify', e => {
        const d = e.detail || {};
        window.tnNotify(d.title || 'TaxNest', d.body || '', d);
    });

    if (!('serviceWorker' in navigator) || !('Notification' in window) || !('PushManager' in window)) return;

    // Skip auto-prompt: only ask once user clicks somewhere (browser policy).
    function urlBase64ToUint8Array(b64){
        const padding='='.repeat((4-b64.length%4)%4);
        const base64=(b64+padding).replace(/-/g,'+').replace(/_/g,'/');
        const raw=atob(base64); const arr=new Uint8Array(raw.length);
        for(let i=0;i<raw.length;i++) arr[i]=raw.charCodeAt(i);
        return arr;
    }

    async function subscribePush(){
        try {
            const reg = await navigator.serviceWorker.ready;
            if (!VAPID_PUB) return; // no server keys yet — local notifs still work
            let sub = await reg.pushManager.getSubscription();
            if (!sub) {
                sub = await reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(VAPID_PUB)
                });
            }
            const csrf = document.querySelector('meta[name="csrf-token"]');
            await fetch('/api/push/subscribe', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type':'application/json',
                    'X-CSRF-TOKEN': csrf ? csrf.content : '',
                    'Accept':'application/json'
                },
                body: JSON.stringify({ ...sub.toJSON(), scope: SCOPE })
            }).catch(()=>{});
        } catch(e) { /* user denied or unsupported */ }
    }

    async function maybeAskPermission(){
        if (Notification.permission === 'granted') { subscribePush(); return; }
        if (Notification.permission === 'denied') return;
        let asked = false;
        try { asked = localStorage.getItem(PROMPT_KEY) === '1'; } catch(_){}
        if (asked) return;

        // Wait for first user interaction, then show a soft custom prompt
        const askOnce = async () => {
            document.removeEventListener('click', askOnce);
            try { localStorage.setItem(PROMPT_KEY, '1'); } catch(_){}
            const ok = await new Promise(resolve => {
                const bar = document.createElement('div');
                bar.style.cssText='position:fixed;bottom:20px;right:20px;z-index:99999;background:#1f2937;color:#fff;padding:14px 18px;border-radius:12px;box-shadow:0 20px 50px rgba(0,0,0,.4);max-width:340px;font-family:-apple-system,sans-serif;font-size:13px;line-height:1.5;border:1px solid #374151';
                bar.innerHTML = '<div style="font-weight:700;margin-bottom:4px">Enable Notifications?</div><div style="opacity:.85;margin-bottom:10px">Get instant alerts for FBR submissions, low stock, and important events.</div><div style="display:flex;gap:8px;justify-content:flex-end"><button id="tnPushNo" style="background:#374151;color:#fff;border:0;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:12px">Not now</button><button id="tnPushYes" style="background:#10b981;color:#fff;border:0;padding:6px 14px;border-radius:6px;cursor:pointer;font-weight:600;font-size:12px">Enable</button></div>';
                document.body.appendChild(bar);
                bar.querySelector('#tnPushYes').onclick=()=>{bar.remove();resolve(true)};
                bar.querySelector('#tnPushNo').onclick =()=>{bar.remove();resolve(false)};
                setTimeout(()=>{ if(document.body.contains(bar)){bar.remove();resolve(false)} },15000);
            });
            if (!ok) return;
            const perm = await Notification.requestPermission();
            if (perm === 'granted') subscribePush();
        };
        document.addEventListener('click', askOnce, { once: true });
    }

    setTimeout(maybeAskPermission, 4000);
})();
</script>
