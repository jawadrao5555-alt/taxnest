{{--
PWA Install Button - shows browser native install prompt for the current app.
Usage: <x-pwa-install color="emerald" /> (or purple/blue)
Auto-hides if already installed or unsupported.
--}}
@props(['color' => 'emerald', 'label' => 'Install App'])
@php
    $colorMap = [
        'emerald' => 'from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 ring-emerald-300',
        'purple'  => 'from-purple-500 to-indigo-600 hover:from-purple-600 hover:to-indigo-700 ring-purple-300',
        'blue'    => 'from-blue-600 to-indigo-700 hover:from-blue-700 hover:to-indigo-800 ring-blue-300',
    ];
    $cls = $colorMap[$color] ?? $colorMap['emerald'];
@endphp
<button id="tnPwaInstallBtn"
    type="button"
    style="display:none"
    class="inline-flex items-center gap-2 px-3 py-1.5 text-xs font-semibold text-white rounded-lg shadow bg-gradient-to-r {{ $cls }} focus:outline-none focus:ring-2 focus:ring-offset-1 transition-all"
    title="Install this app to your device">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4m-9 8h10"/>
    </svg>
    <span>{{ $label }}</span>
</button>
<script>
(function(){
    let deferred = null;
    const btn = document.getElementById('tnPwaInstallBtn');
    if (!btn) return;
    // Hide if already running standalone (installed)
    const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
    if (standalone) return;
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferred = e;
        btn.style.display = 'inline-flex';
    });
    btn.addEventListener('click', async () => {
        if (!deferred) return;
        deferred.prompt();
        const { outcome } = await deferred.userChoice;
        deferred = null;
        if (outcome === 'accepted') btn.style.display = 'none';
    });
    window.addEventListener('appinstalled', () => { btn.style.display = 'none'; });
})();
</script>
