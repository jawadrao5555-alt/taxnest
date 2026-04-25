{{--
PWA central initializer — must be included ONCE per page (placed in layouts before any other pwa-* component).
Centralizes:
  - beforeinstallprompt capture (single listener) → exposes window.tnPwaDeferred + dispatches 'tn-pwa-can-install'
  - appinstalled handling → dispatches 'tn-pwa-installed'
  - window.tnPwaPromptInstall() — shared install function for any UI that wants to trigger the prompt
  - window.tnPwaApplyWaitingUpdate() — safely applies a waiting SW (sets intent flag → posts SKIP_WAITING → reloads)
  - controllerchange auto-reload, but ONLY when WE requested SKIP_WAITING (no jarring mid-task reloads)
Usage: <x-pwa-init />
--}}
<script>
(function(){
    if (window.__tnPwaInitDone) return;
    window.__tnPwaInitDone = true;

    window.tnPwaDeferred = null;
    window.tnPwaCanInstall = false;
    window.tnPwaSkipWaitingRequested = false;

    // === Single source of truth: install prompt ===
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        window.tnPwaDeferred = e;
        window.tnPwaCanInstall = true;
        document.dispatchEvent(new CustomEvent('tn-pwa-can-install'));
    });

    window.addEventListener('appinstalled', () => {
        window.tnPwaDeferred = null;
        window.tnPwaCanInstall = false;
        document.dispatchEvent(new CustomEvent('tn-pwa-installed'));
    });

    window.tnPwaPromptInstall = async function(){
        if (!window.tnPwaDeferred) return null;
        const d = window.tnPwaDeferred;
        window.tnPwaDeferred = null;
        window.tnPwaCanInstall = false;
        d.prompt();
        const { outcome } = await d.userChoice;
        return outcome;
    };

    // === Service Worker update control ===
    if ('serviceWorker' in navigator) {
        let refreshing = false;
        navigator.serviceWorker.addEventListener('controllerchange', () => {
            // Always notify components — the waiting SW is gone now (either we activated it, or another tab did).
            // Components listen so they can clear badges/state across tabs.
            document.dispatchEvent(new CustomEvent('tn-pwa-update-cleared'));
            if (refreshing) return;
            // Auto-reload ONLY when we deliberately asked the new SW to skip waiting.
            // Prevents jarring reloads on first-install (no prior controller) or background updates from other tabs.
            if (!window.tnPwaSkipWaitingRequested) return;
            refreshing = true;
            location.reload();
        });

        window.tnPwaApplyWaitingUpdate = async function(){
            const reg = await navigator.serviceWorker.getRegistration().catch(() => null);
            if (reg && reg.waiting) {
                window.tnPwaSkipWaitingRequested = true;
                reg.waiting.postMessage({ type: 'SKIP_WAITING' });
                // Safety net: if controllerchange doesn't fire within 1.5s, force reload anyway
                setTimeout(() => { if (!document.hidden) location.reload(); }, 1500);
            } else {
                location.reload();
            }
        };
    } else {
        window.tnPwaApplyWaitingUpdate = function(){ location.reload(); };
    }
})();
</script>
