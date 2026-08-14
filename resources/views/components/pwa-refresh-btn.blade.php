{{--
PWA Refresh Button — always-visible header button to manually check/apply updates.
Pulses with a "!" badge when an update is available. Click instantly applies it.
Requires <x-pwa-init /> on the page (for safe SW skip-waiting flow).
Usage: <x-pwa-refresh-btn color="emerald" variant="dark" />
Colors: emerald | purple | blue
Variants: dark (white-on-dark, default) | light (slate-on-light, for white headers)
--}}
@props(['color' => 'emerald', 'variant' => 'dark'])
@php
    $palette = [
        'emerald' => ['ring' => 'rgba(16,185,129,0.45)', 'glow' => '#10b981', 'badge' => '#ef4444'],
        'purple'  => ['ring' => 'rgba(139,92,246,0.45)', 'glow' => '#8b5cf6', 'badge' => '#f59e0b'],
        'blue'    => ['ring' => 'rgba(59,130,246,0.45)', 'glow' => '#3b82f6', 'badge' => '#f59e0b'],
    ];
    $c = $palette[$color] ?? $palette['emerald'];
    if ($variant === 'light') {
        $idleBg = 'rgba(15,23,42,0.04)';
        $idleBorder = 'rgba(15,23,42,0.10)';
        $idleColor = '#374151';
        $hoverBg = 'rgba(15,23,42,0.08)';
    } else {
        $idleBg = 'rgba(255,255,255,0.10)';
        $idleBorder = 'rgba(255,255,255,0.15)';
        $idleColor = '#ffffff';
        $hoverBg = 'rgba(255,255,255,0.18)';
    }
@endphp
<button id="tnPwaRefreshBtn" type="button"
    title="{{ __('pos.pwa_check_updates') }}"
    style="position:relative; display:none; align-items:center; justify-content:center; width:34px; height:34px; border-radius:10px; background:{{ $idleBg }}; border:1px solid {{ $idleBorder }}; color:{{ $idleColor }}; cursor:pointer; transition: all .15s ease; backdrop-filter: blur(8px);">
    <svg id="tnPwaRefreshSvg" style="width:16px; height:16px; transition: transform .4s ease;" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
    </svg>
    <span id="tnPwaRefreshBadge" style="display:none; position:absolute; top:-4px; right:-4px; min-width:16px; height:16px; padding:0 4px; border-radius:8px; background:{{ $c['badge'] }}; color:#fff; font-size:9px; font-weight:800; line-height:16px; text-align:center; box-shadow: 0 0 0 2px rgba(255,255,255,0.95); letter-spacing:-0.3px;">!</span>
