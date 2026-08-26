{{--
PWA update toast — premium notification when a new SW version is ready.
Requires <x-pwa-init /> to be included once on the page first (it owns controllerchange auto-reload).
Auto-checks: every minute + on tab focus + on online event + on visibilitychange.
NEVER auto-applies (owner rule Aug 2026: "bar bar load hone ka nuksan — sale bana
rahe hote hain, POS load ho jata"): the old 30s countdown yanked cashiers mid-work
whenever a deploy landed. Updates apply ONLY on user action — this toast's Refresh
button or the header <x-pwa-refresh-btn />. Dismiss hides the toast for 5 minutes.
Usage: <x-pwa-update color="emerald" />
--}}
@props(['color' => 'emerald'])
@php
    $palette = [
        'emerald' => ['from' => '#10b981', 'to' => '#047857', 'glow' => 'rgba(16,185,129,0.55)'],
        'purple'  => ['from' => '#8b5cf6', 'to' => '#4f46e5', 'glow' => 'rgba(139,92,246,0.55)'],
        'blue'    => ['from' => '#2563eb', 'to' => '#1e40af', 'glow' => 'rgba(37,99,235,0.55)'],
    ];
    $c = $palette[$color] ?? $palette['emerald'];
@endphp
<div id="tnPwaUpdateBar" style="display:none; position:fixed; top:14px; left:50%; transform:translateX(-50%) translateY(-8px); z-index:99999; opacity:0; transition: opacity .25s ease, transform .25s ease;">
    <div style="position:relative; padding:2px; border-radius:18px; background:linear-gradient(135deg, {{ $c['from'] }}, {{ $c['to'] }}); box-shadow: 0 18px 48px {{ $c['glow'] }}, 0 0 0 1px rgba(255,255,255,0.15) inset;">
        <div style="background:linear-gradient(135deg, {{ $c['from'] }}, {{ $c['to'] }}); border-radius:16px; padding:11px 14px 11px 12px; display:flex; align-items:center; gap:12px; min-width:320px; backdrop-filter: blur(10px);">
            <div style="flex-shrink:0; width:36px; height:36px; border-radius:11px; background:rgba(255,255,255,0.18); display:flex; align-items:center; justify-content:center; box-shadow: inset 0 0 0 1px rgba(255,255,255,0.18);">
                <svg style="width:20px; height:20px; color:#fff; animation: tnPwaSpin 1.5s linear infinite;" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
            </div>
            <div style="display:flex; flex-direction:column; gap:1px; line-height:1.25; color:#fff;">
                <div style="font-size:13px; font-weight:800; letter-spacing:0.2px;">{{ __('pos.pwa_update_available') }}</div>
                <div style="font-size:11px; opacity:0.92; font-weight:500;">{{ __('pos.pwa_apply_hint') }}</div>
            </div>
            <button id="tnPwaUpdateBtn" style="flex-shrink:0; margin-left:6px; padding:7px 14px; border-radius:10px; background:#fff; color:{{ $c['to'] }}; border:none; font-size:12px; font-weight:800; cursor:pointer; transition: all .15s ease; box-shadow: 0 4px 12px rgba(0,0,0,0.18);">
                {{ __('pos.pwa_refresh_btn') }}
            </button>
            <button id="tnPwaUpdateDismiss" style="flex-shrink:0; padding:6px; border-radius:8px; background:rgba(255,255,255,0.12); color:#fff; border:none; cursor:pointer; line-height:0; transition: background .15s ease;" title="{{ __('pos.pwa_postpone') }}">
                <svg style="width:14px; height:14px;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
    </div>
</div>
<style>
@keyframes tnPwaSpin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
#tnPwaUpdateBtn:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(0,0,0,0.25); }
#tnPwaUpdateBtn:active { transform: translateY(0); }
#tnPwaUpdateDismiss:hover { background: rgba(255,255,255,0.22) !important; }
</style>
<script>
(function(){
    if (!('serviceWorker' in navigator)) return;
    const bar = document.getElementById('tnPwaUpdateBar');
    const btn = document.getElementById('tnPwaUpdateBtn');
    const dismissBtn = document.getElementById('tnPwaUpdateDismiss');
    if (!bar || !btn) return;

    let postponedUntil = 0;

    const showBar = () => {
        // The user already pressed the header Update icon and that press is still
        // in flight — it applies this very worker by itself. Popping our own
        // "Refresh" bar here is what made one update feel like several (owner,
        // 26 Aug 2026). Stay silent; the icon finishes the job.
        if (typeof window.tnPwaUpdateArmed === 'function' && window.tnPwaUpdateArmed()) {
            document.dispatchEvent(new CustomEvent('tn-pwa-update-available'));
            return;
        }
        if (Date.now() < postponedUntil) return;
        bar.style.display = 'block';
        requestAnimationFrame(() => {
            bar.style.opacity = '1';
            bar.style.transform = 'translateX(-50%) translateY(0)';
        });
        document.dispatchEvent(new CustomEvent('tn-pwa-update-available'));
    };

    const hideBar = () => {
        bar.style.opacity = '0';
        bar.style.transform = 'translateX(-50%) translateY(-8px)';
        setTimeout(() => { bar.style.display = 'none'; }, 250);
    };

    // NO auto-apply (owner rule Aug 2026): the update waits silently until the
    // user clicks Refresh here or the header refresh icon — a deploy must never
    // reload a screen on its own while a cashier is working.
    const applyUpdate = () => {
        if (typeof window.tnPwaApplyWaitingUpdate === 'function') {
            window.tnPwaApplyWaitingUpdate();
        } else {
            location.reload();
        }
    };

    navigator.serviceWorker.getRegistration().then(reg => {
        if (!reg) return;
        const watchWorker = (worker) => {
            if (!worker) return;
            const onState = () => {
                // Only show toast for genuine UPDATES (not first install)
                if (worker.state === 'installed' && navigator.serviceWorker.controller) showBar();
            };
            worker.addEventListener('statechange', onState); onState();
        };
        if (reg.waiting) showBar();
        reg.addEventListener('updatefound', () => watchWorker(reg.installing));

        // Auto-check every minute (owner rule Jul 2026): updates reach devices fast
        setInterval(() => { reg.update().catch(()=>{}); }, 60 * 1000);

        // Check on tab focus / visibility / online — instant detection
        const checkNow = () => { reg.update().catch(()=>{}); };
        window.addEventListener('focus', checkNow);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) checkNow(); });
        window.addEventListener('online', checkNow);

        // Initial check shortly after page load
        setTimeout(checkNow, 2000);
    });

    btn.addEventListener('click', () => { applyUpdate(); });
    dismissBtn.addEventListener('click', () => {
        // Dismiss = gone for this page session (owner rule Aug 2026: no nagging,
        // no re-show). The header refresh icon keeps its "!" badge as the only
        // reminder; user updates whenever they choose.
        postponedUntil = Infinity;
        hideBar();
    });

    // If another tab activates the update, hide our toast — it's no longer relevant
    document.addEventListener('tn-pwa-update-cleared', () => { hideBar(); });
})();
</script>
