/* Only explicit confirmation creates a bill. Preview/close/refresh never submit PRA. */
(() => {
    const dialog = document.getElementById('hotel-bill-preview');
    if (!dialog) return;
    const labels = window.hotelBillPreviewLabels;
    const el = name => dialog.querySelector('[data-preview-' + name + ']');
    const money = value => 'Rs ' + Number(value || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    let context = null, busy = false, timer = null, polls = 0;
    const stop = () => { clearTimeout(timer); timer = null; };
    dialog.addEventListener('close', stop);
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    el('back').addEventListener('click', () => { if (!busy) dialog.close(); });
    function renderResult(result) {
        context.result = result;
        el('result').hidden = false;
        el('note').textContent = labels.saved;
        el('confirm').hidden = true;
        el('back').textContent = labels.close;
        el('status').textContent = labels['status_' + result.status] || labels.status_pending;
        el('number').textContent = result.invoice_number ? labels.number + ': ' + result.invoice_number + (result.fiscal_number ? ' · PRA: ' + result.fiscal_number : '') : '';
        el('qr').hidden = !result.qr;
        if (result.qr) el('qr').src = result.qr;
        el('receipt').hidden = !result.receipt_url;
        if (result.receipt_url) el('receipt').href = result.receipt_url;
        el('stay').hidden = false; el('stay').href = result.stay_url;
        el('refresh').hidden = !context.statusUrl || result.status === 'submitted' || result.status === 'local';
        if (!el('refresh').hidden && polls < 15 && dialog.open) timer = setTimeout(refresh, 2000);
    }
    async function refresh() {
        stop();
        if (!context?.statusUrl || busy) return;
        busy = true; el('refresh').disabled = true; ++polls;
        try {
            const response = await fetch(context.statusUrl, {credentials: 'same-origin', headers: {Accept: 'application/json'}});
            const result = await response.json();
            if (!response.ok) throw new Error(labels.failed);
            renderResult(result); el('error').textContent = '';
        } catch (_) { el('error').textContent = labels.failed; }
        finally { busy = false; el('refresh').disabled = false; }
    }
    el('refresh').addEventListener('click', refresh);
    document.addEventListener('submit', async event => {
        const form = event.target.closest('form[data-hotel-confirm-flow]');
        if (!form) return;
        event.preventDefault(); event.stopImmediatePropagation();
        if (busy) return;
        const payload = Object.fromEntries(new FormData(form));
        payload.flow = form.dataset.hotelConfirmFlow;
        payload.idempotency_key = crypto.randomUUID();
        const query = new URLSearchParams(payload);
        query.delete('_token'); query.delete('idempotency_key');
        busy = true;
        try {
            const response = await fetch(form.dataset.previewUrl + '?' + query, {credentials: 'same-origin', headers: {Accept: 'application/json'}});
            const quote = await response.json();
            if (!response.ok) throw new Error(quote.message || labels.failed);
            context = {payload: {...payload, preview_token: quote.preview_token}, url: form.dataset.confirmUrl};
            stop(); polls = 0;
            el('meta').textContent = [quote.stay_number, quote.guest_name, quote.room_number].filter(Boolean).join(' · ');
            el('lines').replaceChildren();
            for (const line of quote.lines) {
                const row = document.createElement('p'); row.className = 'py-2 flex justify-between gap-3';
                const text = document.createElement('span'), amount = document.createElement('strong');
                text.textContent = line.description; amount.textContent = money(line.amount); row.append(text, amount); el('lines').append(row);
            }
            el('totals').replaceChildren();
            for (const key of ['gross', 'discount', 'tax', 'total', 'paid', 'collect_now', 'remaining']) {
                const label = document.createElement('dt'), value = document.createElement('dd');
                label.textContent = labels[key]; value.textContent = money(quote[key]); value.className = 'text-right font-semibold'; el('totals').append(label, value);
            }
            el('note').textContent = quote.reporting && quote.will_issue ? labels.draft_pra : labels.draft;
            el('confirm').textContent = !quote.will_issue ? labels.confirm_checkout : (quote.reporting ? labels.confirm_pra : labels.confirm_local);
            el('confirm').hidden = false; el('confirm').disabled = false;
            el('back').textContent = labels.back; el('error').textContent = ''; el('result').hidden = true; el('stay').hidden = true;
            dialog.showModal(); el('back').focus();
        } catch (error) { window.alert(error.message || labels.failed); }
        finally { busy = false; }
    }, true);
    el('confirm').addEventListener('click', async () => {
        if (busy || !context || context.result) return;
        busy = true; let refused = false; el('confirm').disabled = true; el('back').disabled = true; el('error').textContent = '';
        // On an uncertain response retain this UUID/payload; never manufacture a new bill.
        try {
            const response = await fetch(context.url, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': context.payload._token}, body: JSON.stringify(context.payload)});
            const result = await response.json();
            if (!response.ok || !result.success) {
                refused = response.status >= 400 && response.status < 500;
                throw new Error(result.message || result.error || labels.failed);
            }
            context.statusUrl = result.status_url; renderResult(result);
        } catch (error) { el('error').textContent = (error.message || labels.failed) + (refused ? '' : ' ' + labels.retry_same); }
        finally { busy = false; el('confirm').disabled = refused; el('back').disabled = false; }
    });
})();
