{{-- ─────────────────────────────────────────────────────────────────────────
     Buyer send modal (Email / WhatsApp) — shared by invoice show + index.
     Task: DI invoice buyer ko WhatsApp/Email se seedha bhejna (all plans).

     Vanilla JS (no Alpine — the success modal opens it too, post-submit).
     Sits above the FBR success modal (z 60) via inline z-index:80.
     Buyer-facing message content stays ENGLISH (owner rule) — only the UI
     labels here are Roman Urdu.
──────────────────────────────────────────────────────────────────────────── --}}
<div id="sendInvoiceModal" style="display:none; z-index:80;" class="fixed inset-0 items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/60" onclick="closeSendModal()"></div>
    <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
        <div class="px-6 py-4 bg-emerald-600 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span id="sendModalHeaderIcon" class="flex items-center justify-center w-9 h-9 rounded-full bg-white/20 text-white"></span>
                <div>
                    <h3 class="text-base font-bold text-white" id="sendModalTitle">Invoice bhejein</h3>
                    <p class="text-xs text-emerald-100" id="sendModalInvoice"></p>
                </div>
            </div>
            <button type="button" onclick="closeSendModal()" class="text-white/70 hover:text-white text-2xl leading-none">&times;</button>
        </div>

        <div class="p-6">
            <div id="sendModalLoading" class="flex items-center gap-3 text-sm text-gray-500 dark:text-gray-400 py-4">
                <svg class="animate-spin h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                Buyer ki maloomat load ho rahi hai...
            </div>

            <div id="sendModalForm" style="display:none;">
                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5" id="sendModalFieldLabel" for="sendModalInput">Buyer ka email address</label>
                <input id="sendModalInput" type="email" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                       class="w-full px-3.5 py-2.5 border border-gray-300 dark:border-gray-700 rounded-lg text-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                       placeholder="buyer@example.com"
                       onkeydown="if(event.key==='Enter'){event.preventDefault(); submitSendModal();}">
                <p id="sendModalHint" style="display:none;" class="mt-2 text-xs text-amber-600 dark:text-amber-400"></p>

                <label class="mt-4 flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300 cursor-pointer">
                    <input type="checkbox" id="sendModalSave" checked class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                    Customer profile mein save karein
                </label>

                <p id="sendModalStatus" style="display:none;" class="mt-3 text-sm font-medium"></p>

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" onclick="closeSendModal()" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg text-sm font-medium hover:bg-gray-300 dark:hover:bg-gray-600 transition">Cancel</button>
                    <button type="button" id="sendModalSubmit" onclick="submitSendModal()" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-bold hover:bg-emerald-700 transition disabled:opacity-50"></button>
                </div>
            </div>

            <p id="sendModalLoadError" style="display:none;" class="text-sm text-red-600 py-2">Maloomat load nahi ho saki — page refresh kar ke dobara koshish karein.</p>
        </div>
    </div>
</div>

