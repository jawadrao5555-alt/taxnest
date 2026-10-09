/* Hotel-only UI: saved print settings, scoped receipts and durable print attempts. */
(() => {
    const config = window.hotelDeskPopupConfig;
    const booking = document.querySelector('[data-hotel-checkin-popup]');
    const receipt = document.querySelector('[data-hotel-receipt-popup]');
    if (!config || !booking || !receipt) return;
    let receiptHost = receipt, inlineDialog = null;
    const q = name => receiptHost.querySelector('[data-receipt-' + name + ']');
    const labels = config.labels;
    const receiptIsOpen = () => (inlineDialog || receipt).open;
    const status = text => { q('status').textContent = text; };
    let active = null, opening = false, loading = false, restore = null, poll = null;
    const attempts = new Map();
    const stop = () => { clearTimeout(poll); poll = null; };
    const sameOrigin = value => {
        const url = new URL(value, location.href);
        if (url.origin !== location.origin) throw new Error(config.failed);
        return url;
    };
    const setBusy = value => {
        loading = value;
        q('print').disabled = value || !active?.ready;
        q('picker').disabled = value;
        q('close').disabled = value;
        if (inlineDialog) {
            inlineDialog.querySelector('[data-preview-back]').disabled = value;
            inlineDialog.querySelector('[data-preview-checkout]').disabled = value;
        }
    };
    receipt.addEventListener('cancel', event => { if (loading) event.preventDefault(); });
    receipt.addEventListener('close', () => {
        stop();
        if (restore && !restore.open) restore.showModal();
        restore = null;
    });
    function unmountInline() {
        if (receiptHost !== receipt) {
            stop();
            while (receiptHost.firstChild) receipt.append(receiptHost.firstChild);
            receiptHost.hidden = true; receiptHost = receipt; inlineDialog = null;
        }
    }
    q('close').addEventListener('click', () => { if (!loading) (inlineDialog || receipt).close(); });
    booking.querySelector('[data-checkin-close]').addEventListener('click', () => booking.close());
    booking.addEventListener('cancel', event => { if (booking.dataset.busy === '1') event.preventDefault(); });

    async function checkIn(url) {
        if (opening) return;
        opening = true;
        const error = booking.querySelector('[data-checkin-error]');
        const container = booking.querySelector('[data-checkin-form]');
        error.textContent = '';
        try {
            const target = sameOrigin(url); target.searchParams.set('modal', '1');
            const response = await fetch(target, {credentials: 'same-origin', headers: {Accept: 'text/html'}});
            if (!response.ok || response.redirected) throw new Error(config.failed);
            const html = await response.text();
            Alpine.mutateDom(() => {
                Alpine.destroyTree(container);
                container.innerHTML = html;
                Alpine.initTree(container);
            });
            if (!booking.open) booking.showModal();
        } catch (failure) { error.textContent = failure.message; if (!booking.open) booking.showModal(); }
        finally { opening = false; }
    }
    booking.addEventListener('submit', async event => {
        const form = event.target.closest('form');
        if (!form || event.defaultPrevented) return;
        event.preventDefault();
        if (booking.dataset.busy === '1') return;
        booking.dataset.busy = '1';
        const close = booking.querySelector('[data-checkin-close]');
        close.disabled = true;
        try {
            const response = await fetch(form.action, {method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: {Accept: 'application/json'}});
            const result = await response.json();
            if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || config.failed);
            location.assign(sameOrigin(result.stay_url).href);
        } catch (failure) {
            booking.querySelector('[data-checkin-error]').textContent = failure.message;
            // Alpine's normal submit lock must be released after a refused/lost response.
            const data = Alpine.$data(form); if (data) data.submitting = false;
        } finally { booking.dataset.busy = '0'; close.disabled = false; }
    });

    function selectDocument(index) {
        stop();
        const document = active.documents[index];
        active = {...active, document, attempt: null, job: null, polls: 0, ...attempts.get(document.url), ready: false};
        q('print').disabled = true; q('browser').hidden = true;
        status(labels.loading);
        const url = sameOrigin(active.document.url);
        url.searchParams.delete('auto_print'); url.searchParams.delete('print');
        q('frame').src = url.href;
    }
    q('frame').addEventListener('load', async () => {
        if (!active) return;
        const doc = q('frame').contentDocument;
        // A login/error page is never printable as an invoice.
        if (!doc || (!doc.querySelector('#receiptActions') && !doc.querySelector('[data-hotel-statement]'))) {
            active.ready = false; status(config.failed); q('print').disabled = true; return;
        }
        const documentUrl = active.document.url;
        if (doc.fonts?.ready) await Promise.race([doc.fonts.ready, new Promise(resolve => setTimeout(resolve, 8000))]);
        if (!active || active.document.url !== documentUrl || q('frame').contentDocument !== doc) return;
        active.ready = true; q('print').disabled = loading || !!active.job;
        if (active.job) { q('picker').disabled = true; pollJob(); }
        else status(active.attempt ? labels.retry_print : '');
    });
    async function openReceipt(url, previous = null) {
        if (opening || loading) return;
        opening = true;
        active = null;
        q('print').disabled = true; q('picker').disabled = true; q('browser').hidden = true;
        status(labels.loading);
        try {
            if (previous?.matches('[data-hotel-bill-preview]')) {
                const host = previous.querySelector('[data-preview-issued]');
                if (inlineDialog !== previous) {
                    unmountInline(); receiptHost = host; inlineDialog = previous;
                    while (receipt.firstChild) host.append(receipt.firstChild);
                    if (!previous.dataset.receiptCloseBound) {
                        previous.addEventListener('close', () => { if (!previous.open && inlineDialog === previous) unmountInline(); });
                        previous.addEventListener('cancel', event => event.preventDefault());
                        previous.dataset.receiptCloseBound = '1';
                    }
                }
                host.hidden = false;
            } else unmountInline();
            const target = sameOrigin(url);
            const match = target.pathname.match(/\/pos\/hotel\/stays\/(\d+)\/(?:bills\/(\d+)\/receipt|statement)$/);
            if (!match) throw new Error(config.failed);
            const response = await fetch(config.base + '/' + match[1] + '/receipt-preview', {credentials:'same-origin', headers:{Accept:'application/json'}});
            if (!response.ok) throw new Error(config.failed);
            const model = await response.json();
            if (previous?.matches('[data-hotel-bill-preview]') && (!previous.open || inlineDialog !== previous)) return;
            active = {...model, stay: match[1], ready:false};
            q('picker').replaceChildren();
            for (const [index, entry] of model.documents.entries()) {
                const option = document.createElement('option'); option.value = index; option.textContent = entry.label; q('picker').append(option);
            }
            const requested = match[2] ? model.documents.findIndex(document => String(document.bill_id) === match[2]) : 0;
            if (requested < 0) throw new Error(config.failed);
            q('picker').value = String(requested);
            q('picker-label').hidden = model.documents.length < 2;
            selectDocument(requested);
            if (inlineDialog) { if (!inlineDialog.open) inlineDialog.showModal(); }
            else {
                if (previous?.open) { restore = previous; previous.close(); }
                if (!receipt.open) receipt.showModal();
            }
        } catch (failure) { status(failure.message); if (!inlineDialog && !receipt.open) receipt.showModal(); }
        finally { opening = false; q('print').disabled = loading || !active?.ready || !!active?.job; q('picker').disabled = loading || !!active?.job; }
    }
    q('picker').addEventListener('change', () => {
        if (active?.job && !confirm(labels.possible_duplicate)) { q('picker').value = String(active.documents.indexOf(active.document)); return; }
        selectDocument(Number(q('picker').value));
    });
    function browserPrint() {
        if (!active?.ready) return;
        if ((active.job || active.attempt) && !confirm(labels.possible_duplicate)) return;
        try { q('frame').contentWindow.focus(); q('frame').contentWindow.print(); status(labels.browser_dialog); }
        catch (_) { status(config.failed); }
    }
    q('browser').addEventListener('click', browserPrint);
    async function pollJob() {
        stop();
        if (!active?.job || !receiptIsOpen()) return;
        const snapshot = active;
        try {
            const response = await fetch(config.base + '/' + active.stay + '/print-jobs/' + active.job, {credentials:'same-origin', headers:{Accept:'application/json'}});
            if (!response.ok) throw new Error(config.failed);
            const result = await response.json();
            if (active !== snapshot || !receiptIsOpen()) return;
            if (result.status === 'done') {
                status(labels.agent_done); attempts.delete(active.document.url); active.attempt = null; active.job = null; setBusy(false); q('browser').hidden = true; return;
            }
            if (result.status === 'failed') {
                status(labels.agent_failed + (result.error ? ': ' + result.error : '')); setBusy(false);
                // No automatic copy after an ambiguous physical printer failure.
                q('print').disabled = true; q('browser').hidden = false; return;
            }
            status(labels.queued + ' #' + active.job + ' — ' + labels.awaiting_agent);
            if (++active.polls < 20) poll = setTimeout(pollJob, 1500);
            else { status(labels.awaiting_agent); setBusy(false); q('print').disabled = true; q('browser').hidden = false; }
        } catch (_) { if (active !== snapshot || !receiptIsOpen()) return; status(labels.awaiting_agent); setBusy(false); q('print').disabled = true; q('browser').hidden = false; }
    }
    q('print').addEventListener('click', async () => {
        if (!active?.ready || loading || active.job) return;
        if (!active.silent) { browserPrint(); return; }
        active.attempt ||= crypto.randomUUID(); attempts.set(active.document.url, {attempt:active.attempt, job:active.job}); setBusy(true); status(labels.sending);
        try {
            const body = {print_attempt_uuid: active.attempt};
            if (!active.document.bill_id) body.paper = active.paper;
            const url = sameOrigin(active.document.print_url).href;
            const response = window.nestposPrintBridge
                ? await window.nestposPrintBridge.enqueue(url, body, active.csrf, active.agent_online)
                : await fetch(url, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN':active.csrf}, body:JSON.stringify(body)});
            const result = await response.json();
            if (!response.ok || !result.success) {
                // These replies prove no job was created; fallback cannot duplicate it.
                if (response.status === 409 && ['disabled','agent_offline','counter_unavailable','no_printer'].includes(result.reason)) {
                    attempts.delete(active.document.url); active.attempt = null; status(labels.agent_unavailable + ' (' + result.reason + ')');
                    q('browser').hidden = false; return;
                }
                throw new Error(result.message || result.reason || config.failed);
            }
            active.job = result.job_id; active.polls = 0;
            attempts.set(active.document.url, {attempt:active.attempt, job:active.job});
            setBusy(false); q('print').disabled = true; q('picker').disabled = true;
            poll = setTimeout(pollJob, 300);
        } catch (failure) { status(failure.message + ' ' + labels.retry_print); }
        finally { if (!active?.job) setBusy(false); }
    });
    document.addEventListener('click', event => {
        const link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const target = new URL(link.href, location.href);
        if (target.origin !== location.origin) return;
        if (target.pathname === new URL(config.create).pathname && target.searchParams.get('walk_in') === '1') {
            event.preventDefault(); checkIn(target.href);
        } else if (/\/pos\/hotel\/stays\/\d+\/(?:bills\/\d+\/receipt|statement)$/.test(target.pathname)) {
            event.preventDefault(); openReceipt(target.href, link.closest('dialog'));
        }
    });
    window.hotelDeskPopups = {receipt: openReceipt};
})();
