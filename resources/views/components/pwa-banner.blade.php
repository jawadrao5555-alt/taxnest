{{--
PWA Install Banner — premium "exe-look" first-time dismissable banner shown on dashboards.
Requires <x-pwa-init /> to be included once on the page first (it owns beforeinstallprompt).
Usage: <x-pwa-banner color="emerald" appName="Tax DI" />
--}}
@props(['color' => 'emerald', 'appName' => 'this app'])
@php
    $palette = [
        'emerald' => ['from' => '#10b981', 'via' => '#059669', 'to' => '#0f766e', 'glow' => 'rgba(16,185,129,0.45)', 'btn' => '#047857'],
        'purple'  => ['from' => '#8b5cf6', 'via' => '#7c3aed', 'to' => '#4f46e5', 'glow' => 'rgba(139,92,246,0.45)', 'btn' => '#5b21b6'],
        'blue'    => ['from' => '#3b82f6', 'via' => '#2563eb', 'to' => '#1e40af', 'glow' => 'rgba(59,130,246,0.45)', 'btn' => '#1e3a8a'],
    ];
    $c = $palette[$color] ?? $palette['emerald'];
    $key = 'tnPwaBannerDismiss_' . str_replace(' ', '_', strtolower($appName));
@endphp
<div id="tnPwaBanner" style="display:none; position:relative; margin-bottom:16px; padding:2px; border-radius:18px; background:linear-gradient(135deg, {{ $c['from'] }}, {{ $c['via'] }}, {{ $c['to'] }}); box-shadow: 0 18px 44px {{ $c['glow'] }};">
    <div style="position:relative; padding:14px 16px; border-radius:16px; background:linear-gradient(135deg, {{ $c['from'] }}, {{ $c['via'] }}, {{ $c['to'] }}); overflow:hidden;">

        <div style="position:relative; display:flex; align-items:center; gap:14px;">
            <div id="tnPwaBannerIcon" style="flex-shrink:0; width:54px; height:54px; border-radius:14px; background:rgba(255,255,255,0.20); display:flex; align-items:center; justify-content:center; box-shadow: inset 0 0 0 1px rgba(255,255,255,0.25), 0 6px 16px rgba(0,0,0,0.18); position:relative;">
                <svg style="width:28px; height:28px; color:#fff;" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4m-9 8h10"/>
                </svg>
                <span style="position:absolute; top:-5px; right:-5px; padding:2px 6px; border-radius:8px; background:#fbbf24; color:#78350f; font-size:8px; font-weight:900; letter-spacing:0.5px; box-shadow: 0 2px 6px rgba(0,0,0,0.25);">PRO</span>
            </div>

            <div style="flex:1; min-width:0; color:#fff;">
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <h4 style="margin:0; font-size:14.5px; font-weight:800; letter-spacing:0.1px;">Install {{ $appName }} as Desktop App</h4>
                    <span style="display:inline-flex; align-items:center; gap:3px; padding:2px 7px; border-radius:6px; background:rgba(255,255,255,0.22); font-size:9px; font-weight:800; letter-spacing:0.6px;">
                        <span style="width:5px; height:5px; border-radius:50%; background:#22c55e; box-shadow:0 0 6px #22c55e;"></span>
                        EXE LOOK
                    </span>
                </div>
                <p style="margin:3px 0 0; font-size:11.5px; opacity:0.94; line-height:1.45; font-weight:500;">
                    No browser bars &middot; Works offline &middot; Opens from Start Menu &middot; Real native feel
                </p>
            </div>

            <div style="display:flex; align-items:center; gap:8px; flex-shrink:0;">
                <button id="tnPwaBannerInstall" style="padding:9px 18px; border-radius:11px; background:#fff; color:{{ $c['btn'] }}; border:none; font-size:12px; font-weight:800; letter-spacing:0.2px; cursor:pointer; box-shadow: 0 6px 16px rgba(0,0,0,0.22); transition: all .15s ease; display:inline-flex; align-items:center; gap:6px;">
                    <svg style="width:13px; height:13px;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4m-9 8h10"/></svg>
                    Install Now
                </button>
                <button id="tnPwaBannerDismiss" style="padding:8px; border-radius:9px; background:rgba(255,255,255,0.12); color:#fff; border:none; cursor:pointer; line-height:0; transition: background .15s ease;" title="Dismiss">
                    <svg style="width:16px; height:16px;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
    </div>
</div>
<style>
#tnPwaBannerInstall:hover { transform: translateY(-1px); box-shadow: 0 8px 22px rgba(0,0,0,0.3); }
#tnPwaBannerInstall:active { transform: translateY(0); }
#tnPwaBannerDismiss:hover { background: rgba(255,255,255,0.22) !important; }
#tnPwaBannerIcon { animation: tnBannerFloat 3.5s ease-in-out infinite; }
@keyframes tnBannerFloat { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-3px); } }
</style>
<script>
(function(){
    const banner = document.getElementById('tnPwaBanner');
    const installBtn = document.getElementById('tnPwaBannerInstall');
    const dismissBtn = document.getElementById('tnPwaBannerDismiss');
    if (!banner) return;
    const dismissKey = '{{ $key }}';
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
    if (isStandalone) return;
    let dismissed = false;
    try { dismissed = localStorage.getItem(dismissKey) === '1'; } catch(_) {}
    if (dismissed) return;

    const showBanner = () => { banner.style.display = 'block'; };

    // Listen to centralized install-ready event
    if (window.tnPwaCanInstall) showBanner();
    document.addEventListener('tn-pwa-can-install', showBanner);
    document.addEventListener('tn-pwa-installed', () => {
        try { localStorage.setItem(dismissKey, '1'); } catch(_) {}
        banner.style.display = 'none';
    });

    installBtn.addEventListener('click', async () => {
        if (typeof window.tnPwaPromptInstall !== 'function') return;
        const outcome = await window.tnPwaPromptInstall();
        if (outcome === 'accepted') {
            try { localStorage.setItem(dismissKey, '1'); } catch(_) {}
            banner.style.display = 'none';
        }
    });
    dismissBtn.addEventListener('click', () => {
        try { localStorage.setItem(dismissKey, '1'); } catch(_) {}
        banner.style.display = 'none';
    });
})();
</script>
