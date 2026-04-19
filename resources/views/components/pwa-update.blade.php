{{--
PWA auto-update toast — shows globally when a new service worker version is ready.
Place once in each layout's <body> (just before </body>).
Color picks based on the product scope.
Usage: <x-pwa-update color="emerald" />
--}}
@props(['color' => 'emerald'])
@php
    $bg = [
        'emerald' => 'from-emerald-500 to-emerald-600',
        'purple'  => 'from-purple-500 to-indigo-600',
        'blue'    => 'from-blue-600 to-indigo-700',
    ][$color] ?? 'from-emerald-500 to-emerald-600';
@endphp
<div id="tnPwaUpdateBar" style="display:none; position:fixed; top:16px; left:50%; transform:translateX(-50%); z-index:99999;"
    class="rounded-xl shadow-2xl bg-gradient-to-r {{ $bg }} text-white px-5 py-3 flex items-center gap-3 max-w-md mx-auto text-sm font-semibold">
    <svg class="w-5 h-5 animate-spin" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
    </svg>
    <span>New update ready! Refresh to apply.</span>
    <button id="tnPwaUpdateBtn" class="ml-3 bg-white/20 hover:bg-white/30 px-3 py-1 rounded-lg text-xs font-bold transition">Refresh</button>
</div>
<script>
(function(){
    if (!('serviceWorker' in navigator)) return;
    const bar = document.getElementById('tnPwaUpdateBar');
    const btn = document.getElementById('tnPwaUpdateBtn');
    if (!bar || !btn) return;

    navigator.serviceWorker.getRegistration().then(reg => {
        if (!reg) return;
        const showUpdate = (worker) => {
            if (!worker) return;
            const onState = () => {
                if (worker.state === 'installed' && navigator.serviceWorker.controller) bar.style.display = 'flex';
            };
            worker.addEventListener('statechange', onState); onState();
        };
        if (reg.waiting) showUpdate(reg.waiting);
        reg.addEventListener('updatefound', () => showUpdate(reg.installing));

        // Auto-check for update every 30 minutes (great for kiosk POS that stays open)
        setInterval(() => { reg.update().catch(()=>{}); }, 30 * 60 * 1000);
    });

    btn.addEventListener('click', () => {
        navigator.serviceWorker.getRegistration().then(reg => {
            if (reg && reg.waiting) reg.waiting.postMessage({ type: 'SKIP_WAITING' });
            setTimeout(() => location.reload(), 250);
        });
    });

    let refreshing = false;
    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (refreshing) return;
        refreshing = true;
        location.reload();
    });
})();
</script>
