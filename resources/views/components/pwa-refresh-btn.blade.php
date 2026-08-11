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

    btn.style.display = 'inline-flex';

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

    btn.addEventListener('click', async (e) => {
        e.preventDefault();
        if (busy) return;
        busy = true;
        btn.classList.add('tn-spinning');

        // Remember whether the badge promised an update BEFORE we touch state —
        // decides the stale-badge fallback below (owner report, 7 Aug 2026).
        const hadBadge = updateAvailable;

        const reg = await navigator.serviceWorker.getRegistration().catch(()=>null);

        if (updateAvailable && reg && reg.waiting) {
            // Apply waiting SW via centralized helper (sets intent flag → posts SKIP_WAITING → reloads)
            if (typeof window.tnPwaApplyWaitingUpdate === 'function') {
                window.tnPwaApplyWaitingUpdate();
            } else {
                location.reload();
            }
            return;
        }

        // No update was marked — but maybe another tab cleared it, or user wants to force-check
        if (reg) {
            try { await reg.update(); } catch(_) {}
            setTimeout(() => {
                if (reg.waiting) {
                    if (typeof window.tnPwaApplyWaitingUpdate === 'function') {
                        window.tnPwaApplyWaitingUpdate();
                    } else {
                        location.reload();
                    }
                } else if (hadBadge) {
                    // Badge said "update available" but no waiting SW exists
                    // (stale flag — e.g. another tab already applied it, or the
                    // worker activated silently). The user clicked EXPECTING a
                    // refresh; pressing again would do nothing forever (owner
                    // hit this: pressed 2-3x, "refresh nahi hua"). Give a real
                    // reload so the fresh version actually shows.
                    location.reload();
                } else {
                    // No new version — sync stale state, show success flash
                    clearUpdate();
                    btn.classList.remove('tn-spinning');
                    btn.classList.add('tn-flash-ok');
                    setTimeout(() => { btn.classList.remove('tn-flash-ok'); busy = false; }, 1100);
                }
            }, 1000);
        } else {
            location.reload();
        }
    });
})();
</script>
