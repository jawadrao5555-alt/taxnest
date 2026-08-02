{{--
PWA auto-update toast — premium notification when a new SW version is ready.
Requires <x-pwa-init /> to be included once on the page first (it owns controllerchange auto-reload).
Auto-checks: every 5 min + on tab focus + on online event + on visibilitychange.
Auto-applies after 30s (countdown) — user can dismiss to postpone for 5 min.
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
                <div style="font-size:13px; font-weight:800; letter-spacing:0.2px;">New update available</div>
                <div style="font-size:11px; opacity:0.92; font-weight:500;">{{ __('pos.pwa_apply_hint') }} <span id="tnPwaUpdateCountdown" style="font-weight:700;"></span></div>
            </div>
            <button id="tnPwaUpdateBtn" style="flex-shrink:0; margin-left:6px; padding:7px 14px; border-radius:10px; background:#fff; color:{{ $c['to'] }}; border:none; font-size:12px; font-weight:800; cursor:pointer; transition: all .15s ease; box-shadow: 0 4px 12px rgba(0,0,0,0.18);">
                Refresh
            </button>
            <button id="tnPwaUpdateDismiss" style="flex-shrink:0; padding:6px; border-radius:8px; background:rgba(255,255,255,0.12); color:#fff; border:none; cursor:pointer; line-height:0; transition: background .15s ease;" title="Postpone">
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
    const countdownEl = document.getElementById('tnPwaUpdateCountdown');
    if (!bar || !btn) return;

    let countdownTimer = null;
    let countdownSeconds = 30;
    let postponedUntil = 0;

    const showBar = () => {
        if (Date.now() < postponedUntil) return;
        bar.style.display = 'block';
        requestAnimationFrame(() => {
            bar.style.opacity = '1';
            bar.style.transform = 'translateX(-50%) translateY(0)';
        });
        document.dispatchEvent(new CustomEvent('tn-pwa-update-available'));
        startCountdown();
    };

    const hideBar = () => {
        bar.style.opacity = '0';
        bar.style.transform = 'translateX(-50%) translateY(-8px)';
        setTimeout(() => { bar.style.display = 'none'; }, 250);
        stopCountdown();
    };

    const startCountdown = () => {
        stopCountdown();
        countdownSeconds = 30;
        countdownEl.textContent = ' (' + countdownSeconds + 's)';
        countdownTimer = setInterval(() => {
            countdownSeconds--;
            if (countdownSeconds <= 0) {
                // Mid-task hold: pages can register window.tnPwaUpdateHold (e.g. POS sale
                // screen with items in the cart / pay modal open). While it returns true,
                // keep the toast visible but DON'T auto-reload — retry every 30s. The
                // manual Refresh button still works immediately.
                if (typeof window.tnPwaUpdateHold === 'function') {
                    let busy = false;
                    try { busy = !!window.tnPwaUpdateHold(); } catch (e) {}
                    if (busy) { countdownSeconds = 30; countdownEl.textContent = ''; return; }
                }
                stopCountdown();
                applyUpdate();
                return;
            }
            countdownEl.textContent = ' (' + countdownSeconds + 's)';
        }, 1000);
    };

    const stopCountdown = () => {
        if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
    };

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

    btn.addEventListener('click', () => { stopCountdown(); applyUpdate(); });
    dismissBtn.addEventListener('click', () => {
        // Postpone for 5 minutes — user is mid-task, don't reload
        postponedUntil = Date.now() + 5 * 60 * 1000;
        hideBar();
        // Re-show after 5 min if a waiting SW still exists
        setTimeout(async () => {
            if (Date.now() < postponedUntil) return;
            const reg = await navigator.serviceWorker.getRegistration().catch(()=>null);
            if (reg && reg.waiting) showBar();
        }, 5 * 60 * 1000 + 500);
    });

    // If another tab activates the update, hide our toast — it's no longer relevant
    document.addEventListener('tn-pwa-update-cleared', () => { hideBar(); });
})();
</script>
