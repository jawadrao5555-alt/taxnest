/* Review, payment, fiscal reporting, checkout and receipt stay in one dialog. */
(() => {
    const dialog = document.getElementById('hotel-bill-preview');
    if (!dialog) return;
    const labels = window.hotelBillPreviewLabels;
    const el = name => dialog.querySelector('[data-preview-' + name + ']');
    const field = name => dialog.querySelector('[data-desk-' + name + ']');
    const money = value => 'Rs ' + Number(value || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    let context = null, busy = false, timer = null, polls = 0;
    const stop = () => { clearTimeout(timer); timer = null; };
    const lockDesk = lock => el('desk-controls').querySelectorAll('input,select,button').forEach(node => { node.disabled = lock; });
    dialog.addEventListener('close', stop);
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    el('back').addEventListener('click', () => { if (!busy) dialog.close(); });
    function creditLink(url) {
        const link = el('credit-note'); link.hidden = !url;
        if (url) link.href = url; else link.removeAttribute('href');
    }
    function renderResult(result) {
        context.result = result;
        creditLink(result.credit_note_url);
        el('desk-controls').hidden = true;
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
        el('checkout').hidden = !context.desk || result.stay_status !== 'checked_in';
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
    function renderQuote(quote, payload, form, desk) {
        context = {payload: {...payload, preview_token: quote.preview_token}, url: form.dataset.confirmUrl, desk, form};
        stop(); polls = 0;
        creditLink(quote.credit_note_url);
        el('meta').textContent = [quote.stay_number, quote.guest_name, quote.room_number].filter(Boolean).join(' · ');
        el('lines').replaceChildren();
        for (const line of quote.lines) {
            const row = document.createElement('p'); row.className = 'py-2 flex justify-between gap-3';
            const text = document.createElement('span'), amount = document.createElement('strong');
            text.textContent = line.description; amount.textContent = money(line.amount); row.append(text, amount); el('lines').append(row);
        }
        el('totals').replaceChildren();
        for (const key of ['gross', 'discount', 'tax', 'total', 'stay_total', 'paid', 'collect_now', 'remaining']) {
            const label = document.createElement('dt'), value = document.createElement('dd');
            label.textContent = labels[key]; value.textContent = money(quote[key]); value.className = 'text-right font-semibold'; el('totals').append(label, value);
        }
        el('note').textContent = quote.reporting && quote.will_issue ? labels.draft_pra : labels.draft;
        const confirmLabel = payload.flow === 'checkout' ? (quote.will_issue && quote.reporting ? labels.confirm_checkout_pra : labels.confirm_checkout_action) : (!quote.will_issue ? labels.confirm_payment : (quote.reporting ? labels.confirm_pra : labels.confirm_local));
        el('confirm').textContent = confirmLabel;
        el('confirm').hidden = false;
        el('confirm').disabled = payload.flow === 'collect' && !quote.will_issue && Number(quote.collect_now) <= 0;
        el('back').textContent = labels.back; el('error').textContent = ''; el('result').hidden = true; el('stay').hidden = true; el('checkout').hidden = true;
        el('desk-controls').hidden = !desk;
        if (desk) {
            field('balance-label').hidden = payload.flow !== 'checkout' || !quote.allow_balance;
            field('amount').max = Number(quote.collect_now) + Number(quote.remaining);
            lockDesk(false);
        }
        if (!dialog.open) dialog.showModal();
        el('back').focus();
    }
    async function preview(form, payload, desk = false) {
        if (busy) return;
        busy = true; el('confirm').disabled = true;
        try {
            payload.idempotency_key = crypto.randomUUID();
            const query = new URLSearchParams(payload); query.delete('_token'); query.delete('idempotency_key');
            const response = await fetch(form.dataset.previewUrl + '?' + query, {credentials: 'same-origin', headers: {Accept: 'application/json'}});
            const quote = await response.json();
            if (!response.ok) throw new Error(quote.message || labels.failed);
            renderQuote(quote, payload, form, desk);
        } catch (error) {
            if (!dialog.open) dialog.showModal();
            el('desk-controls').hidden = !desk;
            el('error').textContent = error.message || labels.failed;
        } finally { busy = false; }
    }
    async function deskPreview(resetAmount = false) {
        if (busy) return;
        const form = document.querySelector('[data-hotel-bill-desk]');
        if (!form) return;
        const payload = Object.fromEntries(new FormData(form));
        payload.flow = field('flow').value; payload.payment_method = field('method').value;
        payload.leave_balance = field('balance').checked ? 1 : 0;
        el('confirm').disabled = true;
        if (resetAmount) {
            busy = true;
            try {
                const response = await fetch(form.dataset.quoteUrl + '?payment_method=' + encodeURIComponent(payload.payment_method), {credentials: 'same-origin', headers: {Accept: 'application/json'}});
                const quote = await response.json();
                if (!response.ok) throw new Error(labels.failed);
                field('amount').value = Number(quote.balance || 0).toFixed(2);
                if (Number(quote.balance) <= 0 && Number(quote.total) <= 0) { field('flow').value = 'checkout'; payload.flow = 'checkout'; }
            } catch (error) { el('error').textContent = error.message || labels.failed; return; }
            finally { busy = false; }
        }
        payload.amount = field('amount').value;
        await preview(form, payload, true);
    }
    document.addEventListener('submit', async event => {
        const form = event.target.closest('form[data-hotel-confirm-flow]');
        if (!form) return;
        event.preventDefault(); event.stopImmediatePropagation();
        const payload = Object.fromEntries(new FormData(form)); payload.flow = form.dataset.hotelConfirmFlow;
        await preview(form, payload);
    }, true);
    field('update').addEventListener('click', () => deskPreview());
    field('method').addEventListener('change', () => deskPreview(true));
    field('flow').addEventListener('change', () => deskPreview());
    field('amount').addEventListener('input', () => { el('confirm').disabled = true; });
    field('balance').addEventListener('change', () => deskPreview());
    document.querySelectorAll('[data-hotel-open-desk]').forEach(button => button.addEventListener('click', () => { field('flow').value = 'collect'; deskPreview(true); }));
    el('checkout').addEventListener('click', () => { stop(); field('flow').value = 'checkout'; deskPreview(true); });
    el('confirm').addEventListener('click', async () => {
        if (busy || !context || context.result || el('confirm').disabled) return;
        busy = true; let refused = false; el('confirm').disabled = true; el('back').disabled = true; el('error').textContent = ''; lockDesk(true);
        // Freeze this payload/UUID on an uncertain response; retry the same attempt.
        try {
            const response = await fetch(context.url, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': context.payload._token}, body: JSON.stringify(context.payload)});
            const result = await response.json();
            if (!response.ok || !result.success) {
                refused = response.status >= 400 && response.status < 500;
                throw new Error(result.message || result.error || labels.failed);
            }
            context.statusUrl = result.status_url; renderResult(result);
        } catch (error) { el('error').textContent = (error.message || labels.failed) + (refused ? '' : ' ' + labels.retry_same); if (refused) lockDesk(false); }
        finally { busy = false; el('confirm').disabled = refused; el('back').disabled = false; }
    });
    const autoDesk = document.querySelector('[data-hotel-bill-desk][data-auto-open="1"]');
    if (autoDesk) { field('flow').value = autoDesk.dataset.initialFlow || 'collect'; deskPreview(true); }
})();
