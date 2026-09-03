(function () {
    'use strict';
    // Production marker used by release tests: TAXNEST_LOCAL_CORE_WAITER_FALLBACK_V2_ATOMIC_HOLD
    function bridge() {
        return window.TaxNestLocalCore &&
            typeof window.TaxNestLocalCore.command === 'function' &&
            typeof window.TaxNestLocalCore.query === 'function' ? window.TaxNestLocalCore : null;
    }
    function querySnapshot() {
        var raw = bridge().query(JSON.stringify({ query: 'snapshot' }));
        var result = JSON.parse(raw || '{}');
        if (!result.ok) throw new Error(result.error || 'local_core_snapshot_failed');
        return result.result || {};
    }
    function entities(catalog, name) {
        var value = catalog && catalog[name];
        return Array.isArray(value) ? value : (value && typeof value === 'object' ? Object.values(value) : []);
    }
    function idOf(value) {
        return String(value && (value.id == null ? value.product_id : value.id));
    }
    function revisionOf(value) {
        return value && (value.revision == null ?
            (value.version == null ? value.updated_at : value.version) : value.revision);
    }
    function parts(value) {
        var source = Array.isArray(value) ? value : (value && Array.isArray(value.parts) ? value.parts : []);
        return source.map(function (part) {
            return {
                stock_id: String(part.stock_id == null ? part.ingredient_id : part.stock_id),
                ingredient_revision: part.ingredient_revision == null ? part.stock_revision : part.ingredient_revision,
                quantity: Number(part.quantity == null ? part.quantity_needed : part.quantity)
            };
        });
    }
    function clone(value) {
        return JSON.parse(JSON.stringify(value == null ? null : value));
    }
    function buildOrderSnapshot(body, orderId, state) {
        var catalog = state.catalog || {};
        var products = entities(catalog, 'products');
        var tables = entities(catalog, 'tables');
        var subtotal = 0;
        var lines = (body.items || []).map(function (item, index) {
            var product = products.find(function (candidate) { return idOf(candidate) === String(item.item_id); });
            if (!product) throw new Error('product_not_found');
            var price = Math.round(Number(item.unit_price) * 100);
            var quantity = Number(item.quantity);
            if (!Number.isInteger(price) || !Number.isInteger(quantity) || quantity < 1) {
                throw new Error('invalid_waiter_line');
            }
            subtotal += price * quantity;
            var recipeSource = product.recipe_snapshot == null ? product.recipe : product.recipe_snapshot;
            var directSource = product.direct_consumption_snapshot == null ?
                product.direct_consumption : product.direct_consumption_snapshot;
            var deal = clone(product.deal_snapshot || product.deal_components || product.components || []);
            if (!Array.isArray(deal)) deal = [];
            deal.forEach(function (component) {
                component.recipe_snapshot = parts(component.recipe_snapshot || component.recipe);
                component.direct_consumption_snapshot = parts(component.direct_consumption_snapshot ||
                    component.direct_consumption);
            });
            return {
                line_id: String(item.line_id || ('waiter-line-' + orderId + '-' + index)),
                product_id: String(item.item_id), product_revision: revisionOf(product),
                name: String(item.name || product.name || ''), quantity: quantity,
                unit_price_cents: price, special_notes: item.special_notes || null,
                tax_snapshot: clone(product.tax_snapshot || {
                    rate_basis_points: Number(body.tax_rate_basis_points || 0),
                    is_tax_exempt: !!item.is_tax_exempt,
                    tax_inclusive: body.tax_inclusive === true
                }),
                recipe_revision: revisionOf(product.recipe_snapshot || product.recipe),
                recipe_snapshot: parts(recipeSource),
                deal_snapshot: deal,
                direct_consumption_snapshot: parts(directSource)
            };
        });
        var tax = body.tax_inclusive === true ? 0 : Math.round(lines.reduce(function (sum, line) {
            var rate = line.tax_snapshot.is_tax_exempt ? 0 :
                Number(line.tax_snapshot.rate_basis_points || body.tax_rate_basis_points || 0);
            return sum + line.unit_price_cents * line.quantity * rate / 10000;
        }, 0));
        var tableId = body.table_id == null ? null : String(body.table_id);
        var table = tableId == null ? null : tables.find(function (candidate) {
            return String(candidate.id == null ? candidate.table_id : candidate.id) === tableId;
        });
        if (tableId && !table) throw new Error('table_not_found');
        var now = new Date();
        var businessDate = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') +
            '-' + String(now.getDate()).padStart(2, '0');
        return {
            order_id: orderId, idempotency_key: String(body.hold_uuid || orderId),
            business_date: businessDate, order_type: String(body.order_type || 'takeaway'),
            cashier_id: body.cashier_id == null ? null : String(body.cashier_id),
            customer_name: body.customer_name || null, customer_phone: body.customer_phone || null,
            kitchen_notes: body.kitchen_notes || null, priority: body.priority === true,
            catalog_revision: revisionOf(catalog), table_id: tableId,
            table_revision: revisionOf(table), table_snapshot: clone(table),
            lines: lines, totals: {
                subtotal_cents: subtotal, tax_cents: tax, discount_cents: 0,
                total_cents: subtotal + tax, tax_inclusive: body.tax_inclusive === true
            }
        };
    }
    function fallbackOrder(body, appendOrderId) {
        if (!bridge()) return { ok: false, unavailable: true };
        // There is no sequential offline append vocabulary. Never partially mutate an existing order.
        if (appendOrderId) return { ok: false, error: 'offline_append_unavailable' };
        var orderId = String(body.hold_uuid || '');
        if (!orderId) return { ok: false, error: 'hold_id_required' };
        var snapshot = buildOrderSnapshot(body, orderId, querySnapshot());
        var command = { v: 1, id: 'order-hold:' + orderId, type: 'order.hold',
            aggregate_id: orderId, expected_revision: 0, at_ms: Date.now(),
            payload: { order_snapshot: snapshot } };
        var response = JSON.parse(bridge().command(JSON.stringify(command)) || '{}');
        if (!response.ok) throw new Error(response.error || 'local_core_rejected');
        return { ok: true, local: true, order_id: orderId, durable_acceptance: response.result };
    }
    window.TaxNestWaiterLocalCore = {
        available: function () { return !!bridge(); },
        buildOrderSnapshot: buildOrderSnapshot,
        fallbackOrder: fallbackOrder
    };
}());