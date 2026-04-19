{{--
PWA Install button (login pages only).
Usage: <x-pwa-install color="emerald" label="Install Tax DI" />
Colors: emerald | purple | blue
- Standard browsers: native install prompt via beforeinstallprompt
- iOS Safari: shows custom "Add to Home Screen" instruction modal
--}}
@props(['color' => 'emerald', 'label' => 'Install App'])
@php
    $colorMap = [
        'emerald' => 'from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 ring-emerald-300 shadow-emerald-500/30',
        'purple'  => 'from-purple-500 to-indigo-600 hover:from-purple-600 hover:to-indigo-700 ring-purple-300 shadow-purple-500/30',
        'blue'    => 'from-blue-600 to-indigo-700 hover:from-blue-700 hover:to-indigo-800 ring-blue-300 shadow-blue-500/30',
    ];
    $cls = $colorMap[$color] ?? $colorMap['emerald'];
    $accent = ['emerald' => '#059669', 'purple' => '#7c3aed', 'blue' => '#1e3a5f'][$color] ?? '#059669';
@endphp
<button id="tnPwaInstallBtn"
    type="button"
    style="display:none"
    class="inline-flex items-center gap-2 px-3 py-1.5 text-xs font-semibold text-white rounded-lg shadow-lg bg-gradient-to-r {{ $cls }} focus:outline-none focus:ring-2 focus:ring-offset-1 transition-all hover:scale-105"
    title="Install this app to your device for an exe-like experience">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4m-9 8h10"/>
    </svg>
    <span>{{ $label }}</span>
</button>

{{-- iOS Install Instructions Modal --}}
<div id="tnIosInstallModal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.65); backdrop-filter:blur(6px); align-items:center; justify-content:center; padding:16px;">
    <div style="background:#fff; border-radius:18px; max-width:380px; width:100%; padding:24px; box-shadow:0 25px 60px rgba(0,0,0,0.4); position:relative; animation:tnIosFadeIn .3s ease;">
        <button onclick="document.getElementById('tnIosInstallModal').style.display='none'" style="position:absolute; top:12px; right:12px; width:32px; height:32px; border-radius:50%; background:#f3f4f6; border:none; font-size:18px; cursor:pointer; color:#6b7280;">&times;</button>
        <div style="text-align:center; margin-bottom:18px;">
            <div style="width:64px; height:64px; margin:0 auto 12px; border-radius:14px; background:linear-gradient(135deg, {{ $accent }}, {{ $accent }}aa); display:flex; align-items:center; justify-content:center; box-shadow:0 8px 20px {{ $accent }}55;">
                <svg width="32" height="32" fill="none" stroke="white" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4m-9 8h10"/></svg>
            </div>
            <h3 style="font-size:18px; font-weight:700; color:#111827; margin:0;">Install on your iPhone/iPad</h3>
            <p style="font-size:13px; color:#6b7280; margin:6px 0 0;">Get the full app experience — works offline, no browser bar.</p>
        </div>
        <ol style="font-size:14px; color:#374151; padding-left:0; list-style:none; margin:0;">
            <li style="display:flex; gap:12px; margin-bottom:14px; align-items:flex-start;">
                <span style="flex-shrink:0; width:28px; height:28px; border-radius:50%; background:{{ $accent }}; color:white; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:700;">1</span>
                <span>Tap the <strong>Share</strong> button
                    <svg style="display:inline-block; vertical-align:-3px; margin:0 2px;" width="16" height="16" fill="none" stroke="{{ $accent }}" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M16 6l-4-4m0 0L8 6m4-4v14"/></svg>
                    at the bottom of Safari.
                </span>
            </li>
            <li style="display:flex; gap:12px; margin-bottom:14px; align-items:flex-start;">
                <span style="flex-shrink:0; width:28px; height:28px; border-radius:50%; background:{{ $accent }}; color:white; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:700;">2</span>
                <span>Scroll down and tap <strong>Add to Home Screen</strong>.</span>
            </li>
            <li style="display:flex; gap:12px; align-items:flex-start;">
                <span style="flex-shrink:0; width:28px; height:28px; border-radius:50%; background:{{ $accent }}; color:white; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:700;">3</span>
                <span>Tap <strong>Add</strong> in the top-right corner.</span>
            </li>
        </ol>
        <button onclick="try{localStorage.setItem('tnIosInstallDismissed','1')}catch(e){}; document.getElementById('tnIosInstallModal').style.display='none'" style="margin-top:20px; width:100%; padding:10px; border-radius:10px; border:none; background:#f3f4f6; color:#6b7280; font-size:13px; cursor:pointer;">Don't show again</button>
    </div>
</div>
<style>@keyframes tnIosFadeIn{from{opacity:0;transform:scale(.92)}to{opacity:1;transform:scale(1)}}</style>

<script>
(function(){
    let deferred = null;
    const btn = document.getElementById('tnPwaInstallBtn');
    if (!btn) return;
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
    if (isStandalone) return;

    // iOS detection (no beforeinstallprompt support)
    const ua = navigator.userAgent || '';
    const isIos = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
    const isIosSafari = isIos && /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS/.test(ua);

    if (isIosSafari) {
        // iOS: honor "Don't show again" preference
        let dismissed = false;
        try { dismissed = localStorage.getItem('tnIosInstallDismissed') === '1'; } catch(_){}
        if (dismissed) return;
        // Show the button — click opens custom modal
        btn.style.display = 'inline-flex';
        btn.addEventListener('click', () => {
            const m = document.getElementById('tnIosInstallModal');
            if (m) m.style.display = 'flex';
        });
        return;
    }

    window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); deferred = e; btn.style.display = 'inline-flex'; });
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
