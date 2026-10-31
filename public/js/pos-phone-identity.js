/* Shared search identity only; never rewrites saved customer or bill snapshots. */
(function (root) {
    'use strict';
    const digits = value => String(value || '').replace(/\D/g, '');
    function canonical(raw) {
        let n = digits(raw);
        if (n.startsWith('00')) n = n.slice(2);
        if (n.startsWith('920') && n.length === 13) n = '92' + n.slice(3);
        if (n.startsWith('92')) return n.length >= 11 && n.length <= 12 ? n : null;
        if (n.startsWith('0')) { n = '92' + n.slice(1); return n.length >= 11 && n.length <= 12 ? n : null; }
        if (n.startsWith('3') && n.length === 10) return '92' + n;
        return n.length >= 10 && n.length <= 15 ? n : null;
    }
    root.TNPosPhone = {
        canonical,
        matches(value, query) {
            const term = String(query || '').trim();
            if (!/^[0-9+().\s-]+$/.test(term)) return false;
            const key = digits(term);
            if (key.length < 3) return false;
            const full = canonical(term);
            return full ? canonical(value) === full : digits(value).includes(key);
        }
    };
})(typeof window === 'undefined' ? globalThis : window);