</button>
<style>
#tnPwaRefreshBtn:hover { background: {{ $hoverBg }} !important; transform: scale(1.05); }
#tnPwaRefreshBtn:active { transform: scale(0.95); }
#tnPwaRefreshBtn.tn-spinning #tnPwaRefreshSvg { animation: tnRefreshSpin 0.8s linear infinite; }
#tnPwaRefreshBtn.tn-has-update { background:linear-gradient(135deg, {{ $c['glow'] }}, {{ $c['glow'] }}cc) !important; color:#fff !important; box-shadow: 0 0 0 4px {{ $c['ring'] }}, 0 4px 14px {{ $c['ring'] }}; animation: tnRefreshPulse 1.6s ease-in-out infinite; }
#tnPwaRefreshBtn.tn-flash-ok { background: rgba(34,197,94,0.65) !important; color:#fff !important; transition: background .2s ease; }
@keyframes tnRefreshSpin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
@keyframes tnRefreshPulse { 0%, 100% { box-shadow: 0 0 0 4px {{ $c['ring'] }}, 0 4px 14px {{ $c['ring'] }}; } 50% { box-shadow: 0 0 0 8px transparent, 0 4px 18px {{ $c['glow'] }}; } }
</style>
<script>
(function(){
    if (!('serviceWorker' in navigator)) return;
    const btn = document.getElementById('tnPwaRefreshBtn');
    const badge = document.getElementById('tnPwaRefreshBadge');
    if (!btn) return;

    let updateAvailable = false;
    let busy = false;

    const TITLE_APPLY = @json(__('pos.pwa_apply_now'));
    const TITLE_CHECK = @json(__('pos.pwa_check_updates'));
    const MSG_DOWNLOADING = @json(__('pos.pwa_downloading'));
    const MSG_STILL_DOWNLOADING = @json(__('pos.pwa_still_downloading'));
    const MSG_ON_LATEST = @json(__('pos.pwa_on_latest'));
    const MSG_OFFLINE = @json(__('pos.pwa_offline_no_check'));
    const LATEST_FLAG = 'tnPwaLatestToast';

    btn.style.display = 'inline-flex';

    // Small self-contained toast (ok = green, err = red, info = amber) — used
    // for the post-reload "you're on the latest version" confirmation, the
    // offline error and the still-downloading notice. Kept independent from
    // the x-pwa-update bar (Task 706).
    const TOAST_BG = {
        ok:   'linear-gradient(135deg,#10b981,#047857)',
        err:  'linear-gradient(135deg,#ef4444,#b91c1c)',
        info: 'linear-gradient(135deg,#f59e0b,#b45309)'
    };
    const showToast = (msg, kind) => {
        const t = document.createElement('div');
        t.textContent = msg;
        t.setAttribute('data-tn-pwa-toast', kind || 'ok');
        t.style.cssText = 'position:fixed;top:16px;left:50%;transform:translateX(-50%) translateY(-8px);z-index:99999;padding:10px 18px;border-radius:12px;font-size:13px;font-weight:700;color:#fff;box-shadow:0 10px 30px rgba(0,0,0,0.28);opacity:0;transition:opacity .25s ease,transform .25s ease;max-width:90vw;text-align:center;background:'
            + (TOAST_BG[kind] || TOAST_BG.ok);
        document.body.appendChild(t);
        requestAnimationFrame(() => { t.style.opacity = '1'; t.style.transform = 'translateX(-50%) translateY(0)'; });
        setTimeout(() => {
            t.style.opacity = '0'; t.style.transform = 'translateX(-50%) translateY(-8px)';
            setTimeout(() => t.remove(), 300);
        }, 3500);
    };

    // The pre-reload click set this flag → confirm "you're on the latest version"
    try {
        if (sessionStorage.getItem(LATEST_FLAG) === '1') {
            sessionStorage.removeItem(LATEST_FLAG);
            showToast(MSG_ON_LATEST, 'ok');
        }
    } catch(_) {}

    const markUpdate = () => {
        updateAvailable = true;
        if (badge) badge.style.display = 'inline-block';
        btn.classList.add('tn-has-update');
        btn.title = TITLE_APPLY;
    };

    const clearUpdate = () => {
        updateAvailable = false;
        if (badge) badge.style.display = 'none';
        btn.classList.remove('tn-has-update');
        btn.title = TITLE_CHECK;
    };

    document.addEventListener('tn-pwa-update-available', markUpdate);
    // Another tab activated the update → our waiting SW is gone, clear stale badge
    document.addEventListener('tn-pwa-update-cleared', clearUpdate);

    navigator.serviceWorker.getRegistration().then(reg => {
        if (!reg) return;
        const watchWorker = (worker) => {
            if (!worker) return;
            const onState = () => {
                if (worker.state === 'installed' && navigator.serviceWorker.controller) markUpdate();
                if (worker.state === 'activated' || worker.state === 'redundant') {
                    if (!reg.waiting) clearUpdate();
                }
            };
            worker.addEventListener('statechange', onState); onState();
        };
        if (reg.waiting) markUpdate();
        reg.addEventListener('updatefound', () => watchWorker(reg.installing));
    });

    const applyWaiting = () => {
        // Apply waiting SW via centralized helper (sets intent flag → posts SKIP_WAITING → reloads)
        if (typeof window.tnPwaApplyWaitingUpdate === 'function') {
            window.tnPwaApplyWaitingUpdate();
        } else {
            location.reload();
        }
    };

    // Task 706: the icon's promise is "bring me the latest version" — so the
    // no-SW-update path must STILL reload once (server-side Blade/features ship
    // without sw.js changing on most deploys). This is a direct user action, so
    // the owner's "no auto-reload" rule does not apply here.
    const reloadFresh = (onLatest) => {
        if (onLatest) { try { sessionStorage.setItem(LATEST_FLAG, '1'); } catch(_) {} }
        location.reload();
    };

    btn.addEventListener('click', async (e) => {
        e.preventDefault();
        if (busy) return;

        // Offline: no reload (would land on the offline splash), no fake green —
        // clear feedback instead (Task 706).
        if (!navigator.onLine) {
            showToast(MSG_OFFLINE, 'err');
            return;
        }

        busy = true;
        btn.classList.add('tn-spinning');

        // Remember whether the badge promised an update BEFORE we touch state —
        // decides the stale-badge fallback below (owner report, 7 Aug 2026).
        const hadBadge = updateAvailable;

        const reg = await navigator.serviceWorker.getRegistration().catch(()=>null);

        if (updateAvailable && reg && reg.waiting) {
            applyWaiting();
            return;
        }

        if (!reg) { reloadFresh(true); return; }

        // Force-check. If the check itself fails (server unreachable even though
        // navigator.onLine says true), do NOT reload — the user would lose the
        // page to the offline splash.
        let checkFailed = false;
        try { await reg.update(); } catch(_) { checkFailed = true; }

        // Fixed-1s race fix (Task 706): instead of peeking reg.waiting after
        // 1000ms (slow shop internet → new SW still 'installing' → false green),
        // watch the new worker until it is fully installed, bounded at 20s.
        const outcome = await new Promise((resolve) => {
            let settled = false;
            const finish = (r) => { if (!settled) { settled = true; resolve(r); } };
            if (reg.waiting) { finish('installed'); return; }
            const hardTimer = setTimeout(() => finish('timeout'), 20000);
            // Grace window: reg.update() resolves before updatefound on some
            // browsers — give the new worker a moment to appear.
            const graceTimer = setTimeout(() => finish('none'), 800);
            const watch = (worker) => {
                if (!worker) return;
                clearTimeout(graceTimer);
                btn.title = MSG_DOWNLOADING;
                const onState = () => {
                    if (worker.state === 'installed' || worker.state === 'activated' || worker.state === 'redundant') {
                        clearTimeout(hardTimer);
                        finish(worker.state);
                    }
                };
                worker.addEventListener('statechange', onState);
                onState();
            };
            reg.addEventListener('updatefound', () => watch(reg.installing));
            watch(reg.installing);
        });

        if (outcome === 'installed') {
            // New SW fully downloaded → apply + reload (helper falls back to a
            // plain reload if there is no waiting worker, e.g. first install).
            applyWaiting();
            return;
        }

        if (outcome === 'timeout') {
            // Still downloading after 20s (very slow shop internet). Do NOT
            // reload — under the old worker the user could land back on the old
            // version, the exact failure this button must eliminate. Explicit
            // non-reloading state instead: the download keeps going in the
            // background, the statechange watcher above fires the "!" badge the
            // moment it's ready, and the user taps once more to apply.
            btn.classList.remove('tn-spinning');
            btn.title = TITLE_CHECK;
            showToast(MSG_STILL_DOWNLOADING, 'info');
            busy = false;
            return;
        }

        if (outcome === 'activated' || outcome === 'redundant') {
            // activated: new SW took over silently → reload to be controlled by it.
            // redundant: install failed → still reload once for fresh server content
            // (panel HTML is network-first, so the reload fetches fresh markup).
            reloadFresh(false);
            return;
        }

        // outcome === 'none' — no new SW found.
        if (checkFailed) {
            // Network said online but the update check couldn't reach the server.
            clearUpdate();
            btn.classList.remove('tn-spinning');
            btn.title = TITLE_CHECK;
            showToast(MSG_OFFLINE, 'err');
            busy = false;
            return;
        }

        if (hadBadge) {
            // Badge said "update available" but no waiting SW exists (stale flag —
            // e.g. another tab already applied it). The user clicked EXPECTING a
            // refresh (owner hit this: pressed 2-3x, "refresh nahi hua") — reload
            // so the fresh version actually shows.
            reloadFresh(false);
            return;
        }

        // No SW change at all → still reload once so the server's fresh
        // Blade/features arrive; the post-reload toast confirms "on latest".
        reloadFresh(true);
    });
})();
</script>
