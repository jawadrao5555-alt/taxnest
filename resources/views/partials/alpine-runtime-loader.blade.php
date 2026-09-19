{{-- Deterministic Alpine boot for the POS shells.
     The Vite entry is authoritative. If it never arrives, the post-DCL ESM
     fallback uses the same coordinator; ESM modules do not auto-start Alpine. --}}
<script data-tn-alpine-runtime>
    (function (window, document) {
        var boot = window.__tnAlpineBoot = window.__tnAlpineBoot || {
            status: 'idle',
            source: null,
            starts: 0,
            fallbackTimer: null
        };

        window.__tnStartAlpine = window.__tnStartAlpine || function (Alpine, plugins, source) {
            if (!Alpine || boot.status === 'started' || boot.status === 'starting') {
                return window.Alpine || Alpine;
            }

            // The local Vite bundle always wins if it arrives while the network
            // fallback is still downloading.
            boot.status = 'starting';
            boot.source = source || 'unknown';
            if (boot.fallbackTimer) {
                clearTimeout(boot.fallbackTimer);
                boot.fallbackTimer = null;
            }

            (plugins || []).filter(Boolean).forEach(function (plugin) {
                Alpine.plugin(plugin);
            });
            window.Alpine = Alpine;

            try {
                Alpine.start();
                boot.status = 'started';
                boot.starts += 1;
                // Legacy probes still read this flag. It now means "start
                // completed", never "a fallback download was merely claimed".
                window.__alpineStarted = true;
                window.dispatchEvent(new CustomEvent('tn:alpine-started', {
                    detail: { source: boot.source, starts: boot.starts }
                }));
            } catch (error) {
                boot.status = 'failed';
                window.__alpineStarted = false;
                throw error;
            }

            return Alpine;
        };

        function armFallback() {
            if (boot.status === 'started' || boot.status === 'starting' || boot.fallbackTimer) return;
            boot.fallbackTimer = setTimeout(async function () {
                boot.fallbackTimer = null;
                if (boot.status === 'started' || boot.status === 'starting') return;

                boot.status = 'fallback-loading';
                boot.source = 'cdn-esm';
                try {
                    var modules = await Promise.all([
                        import('https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/module.esm.js'),
                        import('https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.14.8/dist/module.esm.js')
                    ]);
                    // A late Vite bundle may have completed while imports were
                    // in flight. In that case the fallback must be a strict no-op.
                    if (boot.status === 'started' || boot.status === 'starting') return;
                    boot.status = 'idle';
                    window.__tnStartAlpine(modules[0].default, [modules[1].default], 'cdn-esm');
                } catch (error) {
                    if (boot.status !== 'started') {
                        boot.status = 'failed';
                        window.dispatchEvent(new CustomEvent('tn:alpine-failed', {
                            detail: { source: 'cdn-esm' }
                        }));
                    }
                }
            }, 1200);
        }

        // Module scripts execute before DOMContentLoaded. Waiting until DCL plus
        // a grace period means every page-local component factory has parsed and
        // prevents the fallback from racing a slow Vite response.
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', armFallback, { once: true });
        } else {
            armFallback();
        }
    })(window, document);
</script>