<script>
(function () {
    if (window._sendModalInit) return; // double-include guard
    window._sendModalInit = true;

    var ICON_MAIL = '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>';
    var ICON_WA = '<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.625.846 5.059 2.284 7.034L.789 23.492a.75.75 0 00.917.918l4.462-1.494A11.945 11.945 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-2.4 0-4.637-.734-6.482-1.988l-.452-.305-2.971.993.994-2.969-.316-.461A9.955 9.955 0 012 12C2 6.486 6.486 2 12 2s10 4.486 10 10-4.486 10-10 10z"/></svg>';

    window._sendModal = { invoiceId: null, channel: 'email', sending: false };

    function el(id) { return document.getElementById(id); }

    function setStatus(kind, msg) {
        var s = el('sendModalStatus');
        if (!msg) { s.style.display = 'none'; s.textContent = ''; return; }
        s.style.display = 'block';
        s.textContent = msg;
        s.className = 'mt-3 text-sm font-medium ' + (kind === 'ok' ? 'text-emerald-600' : 'text-red-600');
    }

    window.escapeHtmlSend = function (v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };

    window.openSendModal = async function (invoiceId, channel) {
        window._sendModal = { invoiceId: invoiceId, channel: channel, sending: false };
        var isWa = channel === 'whatsapp';

        el('sendModalTitle').textContent = isWa ? 'WhatsApp karein' : 'Email karein';
        el('sendModalHeaderIcon').innerHTML = isWa ? ICON_WA : ICON_MAIL;
        el('sendModalInvoice').textContent = '';
        el('sendModalSubmit').textContent = isWa ? 'WhatsApp kholein' : 'Email bhejein';
        el('sendModalHint').style.display = 'none';
        el('sendModalLoadError').style.display = 'none';
        el('sendModalForm').style.display = 'none';
        el('sendModalLoading').style.display = 'flex';
        setStatus('', '');

        var m = el('sendInvoiceModal');
        m.style.display = 'flex';

        try {
            var res = await fetch('/invoice/' + invoiceId + '/send-info', { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            var info = await res.json();
            if (window._sendModal.invoiceId !== invoiceId) return; // modal re-opened meanwhile

            el('sendModalInvoice').textContent = 'Invoice ' + (info.invoice_number || '') + (info.buyer_name ? ' — ' + info.buyer_name : '');
            var input = el('sendModalInput');
            if (isWa) {
                input.type = 'tel';
                input.placeholder = '0300-1234567';
                input.value = info.phone || '';
                el('sendModalFieldLabel').textContent = 'Buyer ka WhatsApp number';
            } else {
                input.type = 'email';
                input.placeholder = 'buyer@example.com';
                input.value = info.email || '';
                el('sendModalFieldLabel').textContent = 'Buyer ka email address';
            }
            if (!input.value) {
                var hint = el('sendModalHint');
                hint.textContent = isWa
                    ? 'Customer profile mein number mehfooz nahi tha — yahan likh dein, save bhi ho jayega.'
                    : 'Customer profile mein email mehfooz nahi tha — yahan likh dein, save bhi ho jayega.';
                hint.style.display = 'block';
            }
            el('sendModalLoading').style.display = 'none';
            el('sendModalForm').style.display = 'block';
            try { input.focus(); } catch (e) {}
        } catch (e) {
            el('sendModalLoading').style.display = 'none';
            el('sendModalLoadError').style.display = 'block';
        }
    };

    window.closeSendModal = function () {
        el('sendInvoiceModal').style.display = 'none';
    };

    window.submitSendModal = async function () {
        var ctx = window._sendModal;
        if (ctx.sending) return;
        var isWa = ctx.channel === 'whatsapp';
        var input = el('sendModalInput');
        var value = (input.value || '').trim();
        if (!value) {
            setStatus('error', isWa ? 'WhatsApp number likhein.' : 'Email address likhein.');
            return;
        }

        ctx.sending = true;
        var btn = el('sendModalSubmit');
        var orig = btn.textContent;
        btn.disabled = true;
        btn.textContent = isWa ? 'Tayar ho raha hai...' : 'Bhej rahe hain...';
        setStatus('', '');

        // Popup-blocker workaround: WhatsApp tab must be opened synchronously
        // inside the click handler, then pointed at the wa.me URL after the fetch.
        var waWin = null;
        if (isWa) { try { waWin = window.open('', '_blank'); } catch (e) { waWin = null; } }

        try {
            var fd = new FormData();
            fd.append('_token', (document.querySelector('meta[name="csrf-token"]') || {}).content || '');
            fd.append(isWa ? 'phone' : 'email', value);
            fd.append('save_to_profile', el('sendModalSave').checked ? '1' : '0');

            var res = await fetch('/invoice/' + ctx.invoiceId + (isWa ? '/send-whatsapp' : '/send-email'), {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            });
            var data = null;
            try { data = await res.json(); } catch (e) { data = null; }

            if (res.ok && data && data.status === 'ok') {
                if (isWa && data.wa_url) {
                    if (waWin) { waWin.location.href = data.wa_url; }
                    else { window.open(data.wa_url, '_blank'); }
                }
                setStatus('ok', data.message || 'Bhej diya gaya.');
                if (data.delivery) window.prependDeliveryRow(data.delivery);
                setTimeout(window.closeSendModal, 1500);
            } else {
                if (waWin) { try { waWin.close(); } catch (e) {} }
                var msg = 'Send nahi ho saka — dobara koshish karein.';
                if (data && data.message) msg = data.message;
                else if (data && data.errors) { var k = Object.keys(data.errors); if (k.length) msg = data.errors[k[0]][0]; }
                setStatus('error', msg);
                if (data && data.delivery) window.prependDeliveryRow(data.delivery);
            }
        } catch (e) {
            if (waWin) { try { waWin.close(); } catch (e2) {} }
            setStatus('error', 'Network masla — connection check kar ke dobara koshish karein.');
        }

        ctx.sending = false;
        btn.disabled = false;
        btn.textContent = orig;
    };

    // Live-prepend a delivery row on the invoice page history card (no reload).
    window.prependDeliveryRow = function (d) {
        var list = document.getElementById('deliveryHistoryList');
        if (!list || !d) return;
        var empty = document.getElementById('deliveryHistoryEmpty');
        if (empty) empty.style.display = 'none';
        var isWa = d.channel === 'whatsapp';
        var ok = d.status === 'sent';
        var li = document.createElement('li');
        li.className = 'py-2.5 flex items-center gap-3';
        li.innerHTML =
            '<span class="flex items-center justify-center w-8 h-8 rounded-full flex-shrink-0 ' + (isWa ? 'bg-green-500' : 'bg-blue-500') + ' text-white">' + (isWa ? ICON_WA : ICON_MAIL) + '</span>' +
            '<div class="flex-1 min-w-0">' +
                '<p class="text-sm font-semibold text-gray-900 dark:text-gray-100 truncate">' + window.escapeHtmlSend((isWa ? '+' : '') + d.recipient) + '</p>' +
                '<p class="text-xs text-gray-400">' + (isWa ? 'WhatsApp' : 'Email') + ' &middot; by ' + window.escapeHtmlSend(d.user || '') + ' &middot; ' + window.escapeHtmlSend(d.at || '') + '</p>' +
            '</div>' +
            (ok
                ? '<span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">Sent</span>'
                : '<span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">Failed</span>');
        list.insertBefore(li, list.firstChild);
    };

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var m = document.getElementById('sendInvoiceModal');
            if (m && m.style.display !== 'none' && !window._sendModal.sending) window.closeSendModal();
        }
    });
})();
</script>
