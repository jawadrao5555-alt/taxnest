{{--
PWA Install Banner — first-time dismissable banner shown on dashboards.
Encourages user to install the app for an "exe-like" experience.
Usage: <x-pwa-banner color="emerald" appName="Tax DI" />
--}}
@props(['color' => 'emerald', 'appName' => 'this app'])
@php
    $bg = [
        'emerald' => 'from-emerald-500 via-emerald-600 to-teal-600',
        'purple'  => 'from-purple-500 via-indigo-600 to-purple-700',
        'blue'    => 'from-blue-600 via-indigo-700 to-blue-800',
    ][$color] ?? 'from-emerald-500 via-emerald-600 to-teal-600';
    $key = 'tnPwaBannerDismiss_' . str_replace(' ', '_', strtolower($appName));
@endphp
<div id="tnPwaBanner" style="display:none"
    class="relative bg-gradient-to-r {{ $bg }} text-white rounded-xl shadow-xl p-4 mb-4 flex items-center gap-4 overflow-hidden">
    <div class="absolute inset-0 bg-white/5 backdrop-blur-sm"></div>
    <div class="relative flex-shrink-0 w-12 h-12 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center">
        <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4m-9 8h10"/>
        </svg>
    </div>
    <div class="relative flex-1 min-w-0">
        <h4 class="font-bold text-sm">Install {{ $appName }} as a Desktop App</h4>
        <p class="text-xs text-white/85 mt-0.5">Faster, exe-like experience — no browser bars, works offline, opens from Start Menu.</p>
    </div>
    <div class="relative flex items-center gap-2">
        <button id="tnPwaBannerInstall" class="bg-white text-gray-900 hover:bg-white/90 px-4 py-2 rounded-lg text-xs font-bold shadow transition hover:scale-105">Install Now</button>
        <button id="tnPwaBannerDismiss" class="text-white/70 hover:text-white transition p-1" title="Dismiss">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
</div>
<script>
(function(){
    const banner = document.getElementById('tnPwaBanner');
    const installBtn = document.getElementById('tnPwaBannerInstall');
    const dismissBtn = document.getElementById('tnPwaBannerDismiss');
    if (!banner) return;
    let deferred = null;
    const dismissKey = '{{ $key }}';
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
    if (isStandalone) return;
    let dismissed = false;
    try { dismissed = localStorage.getItem(dismissKey) === '1'; } catch(_) {}
    if (dismissed) return;

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferred = e;
        banner.style.display = 'flex';
    });
    installBtn.addEventListener('click', async () => {
        if (!deferred) return;
        deferred.prompt();
        const { outcome } = await deferred.userChoice;
        deferred = null;
        if (outcome === 'accepted') {
            try { localStorage.setItem(dismissKey, '1'); } catch(_) {}
            banner.style.display = 'none';
        }
    });
    dismissBtn.addEventListener('click', () => {
        try { localStorage.setItem(dismissKey, '1'); } catch(_) {}
        banner.style.display = 'none';
    });
    window.addEventListener('appinstalled', () => {
        try { localStorage.setItem(dismissKey, '1'); } catch(_) {}
        banner.style.display = 'none';
    });
})();
</script>
