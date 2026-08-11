{{--
PWA Install button — premium "exe-look" install pill.
Requires <x-pwa-init /> to be included once on the page first (it owns beforeinstallprompt).
Usage: <x-pwa-install color="emerald" label="Install Tax DI" />
Colors: emerald | purple | blue
--}}
@props(['color' => 'emerald', 'label' => 'Install App'])
@php
    $palette = [
        'emerald' => ['from' => '#10b981', 'to' => '#047857', 'glow' => 'rgba(16,185,129,0.55)', 'accent' => '#059669'],
        'purple'  => ['from' => '#8b5cf6', 'to' => '#4f46e5', 'glow' => 'rgba(139,92,246,0.55)', 'accent' => '#7c3aed'],
        'blue'    => ['from' => '#2563eb', 'to' => '#1e40af', 'glow' => 'rgba(37,99,235,0.55)', 'accent' => '#1e3a5f'],
    ];
    $c = $palette[$color] ?? $palette['emerald'];
@endphp
<button id="tnPwaInstallBtn"
    type="button"
    style="display:none; align-items:center; gap:7px; padding:7px 13px; border-radius:10px; background:linear-gradient(135deg, {{ $c['from'] }}, {{ $c['to'] }}); color:#fff; border:none; font-size:11.5px; font-weight:800; letter-spacing:0.2px; cursor:pointer; box-shadow: 0 4px 14px {{ $c['glow'] }}, inset 0 0 0 1px rgba(255,255,255,0.15); transition: all .15s ease;"
    title="{{ __('pos.pwa_pill_title') }}">
    <span style="display:inline-flex; align-items:center; justify-content:center; width:18px; height:18px; border-radius:6px; background:rgba(255,255,255,0.22); flex-shrink:0;">
        <svg style="width:11px; height:11px;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4m-9 8h10"/>
        </svg>
    </span>
    <span>{{ $label }}</span>
</button>
<style>
#tnPwaInstallBtn:hover { transform: translateY(-1px); box-shadow: 0 6px 18px {{ $c['glow'] }}, inset 0 0 0 1px rgba(255,255,255,0.25); }
#tnPwaInstallBtn:active { transform: translateY(0); }
#tnPwaInstallBtn.tn-pulse { animation: tnInstallPulse 1.6s ease-in-out 3; }
@keyframes tnInstallPulse { 0%, 100% { box-shadow: 0 4px 14px {{ $c['glow'] }}, inset 0 0 0 1px rgba(255,255,255,0.15); } 50% { box-shadow: 0 8px 28px {{ $c['glow'] }}, 0 0 0 4px rgba(255,255,255,0.18), inset 0 0 0 1px rgba(255,255,255,0.3); } }
</style>

{{-- iOS Install Instructions Modal --}}
<div id="tnIosInstallModal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.7); backdrop-filter:blur(8px); align-items:center; justify-content:center; padding:16px;">
    <div style="background:#fff; border-radius:20px; max-width:380px; width:100%; padding:26px; box-shadow:0 30px 70px rgba(0,0,0,0.45); position:relative; animation:tnIosFadeIn .3s ease;">
        <button onclick="document.getElementById('tnIosInstallModal').style.display='none'" style="position:absolute; top:12px; right:12px; width:32px; height:32px; border-radius:50%; background:#f3f4f6; border:none; font-size:18px; cursor:pointer; color:#6b7280;">&times;</button>
        <div style="text-align:center; margin-bottom:18px;">
            <div style="width:68px; height:68px; margin:0 auto 14px; border-radius:16px; background:linear-gradient(135deg, {{ $c['from'] }}, {{ $c['to'] }}); display:flex; align-items:center; justify-content:center; box-shadow:0 12px 28px {{ $c['glow'] }};">
                <svg width="34" height="34" fill="none" stroke="white" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4m-9 8h10"/></svg>
            </div>
            <h3 style="font-size:18px; font-weight:800; color:#111827; margin:0;">{{ __('pos.pwa_ios_modal_title') }}</h3>
            <p style="font-size:13px; color:#6b7280; margin:6px 0 0;">{!! __('pos.pwa_ios_modal_sub') !!}</p>
        </div>
        <ol style="font-size:14px; color:#374151; padding-left:0; list-style:none; margin:0;">
            <li style="display:flex; gap:12px; margin-bottom:14px; align-items:flex-start;">
                <span style="flex-shrink:0; width:28px; height:28px; border-radius:50%; background:{{ $c['accent'] }}; color:white; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:700;">1</span>
                <span>{!! __('pos.pwa_ios_share_prefix') !!}
                    <svg style="display:inline-block; vertical-align:-3px; margin:0 2px;" width="16" height="16" fill="none" stroke="{{ $c['accent'] }}" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M16 6l-4-4m0 0L8 6m4-4v14"/></svg>
                    {!! __('pos.pwa_ios_share_suffix') !!}
                </span>
            </li>
            <li style="display:flex; gap:12px; margin-bottom:14px; align-items:flex-start;">
                <span style="flex-shrink:0; width:28px; height:28px; border-radius:50%; background:{{ $c['accent'] }}; color:white; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:700;">2</span>
                <span>{!! __('pos.pwa_ios_step_add_home') !!}</span>
            </li>
            <li style="display:flex; gap:12px; align-items:flex-start;">
                <span style="flex-shrink:0; width:28px; height:28px; border-radius:50%; background:{{ $c['accent'] }}; color:white; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:700;">3</span>
                <span>{!! __('pos.pwa_ios_step_add') !!}</span>
            </li>
        </ol>
        <button onclick="try{localStorage.setItem('tnIosInstallDismissed','1')}catch(e){}; document.getElementById('tnIosInstallModal').style.display='none'" style="margin-top:20px; width:100%; padding:11px; border-radius:11px; border:none; background:#f3f4f6; color:#6b7280; font-size:13px; font-weight:600; cursor:pointer;">{{ __('pos.pwa_dont_show_again') }}</button>
    </div>
</div>
<style>@keyframes tnIosFadeIn{from{opacity:0;transform:scale(.92)}to{opacity:1;transform:scale(1)}}</style>

<script>
(function(){
    const btn = document.getElementById('tnPwaInstallBtn');
    if (!btn) return;
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
    if (isStandalone) return;

    const ua = navigator.userAgent || '';
    const isIos = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
    const isIosSafari = isIos && /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS/.test(ua);

    const showBtn = () => {
        btn.style.display = 'inline-flex';
        btn.classList.add('tn-pulse');
        setTimeout(() => btn.classList.remove('tn-pulse'), 5000);
    };

    if (isIosSafari) {
        let dismissed = false;
        try { dismissed = localStorage.getItem('tnIosInstallDismissed') === '1'; } catch(_){}
        if (dismissed) return;
        showBtn();
        btn.addEventListener('click', () => {
            const m = document.getElementById('tnIosInstallModal');
            if (m) m.style.display = 'flex';
        });
        return;
    }

    // Listen to centralized install-ready event (fired by the central PWA initializer)
    if (window.tnPwaCanInstall) showBtn();
    document.addEventListener('tn-pwa-can-install', showBtn);
    document.addEventListener('tn-pwa-installed', () => { btn.style.display = 'none'; });

    btn.addEventListener('click', async () => {
        if (typeof window.tnPwaPromptInstall !== 'function') return;
        const outcome = await window.tnPwaPromptInstall();
        if (outcome === 'accepted') btn.style.display = 'none';
    });
})();
</script>
