'use strict';

const crypto = require('crypto');
const { MAX_PAYLOAD_BYTES } = require('./protocol');

function fail(code, message) {
    const error = new Error(message);
    error.code = code;
    throw error;
}

function object(value) {
    return !!value && typeof value === 'object' && !Array.isArray(value);
}

function money(value) {
    const number = Number(value);
    if (!Number.isFinite(number) || number < 0 || number > 999999999 || Math.round(number * 100) !== number * 100) {
        fail('invalid_sale', 'invalid money value');
    }
    return number;
}

function createSaleAcceptance(options) {
    const opts = options || {};
    if (typeof opts.storeProvider !== 'function' || typeof opts.scopeProvider !== 'function' ||
        typeof opts.isAuthorized !== 'function') throw new Error('sale acceptance dependencies are required');

    return function accept(context, command) {
        if (!opts.isAuthorized(context)) return { ok: false, error: 'unauthorized' };
        const store = opts.storeProvider();
        if (!store) return { ok: false, error: 'core_disabled' };
        try {
            if (!object(command) || Buffer.byteLength(JSON.stringify(command), 'utf8') > MAX_PAYLOAD_BYTES) {
                fail('invalid_sale', 'sale command is invalid or too large');
            }
            const expected = opts.scopeProvider();
            const scope = command.scope;
            if (!object(scope) || ['company_id', 'branch_id', 'device_id', 'user_id'].some((key) =>
                !expected || String(scope[key] || '') !== String(expected[key] || ''))) {
                fail('scope_mismatch', 'sale scope does not match the authenticated desktop session');
            }
            if (!/^[1-9]\d*$/.test(String(scope.company_id)) ||
                !/^[1-9]\d*$/.test(String(scope.branch_id)) ||
                !/^[1-9]\d*$/.test(String(scope.user_id)) || !String(scope.device_id || '')) {
                fail('scope_mismatch', 'sale requires positive company, branch, user and device scope');
            }
            if (typeof command.offline_uuid !== 'string' ||
                !/^[A-Za-z0-9][A-Za-z0-9._:-]{15,63}$/.test(command.offline_uuid)) {
                fail('invalid_sale', 'offline_uuid is required');
            }
            if (!['cash', 'card'].includes(command.payment_method) || command.provisional ||
                command.inventory_enabled || !command.pra_reporting_enabled || command.order_type || command.customer_credit ||
                command.incoming_order_id || command.recalled_order_id || command.table_id ||
                command.online_payment_confirmed) {
                fail('unsupported_sale', 'sale is outside the Local Core immediate-sale slice');
            }
            if (!Array.isArray(command.items) || !command.items.length || command.items.length > 200) {
                fail('invalid_sale', 'sale items are required');
            }
            const items = command.items.map((line) => {
                if (!object(line) || typeof line.name !== 'string' || !line.name.trim() || line.name.length > 255 ||
                    !Number.isInteger(Number(line.quantity)) || Number(line.quantity) < 1 || Number(line.quantity) > 9999) {
                    fail('invalid_sale', 'invalid sale line');
                }
                const unitPrice = money(line.unit_price);
                const lineTotal = money(line.line_total);
                if (line.type === 'deal') fail('unsupported_sale', 'deal sales are not supported offline');
                if (Math.abs(lineTotal - Math.round(unitPrice * Number(line.quantity) * 100) / 100) > 0.001) {
                    fail('totals_mismatch', 'line snapshot does not add up');
                }
                return {
                    name: line.name.trim(), quantity: Number(line.quantity), unit_price: unitPrice,
                    line_total: lineTotal, type: line.type || 'product', item_id: line.item_id || null,
                    is_tax_exempt: !!line.is_tax_exempt, _manual: !!line._manual,
                };
            });
            const totals = command.totals;
            if (!object(totals)) fail('invalid_sale', 'totals snapshot is required');
            const subtotal = money(totals.subtotal);
            const discountAmount = money(totals.discount_amount);
            const taxAmount = money(totals.tax_amount);
            const totalAmount = money(totals.total_amount);
            const lineSubtotal = Math.round(items.reduce((sum, item) => sum + item.line_total, 0) * 100) / 100;
            if (Math.abs(lineSubtotal - subtotal) > 0.001 ||
                (!totals.tax_inclusive && Math.round(subtotal - discountAmount + taxAmount) !== totalAmount) ||
                (totals.tax_inclusive && Math.round(subtotal - discountAmount) !== totalAmount)) {
                fail('totals_mismatch', 'sale totals do not add up');
            }
            const idHash = crypto.createHash('sha256').update(command.offline_uuid).digest('hex').slice(0, 32);
            const id = 'sale-' + idHash;
            const idempotencyKey = 'sale:' + command.offline_uuid;
            const existing = store.findByIdempotency(idempotencyKey);
            if (existing) {
                if (existing.id !== id) fail('idempotency_conflict', 'offline_uuid was reused');
                return { ok: true, status: 'duplicate', duplicate: true, event_id: existing.id,
                    receipt: existing.payload.receipt };
            }
            const next = store.allocateLocalSaleSequence();
            const deviceFragment = crypto.createHash('sha256').update(String(scope.device_id)).digest('hex').slice(0, 8).toUpperCase();
            const receipt = {
                local_bill_number: 'DL-' + deviceFragment + '-' + String(next).padStart(6, '0'),
                total_amount: totalAmount, payment_method: command.payment_method,
                accepted_at: new Date(Number(command.occurred_at_ms) || Date.now()).toISOString(),
                label: 'LOCAL / PRA PENDING',
            };
            const sale = {
                offline_uuid: command.offline_uuid, payment_method: command.payment_method,
                items, totals, discount_type: command.discount_type,
                discount_value: money(command.discount_value || 0),
                cash_received: command.payment_method === 'cash' && command.cash_received != null
                    ? money(command.cash_received) : null,
                terminal_id: command.terminal_id || null,
                customer_name: typeof command.customer_name === 'string' ? command.customer_name.slice(0, 255) : null,
                customer_phone: typeof command.customer_phone === 'string' ? command.customer_phone.slice(0, 40) : null,
            };
            const result = store.append({
                v: 1, id, idempotency_key: idempotencyKey, scope: {
                    company_id: String(scope.company_id), branch_id: String(scope.branch_id),
                    device_id: String(scope.device_id), user_id: String(scope.user_id),
                },
                type: 'sale.created', at_ms: Number(command.occurred_at_ms) || Date.now(),
                payload: { schema: 'pra.manual-immediate.v1', sale, receipt, local_sequence: next },
            });
            if (typeof opts.onAccepted === 'function') opts.onAccepted(result);
            return { ok: true, status: result.duplicate ? 'duplicate' : 'accepted_local',
                duplicate: !!result.duplicate, event_id: id, receipt: result.event.payload.receipt };
        } catch (error) {
            return { ok: false, error: error.code || 'invalid_sale' };
        }
    };
}

module.exports = { createSaleAcceptance };