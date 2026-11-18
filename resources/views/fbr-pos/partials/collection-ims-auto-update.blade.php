@if(!($preview ?? false) && !empty($imsReceipt->fbr_pos_transaction_id)
    && in_array($imsReceipt->fbr_status ?? null, ['pending', 'offline', 'verification_pending'], true))
<script>
(() => {
    const initialStatus = @json($imsReceipt->fbr_status);
    const url = new URL(window.location.href);
    url.searchParams.set('fiscal_status', '1');
    let printing = false;
    let needsRefresh = false;
    let delay = 5000;

    function refreshIfReady() {
        if (needsRefresh && !printing && !document.hidden) window.location.reload();
    }

    window.addEventListener('beforeprint', () => { printing = true; });
    window.addEventListener('afterprint', () => {
        printing = false;
        refreshIfReady();
    });
    document.addEventListener('visibilitychange', refreshIfReady);

    async function checkStatus() {
        if (!document.hidden && !printing) {
            try {
                const response = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                    signal: AbortSignal.timeout(5000),
                });
                if (response.redirected || [401, 403, 404, 419].includes(response.status)) return;
                if (response.ok) {
                    const { status } = await response.json();
                    if (status && status !== initialStatus) {
                        needsRefresh = true;
                        refreshIfReady();
                        return;
                    }
                }
            } catch (_) {
                // A brief network interruption should not disrupt the receipt.
            }
        }
        window.setTimeout(checkStatus, delay);
        delay = Math.min(delay + 1000, 30000);
    }

    window.setTimeout(checkStatus, delay);
})();
</script>
@endif
