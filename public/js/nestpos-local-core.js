(function (window, document) {
    'use strict';
    var desktop = window.nestposDesktop;
    if (!desktop || !desktop.localCore || desktop.localCore.version !== 1) return;

    var serial = 0;
    function id(prefix) {
        serial += 1;
        return String(prefix || 'pos') + '-' + Date.now().toString(36) + '-' + serial.toString(36);
    }
    function emit(result) {
        var state = result && result.state || 'rejected';
        document.documentElement.setAttribute('data-local-core-state', state);
        window.dispatchEvent(new CustomEvent('nestpos:local-core-state', { detail: result }));
        return result;
    }
    function timeoutFetch(url, options, timeout) {
        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var timer = setTimeout(function () { if (controller) controller.abort(); }, timeout || 8000);
        var opts = Object.assign({}, options || {});
        if (controller) opts.signal = controller.signal;
        return fetch(url, opts).finally(function () { clearTimeout(timer); });
    }
    function parse(response) {
        return response.json().catch(function () { return {}; }).then(function (body) {
            if (!response.ok || body.success === false || body.ok === false) throw Object.assign(new Error(body.message || 'Cloud request rejected'), {
                cloudRejected: response.status < 500, response: response, body: body
            });
            return Object.assign({ ok: true, success: true, state: 'cloud', local: false, redirect: response.url }, body);
        });
    }
    function command(type, aggregateId, payload, revision, commandId) {
        function invoke(expected) {
            return desktop.localCore.command({
                v: 1, id: commandId || id(type.replace(/\./g, '-')), type: type,
                aggregate_id: String(aggregateId), expected_revision: Number(expected || 0),
                payload: payload || {}
            }).then(emit);
        }
        if (revision !== undefined && revision !== null) return invoke(revision);
        return desktop.localCore.query({ v: 1, projection: 'revisions', id: String(aggregateId) })
            .then(function (answer) { return invoke(answer && answer.data || 0); });
    }
    function query(projection, projectionId) {
        return desktop.localCore.query({ v: 1, projection: projection, id: projectionId }).then(emit);
    }
    // Cloud remains authoritative whenever it is reachable. 4xx responses are
    // real business rejections and are never converted into local success.
    function cloudFirst(spec) {
        spec = spec || {};
        return timeoutFetch(spec.url, spec.options, spec.timeout).then(parse).then(emit).catch(function (error) {
            if (error && error.cloudRejected) return emit({
                ok: false, success: false, state: 'rejected', error: 'cloud_rejected',
                status: error.response.status, message: error.message
            });
            if (spec.external || !spec.command) return emit({
                ok: false, success: false, local: false, pending: false, state: 'rejected',
                error: 'external_call_not_queued', message: 'External submission needs internet and was not queued.'
            });
            return command(spec.command.type, spec.command.aggregate_id, spec.command.payload,
                spec.command.expected_revision, spec.command.id);
        });
    }
    function flow(type) {
        return function (aggregateId, payload, revision, cloud) {
            if (cloud && cloud.url) return cloudFirst(Object.assign({}, cloud, { command: {
                type: type, aggregate_id: aggregateId, payload: payload || {}, expected_revision: revision
            }}));
            return command(type, aggregateId, payload, revision);
        };
    }
    function reject(error, message, extra) {
        return emit(Object.assign({
            ok: false, success: false, local: true, pending: false,
            state: 'rejected', error: error, message: message
        }, extra || {}));
    }
    function normalizeHeldSaleSnapshot(orderId, input) {
        function invalid(message) { var error = new Error(message); error.code = 'invalid_sale_snapshot'; throw error; }
        function finite(value, name) {
            var number = Number(value);
            if (!Number.isFinite(number)) invalid(name + ' must be numeric');
            return number;
        }
        function moneyCents(value, name) {
            var number = finite(value, name);
            if (number < 0 || Math.abs(number * 100 - Math.round(number * 100)) > 0.000001) {
                invalid(name + ' must be non-negative money with at most two decimals');
            }
            return Math.round(number * 100);
        }
        var aggregate = String(orderId || '');
        if (!aggregate || !input || typeof input !== 'object' || Array.isArray(input)) invalid('sale snapshot is required');
        if (input.order_id != null && String(input.order_id) !== aggregate) invalid('order_id does not match settlement aggregate');
        if (!/^\d{4}-\d{2}-\d{2}$/.test(String(input.business_date || ''))) invalid('business_date is required');
        if (!input.offline_uuid || !Array.isArray(input.items) || !input.items.length) invalid('offline_uuid and items are required');
        var paymentMethod = input.payment_method || (input.payment && input.payment.method);
        if (typeof paymentMethod !== 'string' || !paymentMethod) invalid('payment method is required');
        var items = input.items.map(function (line, index) {
            if (!line || typeof line !== 'object') invalid('sale line is invalid');
            var quantity = finite(line.quantity, 'line quantity');
            if (!Number.isInteger(quantity) || quantity < 1) invalid('line quantity must be a positive integer');
            var unitCents = line.unit_price_cents == null
                ? moneyCents(line.unit_price, 'line unit price')
                : finite(line.unit_price_cents, 'line unit price cents');
            if (!Number.isInteger(unitCents) || unitCents < 0) invalid('line unit price cents must be a non-negative integer');
            var lineId = String(line.line_id == null ? (line.id == null ? '' : line.id) : line.line_id);
            if (!lineId) invalid('line_id is required');
            if (!line.tax_snapshot || typeof line.tax_snapshot !== 'object' || Array.isArray(line.tax_snapshot)) {
                invalid('complete tax_snapshot is required');
            }
            if (!Number.isInteger(line.tax_snapshot.rate_basis_points) ||
                typeof line.tax_snapshot.exempt !== 'boolean' ||
                typeof line.tax_snapshot.inclusive !== 'boolean' ||
                !Object.prototype.hasOwnProperty.call(line.tax_snapshot, 'menu_rate_basis_points')) {
                invalid('tax_snapshot is incomplete');
            }
            if (!Array.isArray(line.recipe_snapshot)) invalid('recipe_snapshot is required');
            if (typeof line.has_recipe !== 'boolean') invalid('recipe applicability must be explicit');
            if (line.has_recipe === true && !line.recipe_snapshot.length) invalid('recipe snapshot cannot be empty for a recipe item');
            line.recipe_snapshot.forEach(function (part) {
                if (!part || !part.stock_id || !Number.isFinite(Number(part.quantity)) || Number(part.quantity) <= 0) {
                    invalid('recipe snapshot part is invalid');
                }
            });
            var lineType = line.type || line.item_type || 'product';
            var dealSnapshot = line.deal_snapshot;
            if (lineType === 'deal') {
                if (!Array.isArray(dealSnapshot) || !dealSnapshot.length) {
                    invalid('deal line requires an immutable component snapshot');
                }
                dealSnapshot = dealSnapshot.map(function (component) {
                    if (!component || !component.deal_id || !component.deal_version ||
                        !component.deal_name || !Number.isFinite(Number(component.deal_price)) ||
                        !component.product_id || !Number.isInteger(Number(component.quantity || component.qty)) ||
                        Number(component.quantity || component.qty) < 1 ||
                        !['direct', 'recipe'].includes(component.mode) ||
                        !component.tax_facts || typeof component.tax_facts !== 'object' ||
                        typeof component.tax_facts.is_tax_exempt !== 'boolean' ||
                        typeof component.tax_facts.is_third_schedule !== 'boolean' ||
                        !component.tax_facts.company_id ||
                        !Array.isArray(component.recipe_snapshot)) {
                        invalid('deal component snapshot is incomplete');
                    }
                    if (String(component.deal_id) !== String(line.item_id || line.product_id)) {
                        invalid('deal component identity does not match the sale line');
                    }
                    if (component.mode === 'recipe' && !component.recipe_snapshot.length) {
                        invalid('recipe deal component requires its frozen recipe');
                    }
                    if (component.mode === 'direct' && component.recipe_snapshot.length) {
                        invalid('direct deal component cannot carry a recipe');
                    }
                    component.recipe_snapshot.forEach(function (part) {
                        if (!part || !part.stock_id || !Number.isFinite(Number(part.quantity)) || Number(part.quantity) <= 0) {
                            invalid('deal component recipe snapshot is invalid');
                        }
                    });
                    return Object.assign({}, component, {
                        product_id: String(component.product_id),
                        quantity: Number(component.quantity || component.qty),
                        qty: Number(component.quantity || component.qty),
                        recipe_snapshot: component.recipe_snapshot.map(function (part) { return Object.assign({}, part); }),
                        tax_facts: Object.assign({}, component.tax_facts),
                    });
                });
            } else if (dealSnapshot != null) {
                invalid('non-deal line cannot carry a deal snapshot');
            }
            var expectedLine = unitCents * quantity;
            if (line.line_total != null && moneyCents(line.line_total, 'line total') !== expectedLine) {
                invalid('line total does not match quantity and unit price');
            }
            return Object.assign({}, line, {
                line_id: lineId,
                product_id: line.product_id == null ? (line.item_id == null ? null : String(line.item_id)) : String(line.product_id),
                quantity: quantity,
                unit_price_cents: unitCents,
                unit_price: unitCents / 100,
                line_total: expectedLine / 100,
                tax_snapshot: Object.assign({}, line.tax_snapshot),
                recipe_snapshot: line.recipe_snapshot.map(function (part) { return Object.assign({}, part); }),
                deal_snapshot: dealSnapshot || null,
            });
        });
        var totalsIn = input.totals;
        if (!totalsIn || typeof totalsIn !== 'object' || Array.isArray(totalsIn)) invalid('totals are required');
        var subtotal = totalsIn.subtotal_cents == null ? moneyCents(totalsIn.subtotal, 'subtotal') : finite(totalsIn.subtotal_cents, 'subtotal cents');
        var tax = totalsIn.tax_cents == null ? moneyCents(totalsIn.tax_amount, 'tax') : finite(totalsIn.tax_cents, 'tax cents');
        var discount = totalsIn.discount_cents == null ? moneyCents(totalsIn.discount_amount, 'discount') : finite(totalsIn.discount_cents, 'discount cents');
        var total = totalsIn.total_cents == null ? moneyCents(totalsIn.total_amount, 'total') : finite(totalsIn.total_cents, 'total cents');
        [subtotal, tax, discount, total].forEach(function (value) {
            if (!Number.isInteger(value) || value < 0) invalid('all totals must be non-negative integer cents');
        });
        var lineSubtotal = items.reduce(function (sum, line) { return sum + line.unit_price_cents * line.quantity; }, 0);
        if (subtotal !== lineSubtotal || discount > subtotal) invalid('subtotal or discount is inconsistent with immutable lines');
        if (!totalsIn.tax_inclusive && total !== subtotal - discount + tax) invalid('exclusive totals are inconsistent');
        if (totalsIn.tax_inclusive) {
            var pricing = input.tax_pricing;
            if (!pricing || !Number.isInteger(pricing.rate_basis_points)) invalid('inclusive tax pricing snapshot is required');
            var menuBasis = Number.isInteger(pricing.menu_rate_basis_points)
                ? pricing.menu_rate_basis_points : pricing.rate_basis_points;
            var taxableGross = items.reduce(function (sum, line) {
                return sum + (line.tax_snapshot.exempt ? 0 : line.unit_price_cents * line.quantity);
            }, 0);
            var taxableAfter = subtotal ? taxableGross * (subtotal - discount) / subtotal : 0;
            var exemptAfter = subtotal - discount - taxableAfter;
            var expectedTax = Math.round(taxableAfter * pricing.rate_basis_points / (10000 + menuBasis));
            var expectedTotal = Math.round((exemptAfter +
                taxableAfter * (10000 + pricing.rate_basis_points) / (10000 + menuBasis)) / 100) * 100;
            if (tax !== expectedTax || total !== expectedTotal) invalid('inclusive totals are inconsistent');
        }
        var payment = Object.assign({}, input.payment || {}, {
            method: paymentMethod,
            terminal_id: input.terminal_id == null ? ((input.payment || {}).terminal_id || null) : input.terminal_id,
        });
        if (input.cash_received != null) payment.cash_received_cents = moneyCents(input.cash_received, 'cash received');
        return Object.assign({}, input, {
            order_id: aggregate,
            business_date: String(input.business_date),
            payment_method: paymentMethod,
            payment: payment,
            items: items,
            totals: Object.assign({}, totalsIn, {
                subtotal_cents: subtotal, tax_cents: tax, discount_cents: discount, total_cents: total,
                subtotal: subtotal / 100, tax_amount: tax / 100,
                discount_amount: discount / 100, total_amount: total / 100,
            }),
            immutable_refs: {
                order: Object.assign({}, input.order_ref || {}),
                customer: Object.assign({}, input.customer_ref || {}),
                offline_uuid: String(input.offline_uuid),
            },
            immutable_metadata: {
                occurred_at_ms: input.occurred_at_ms || null,
                terminal_id: input.terminal_id || null,
                delivery_address: input.delivery_address || null,
                tax_pricing: Object.assign({}, input.tax_pricing || {}),
            },
        });
    }
    // Settlement is one atomic domain command. The immutable sale input travels
    // inside that command so a rejected/interrupted settlement can never leave a
    // standalone sale event behind.
    function settleHeldOrder(orderId, sale, settlement, revision) {
        if (!sale || !settlement || !sale.offline_uuid) {
            return Promise.resolve(reject('sale_snapshot_required', 'A complete immutable sale snapshot is required.'));
        }
        var normalized;
        try { normalized = normalizeHeldSaleSnapshot(orderId, sale); }
        catch (error) { return Promise.resolve(reject(error.code || 'invalid_sale_snapshot', error.message)); }
        var payload = Object.assign({}, settlement, {
            sale_snapshot: normalized,
            offline_uuid: sale.offline_uuid,
        });
        return command('order.settle', orderId, payload, revision,
            'order-settle:' + String(sale.offline_uuid)).then(function (result) {
            if (result && result.ok) return result;
            return reject((result && result.error) || 'settlement_rejected',
                (result && result.message) || 'Local Core rejected the order settlement.');
        });
    }
    function holdOrder(orderId, snapshot, revision) {
        if (!snapshot || String(snapshot.order_id || '') !== String(orderId) ||
            !snapshot.idempotency_key || !snapshot.business_date ||
            !Array.isArray(snapshot.lines) || !snapshot.lines.length ||
            !snapshot.totals || typeof snapshot.totals !== 'object') {
            return Promise.resolve(reject('invalid_hold_snapshot', 'A complete immutable held-order snapshot is required.'));
        }
        var invalidLine = snapshot.lines.some(function (line) {
            if (!line || !line.line_id || !Number.isInteger(line.quantity) || line.quantity < 1 ||
                !Number.isInteger(line.unit_price_cents) || line.unit_price_cents < 0 ||
                !line.tax_snapshot || typeof line.tax_snapshot !== 'object' ||
                !Array.isArray(line.recipe_snapshot) || !Array.isArray(line.deal_snapshot) ||
                !Array.isArray(line.direct_consumption_snapshot) ||
                (line.has_recipe === true && !line.recipe_snapshot.length)) return true;
            if ((line.type || line.item_type) !== 'deal') return line.deal_snapshot.length !== 0;
            return !Array.isArray(line.deal_snapshot) || !line.deal_snapshot.length ||
                line.deal_snapshot.some(function (component) {
                    return !component || !component.deal_id || !component.deal_version ||
                        !component.deal_name || !Number.isFinite(Number(component.deal_price)) ||
                        !component.product_id || !Number.isInteger(Number(component.quantity || component.qty)) ||
                        !['direct', 'recipe'].includes(component.mode) ||
                        !component.tax_facts || !Array.isArray(component.recipe_snapshot) ||
                        (component.mode === 'recipe' && !component.recipe_snapshot.length) ||
                        (component.mode === 'direct' && component.recipe_snapshot.length);
                });
        });
        var totalKeys = ['gross_subtotal_cents', 'item_discount_cents', 'subtotal_cents',
            'tax_cents', 'discount_cents', 'total_cents'];
        if (invalidLine || totalKeys.some(function (key) {
            return !Number.isInteger(snapshot.totals[key]) || snapshot.totals[key] < 0;
        })) {
            return Promise.resolve(reject('invalid_hold_snapshot', 'A held-order line or total snapshot is incomplete.'));
        }
        return command('order.hold', orderId, { order_snapshot: snapshot },
            revision == null ? 0 : revision, 'order-hold:' + String(snapshot.idempotency_key))
            .then(function (result) {
                if (result && result.ok) return result;
                return reject((result && result.error) || 'hold_rejected',
                    (result && result.message) || 'Local Core rejected the held order.');
            });
    }
    var api = {
        version: 1, available: true, request: cloudFirst, command: command, query: query,
        normalizeHeldSaleSnapshot: normalizeHeldSaleSnapshot,
        normalizeSettlementSnapshot: normalizeHeldSaleSnapshot,
        heldOrder: {
            hold: holdOrder, open: flow('order.open'), addLine: flow('order.line.add'),
            consumeLine: flow('order.line.consume'), claim: flow('order.claim'),
            settle: flow('order.settle'), settleWithSale: settleHeldOrder,
            cancel: flow('order.cancel')
        },
        table: {
            claim: flow('table.claim'), release: flow('table.release'),
            shift: function (from, to, orderId, revision) {
                // A shift is one order-aggregate transition. Release+claim could
                // strand the order without a table if the second append rejected.
                return command('table.shift', orderId, {
                    order_id: orderId, source_table_id: from,
                    target_table_id: to, table_aggregate_id: String(to)
                }, revision);
            }
        },
        kot: { request: flow('print.enqueue') },
        customer: {
            upsert: flow('customer.upsert'), khata: flow('khata.debit'),
            wasooli: flow('wasooli.record'), refund: flow('refund.record')
        },
        inventory: { set: flow('stock.set'), adjust: flow('stock.adjust') },
        cash: { open: flow('cash.open'), expense: flow('cash.expense'), close: flow('cash.close') },
        staff: { start: flow('staff.start'), end: flow('staff.end') },
        printQueue: {
            enqueue: flow('print.enqueue'), claim: flow('print.claim'),
            complete: flow('print.complete'), fail: flow('print.fail')
        },
        external: function (url, options) { return cloudFirst({ url: url, options: options, external: true }); },
        bindForm: function (form, commandFactory) {
            if (!form || form.__nestPosLocalBound) return;
            form.__nestPosLocalBound = true;
            form.addEventListener('submit', function (event) {
                if (event.defaultPrevented) return;
                event.preventDefault();
                var values = {};
                new FormData(form).forEach(function (value, key) { values[key] = value; });
                var commandSpec = commandFactory(values, form);
                cloudFirst({
                    url: form.action, options: { method: form.method || 'POST', body: new FormData(form),
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } },
                    command: commandSpec
                }).then(function (result) {
                    if (result && result.state === 'cloud') window.location.assign(result.redirect || form.action);
                });
            });
        }
    };
    Object.defineProperty(window, 'NestPosLocal', { value: Object.freeze(api), configurable: false });

    var badge = document.createElement('div');
    badge.id = 'tn-local-core-state';
    badge.setAttribute('aria-live', 'polite');
    badge.style.cssText = 'display:none;position:fixed;right:10px;bottom:10px;z-index:2147483647;padding:5px 9px;border-radius:7px;background:#92400e;color:#fff;font:700 11px system-ui';
    badge.textContent = 'LOCAL';
    window.addEventListener('nestpos:local-core-state', function (event) {
        var state = event.detail && event.detail.state;
        badge.style.display = state === 'cloud' ? 'none' : 'block';
        badge.textContent = state === 'pending' ? 'LOCAL · PENDING' : state === 'rejected' ? 'LOCAL · REJECTED' : 'LOCAL';
        badge.style.background = state === 'rejected' ? '#991b1b' : state === 'local' ? '#166534' : '#92400e';
    });
    function number(value) { var n = Number(value); return Number.isFinite(n) ? n : 0; }
    function cents(value) { return Math.round(number(value) * 100); }
    function bindMarkedForms() {
        document.querySelectorAll('form[data-local-core-command]').forEach(function (form) {
            api.bindForm(form, function (values) {
                var type = form.getAttribute('data-local-core-command');
                var aggregate = form.getAttribute('data-local-core-aggregate') ||
                    values[form.getAttribute('data-local-core-aggregate-field') || 'id'] || id('aggregate');
                var payload = Object.assign({}, values);
                if (type === 'stock.adjust' && values.type === 'set') {
                    type = 'stock.set'; payload = { quantity: number(values.quantity) };
                } else if (type === 'stock.adjust') payload = { delta: values.type === 'remove' ? -number(values.quantity) : number(values.quantity) };
                if (type === 'stock.set') payload = { quantity: number(values.quantity) };
                if (type === 'cash.open') payload = { business_date: form.getAttribute('data-business-date'), opening_cents: cents(values.opening_cash) };
                if (type === 'cash.expense') payload = { business_date: form.getAttribute('data-business-date'), amount_cents: cents(values.amount), note: values.note || '' };
                if (type === 'cash.close') payload = { business_date: form.getAttribute('data-business-date'), counted_cents: cents(values.counted_cash || values.cash_in_hand || 0) };
                if (type === 'refund.record') {
                    var amount = 0, lines = [];
                    form.querySelectorAll('[data-local-refund-line]').forEach(function (input) {
                        if (number(input.value) <= 0) return;
                        amount += number(input.value) * number(input.getAttribute('data-unit-price'));
                        lines.push(input.getAttribute('data-local-refund-line'));
                    });
                    payload = { order_id: form.getAttribute('data-order-id'), amount_cents: cents(amount),
                        method: values.refund_method, line_ids: lines };
                }
                return { type: type, aggregate_id: aggregate, payload: payload };
            });
        });
    }
    document.addEventListener('DOMContentLoaded', function () { document.body.appendChild(badge); bindMarkedForms(); });
}(window, document));