(() => {
    'use strict';
    function open(id) {
        const dialog = document.getElementById(id);
        if (dialog?.matches('dialog[data-hotel-credit-dialog]') && !dialog.open) dialog.showModal();
    }
    document.addEventListener('click', event => {
        const trigger = event.target.closest('[data-credit-open]');
        if (trigger) open(trigger.dataset.creditOpen);
        const close = event.target.closest('[data-credit-close]');
        if (close) close.closest('dialog').close();
    });
    document.addEventListener('submit', event => {
        const form = event.target.closest('form[data-credit-simple]');
        if (!form) return;
        if (form.dataset.submitting === '1') { event.preventDefault(); return; }
        form.dataset.submitting = '1';
        form.querySelector('button[type="submit"]').disabled = true;
    });
    function fromHash() {
        const match = location.hash.match(/^#(bill|note)-(\d+)$/);
        if (!match) return;
        if (match[1] === 'note') {
            const id = 'credit-result-' + match[2];
            open(id);
            const url = window.hotelDeskPopupConfig?.autoReceipt;
            if (url && window.hotelDeskPopups) {
                window.hotelDeskPopupConfig.autoReceipt = null;
                window.hotelDeskPopups.receipt(url, document.getElementById(id));
            }
        }
        else {
            const trigger = document.getElementById('bill-' + match[2])?.querySelector('[data-credit-open]');
            if (trigger) open(trigger.dataset.creditOpen);
        }
    }
    window.addEventListener('hashchange', fromHash);
    window.addEventListener('pageshow', () => {
        document.querySelectorAll('form[data-credit-simple]').forEach(form => {
            delete form.dataset.submitting;
            form.querySelector('button[type="submit"]').disabled = false;
        });
    });
    // The receipt popup script appears after this slot; wait for every deferred script.
    if (document.readyState === 'complete' || window.hotelDeskPopups) fromHash();
    else document.addEventListener('DOMContentLoaded', fromHash, {once:true});
})();
