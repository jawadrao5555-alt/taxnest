/* Versioned PRA deal preview. Keep paisa allocation in sync with PosDealAllocation. */
(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.PosDealAllocation = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';
    const r2 = n => Math.round((n + Number.EPSILON) * 100) / 100;
    const supported = snapshot => Array.isArray(snapshot) && snapshot.length > 0 &&
        snapshot.every(c => Number(c.allocation_version) === 1 && c.allocation_weight != null && Number(c.allocation_weight) >= 0);
    function cents(total, weights) {
        weights = weights.map(w => Math.max(0, Number(w)));
        if (!weights.length) return [];
        if (weights.reduce((a,b) => a+b, 0) <= 0) weights = weights.map(() => 1);
        const sum = weights.reduce((a,b) => a+b, 0), exact = weights.map(w => Math.abs(total) * w / sum);
        const result = exact.map(Math.floor);
        const order = weights.map((_,i) => i).sort((a,b) => (exact[b]-result[b])-(exact[a]-result[a]) || a-b);
        const left = Math.abs(total)-result.reduce((a,b) => a+b, 0);
        for (let i=0; i<left; i++) result[order[i % order.length]]++;
        return total < 0 ? result.map(v => -v) : result;
    }
    function weights(snapshot) {
        const result = snapshot.map(c => Number(c.allocation_weight));
        return result.reduce((a,b) => a+b, 0) > 0 ? result : snapshot.map(c => Math.max(1, Number(c.quantity || c.qty || 1)));
    }
    const exempt = c => !!(c.tax_facts?.is_tax_exempt || c.tax_facts?.is_third_schedule);
    function taxable(snapshot, subtotal, legacyExempt) {
        if (!supported(snapshot)) return legacyExempt ? 0 : subtotal;
        return cents(Math.round(subtotal*100), weights(snapshot)).reduce((n,v,i) => n + (exempt(snapshot[i]) ? 0 : v), 0)/100;
    }
    function capture(snapshot, subtotal, discount, rate, inclusive, menuRate) {
        const gross = cents(Math.round(subtotal*100), weights(snapshot));
        const discounts = cents(Math.min(gross.reduce((a,b) => a+b,0), Math.max(0, Math.round(discount*100))), gross);
        return snapshot.map((component,i) => {
            const net = (gross[i]-discounts[i])/100, isExempt = exempt(component), ownRate = isExempt ? 0 : rate;
            const cardSave = menuRate != null && menuRate > 0 && Math.abs(menuRate-ownRate) >= .005;
            const tax = inclusive ? (ownRate > 0 ? r2(net*ownRate/(100+(cardSave ? menuRate : ownRate))) : 0) : r2(net*ownRate/100);
            const base = !inclusive || isExempt ? net : (cardSave ? r2(net*100/(100+menuRate)) : r2(net-tax));
            return { ...component, allocation: {subtotal:gross[i]/100,discount:discounts[i]/100,
                taxable_base:isExempt ? 0 : base, exempt_base:isExempt ? base : 0,
                tax_rate:ownRate,tax,total:r2(base+tax),tax_inclusive:inclusive,menu_rate:menuRate ?? null} };
        });
    }
    function bill(lines, discount, rate, inclusive, menuRate) {
        if (!lines.some(line => supported(line.snapshot))) return null;
        const gross = lines.map(line => Math.max(0, Math.round(line.subtotal*100)));
        discount = Math.min(gross.reduce((a,b) => a+b,0),Math.max(0,Math.round(discount*100)))/100;
        const discounts = cents(Math.round(discount*100), gross);
        let taxableBase=0, exemptBase=0, tax=0;
        const captured = lines.map((line,i) => {
            const source = supported(line.snapshot) ? line.snapshot : [{allocation_version:1,allocation_weight:1,tax_facts:{is_tax_exempt:!!line.exempt}}];
            const snapshot = capture(source,gross[i]/100,discounts[i]/100,rate,inclusive,menuRate);
            for (const c of snapshot) {taxableBase+=c.allocation.taxable_base;exemptBase+=c.allocation.exempt_base;tax+=c.allocation.tax;}
            return {discount:discounts[i]/100,snapshot};
        });
        tax = inclusive ? r2(tax) : Math.round(taxableBase*rate/100);
        return {lines:captured,discount_amount:discount,subtotal_col:r2(taxableBase+exemptBase+discount),
            tax_amount:tax,exempt_amount:r2(exemptBase),total_amount:Math.round(taxableBase+exemptBase+tax)};
    }
    return {supported,cents,taxable,capture,bill};
});
