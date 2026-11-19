@if(!($preview ?? false) && !empty($imsReceipt->fbr_pos_transaction_id)
    && in_array($imsReceipt->fbr_status ?? null, ['pending', 'offline', 'verification_pending'], true))
<script>
(() => {
    const initialStatus = @json($imsReceipt->fbr_status);
    const url = new URL(window.location.href);
    url.searchParams.set('fiscal_status', '1');
    let printing = false;
    let needsRefresh = false;
    let delay = 500;

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
                        if (status === 'submitted') {
                            const page = await fetch(window.location.href, { cache: 'no-store', signal: AbortSignal.timeout(5000) });
                            if (page.ok && !page.redirected) {
                                const updated = new DOMParser().parseFromString(await page.text(), 'text/html')
                                    .querySelector('article.collection-print-receipt');
                                const current = document.querySelector('article.collection-print-receipt');
                                if (current && updated?.querySelector('.receipt-fiscal img') && !printing && !document.hidden) {
                                    current.replaceWith(updated);
                                    return;
                                }
                            }
                        }
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

    checkStatus();
})();
</script>
@endif
