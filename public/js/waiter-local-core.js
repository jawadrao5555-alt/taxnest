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
            // Cloud-baked catalog rows carry no recipe fields; the Local Core's
            // own `recipes` projection (keyed by product id) is then the frozen
            // truth — the domain compares the supplied parts against exactly it.
            if (recipeSource == null && state.recipes && state.recipes[String(item.item_id)] != null) {
                recipeSource = state.recipes[String(item.item_id)];
            }
            var directSource = product.direct_consumption_snapshot == null ?
                product.direct_consumption : product.direct_consumption_snapshot;
            var deal = clone(product.deal_snapshot || product.deal_components || product.components || []);
            if (!Array.isArray(deal)) deal = [];
            deal.forEach(function (component) {
                component.recipe_snapshot = parts(component.recipe_snapshot || component.recipe);
                component.direct_consumption_snapshot = parts(component.direct_consumption_snapshot ||
                    component.direct_consumption);
            });
            var recipe = parts(recipeSource);
            // tax_snapshot is written in the ONE canonical shape the settlement
            // normalizer, the cloud sale projector and the held-snapshot match
            // all require ({rate_basis_points, exempt, inclusive,
            // menu_rate_basis_points}) — the counter settles this line VERBATIM
            // later, so a waiter-only key spelling here would strand the sale.
            var exempt = !!(item.is_tax_exempt || product.is_tax_exempt || product.is_third_schedule);
            var menuRate = body.tax_menu_rate_basis_points == null || body.tax_menu_rate_basis_points === ''
                ? null : Math.round(Number(body.tax_menu_rate_basis_points));
            return {
                line_id: String(item.line_id || ('waiter-line-' + orderId + '-' + index)),
                type: 'product', item_type: 'product',
                product_id: String(item.item_id), product_revision: revisionOf(product),
                name: String(item.name || product.name || ''), quantity: quantity,
                unit_price_cents: price, special_notes: item.special_notes || null,
                tax_snapshot: {
                    rate_basis_points: Math.round(Number(body.tax_rate_basis_points || 0)),
                    exempt: exempt,
                    inclusive: body.tax_inclusive === true,
                    menu_rate_basis_points: Number.isFinite(menuRate) ? menuRate : null
                },
                has_recipe: recipe.length > 0,
                recipe_revision: revisionOf(product.recipe_snapshot || product.recipe),
                recipe_snapshot: recipe,
                deal_snapshot: deal,
                direct_consumption_snapshot: parts(directSource)
            };
        });
        var tax = body.tax_inclusive === true ? 0 : Math.round(lines.reduce(function (sum, line) {
            var rate = line.tax_snapshot.exempt ? 0 :
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
    // Offline KOT (Sep 2026): the kitchen slip rides INSIDE the hold. The shop
    // PC prints it from its local print_queue; the cloud, receiving the same
    // document in the order.held event, holds its own KOT back (local handoff)
    // and stamps the lines only from the shop PC's printed ack — one slip.
    function buildKotDocument(body, snapshot, options) {
        var opts = options || {};
        var lines = snapshot.lines.map(function (line) {
            return { line_id: line.line_id, name: line.name, quantity: line.quantity,
                special_notes: line.special_notes || null };
        });
        if (!lines.length) return null;
        return {
            kind: 'kot', order_id: snapshot.order_id, order_type: snapshot.order_type,
            table_label: opts.tableLabel == null ? (snapshot.table_snapshot ? snapshot.table_snapshot.table_number : null) : String(opts.tableLabel),
            token_label: null, order_label: opts.orderLabel == null ? null : String(opts.orderLabel),
            waiter_name: opts.waiterName == null ? null : String(opts.waiterName),
            customer_name: body.customer_name || null,
            kitchen_notes: body.kitchen_notes || null, priority: body.priority === true,
            lines: lines
        };
    }
    function fallbackOrder(body, appendOrderId, options) {
        if (!bridge()) return { ok: false, unavailable: true };
        // There is no sequential offline append vocabulary. Never partially mutate an existing order.
        if (appendOrderId) return { ok: false, error: 'offline_append_unavailable' };
        var orderId = String(body.hold_uuid || '');
        if (!orderId) return { ok: false, error: 'hold_id_required' };
        var opts = options || {};
        var snapshot = buildOrderSnapshot(body, orderId, querySnapshot());
        // Counter's offline held-orders list shows who punched the order.
        if (opts.waiterName != null && String(opts.waiterName)) snapshot.waiter_name = String(opts.waiterName);
        var payload = { order_snapshot: snapshot };
        // Only shops with silent KOT printing configured get a local slip; the
        // others print from the counter exactly as before (cloud stamps nothing).
        if (opts.kot === true) {
            var kot = buildKotDocument(body, snapshot, opts);
            if (kot) payload.kot_document = kot;
        }
        var command = { v: 1, id: 'order-hold:' + orderId, type: 'order.hold',
            aggregate_id: orderId, expected_revision: 0, at_ms: Date.now(),
            payload: payload };
        var response = JSON.parse(bridge().command(JSON.stringify(command)) || '{}');
        if (!response.ok) throw new Error(response.error || 'local_core_rejected');
        return { ok: true, local: true, order_id: orderId, durable_acceptance: response.result,
            kot_queued: !!payload.kot_document };
    }
    // ── Offline READERS (Sep 2026) ────────────────────────────────────────
    // Cloud stays first. These fill the tables list and "meray orders" from
    // the shop PC's projections only after the cloud fetch failed, in the exact
    // shapes /pos/waiter/api/tables and /pos/waiter/api/orders return, so the
    // screen code stays identical. Every row carries local_source:'shop_pc'.
    function orderLines(order) {
        var lines = Array.isArray(order.lines) ? order.lines : (Array.isArray(order.items) ? order.items : []);
        return lines.map(function (line) {
            return {
                id: line.id == null ? (line.line_id || null) : line.id,
                item_id: line.item_id == null ? (line.product_id == null ? null : line.product_id) : line.item_id,
                item_type: line.item_type || line.type || 'product',
                name: String(line.item_name == null ? (line.name || '') : line.item_name),
                quantity: Number(line.quantity || 0),
                unit_price: line.unit_price != null ? Number(line.unit_price) :
                    (line.unit_price_cents != null ? Number(line.unit_price_cents) / 100 : 0),
                subtotal: line.subtotal != null ? Number(line.subtotal) :
                    (line.line_subtotal_cents != null ? Number(line.line_subtotal_cents) / 100 :
                        (line.unit_price_cents != null ? Number(line.unit_price_cents) * Number(line.quantity || 0) / 100 : 0)),
                special_notes: line.special_notes || null,
                is_tax_exempt: !!(line.is_tax_exempt || (line.tax_snapshot && (line.tax_snapshot.is_tax_exempt || line.tax_snapshot.exempt))),
                printed: line.kot_printed_at != null
            };
        });
    }
    function isLocalOrder(order) {
        // Cloud-projected orders come keyed by their numeric cloud id; local
        // (offline) holds carry a uuid aggregate id and status 'open'.
        return order && order.status === 'open' && order.held_at_ms != null;
    }
    function isOpenOrder(order) {
        if (!order || !order.status) return false;
        return isLocalOrder(order) || ['held', 'preparing', 'ready'].indexOf(String(order.status)) !== -1;
    }
    function orderTotal(order) {
        if (order.totals && order.totals.total_cents != null) return Number(order.totals.total_cents) / 100;
        return Number(order.total_amount || 0);
    }
    function orderNumber(order, id) {
        if (order.order_number) return String(order.order_number);
        // Local uuid → short human label (last 4 chars), same idea as the counter.
        return 'L-' + String(id).replace(/-/g, '').slice(-4).toUpperCase();
    }
    function openOrders(state) {
        var orders = state.orders && typeof state.orders === 'object' ? state.orders : {};
        return Object.keys(orders).filter(function (id) {
            return id.indexOf('held:') !== 0 && isOpenOrder(orders[id]);
        }).map(function (id) { return { id: id, order: orders[id] }; });
    }
    function toOrderJson(id, order, tables, floors) {
        var tableId = order.table_id == null ? null : String(order.table_id);
        var table = tableId == null ? null : tables.find(function (t) { return String(t.id) === tableId; });
        var items = orderLines(order);
        var createdMs = order.held_at_ms != null ? Number(order.held_at_ms) :
            (order.created_at ? new Date(String(order.created_at).replace(' ', 'T')).getTime() : Date.now());
        var created = new Date(isNaN(createdMs) ? Date.now() : createdMs);
        return {
            id: isLocalOrder(order) ? String(id) : (isNaN(Number(id)) ? String(id) : Number(id)),
            local: isLocalOrder(order), local_source: 'shop_pc',
            order_number: orderNumber(order, id),
            order_type: String(order.order_type || 'dine_in'),
            table_id: tableId == null ? null : (isNaN(Number(tableId)) ? tableId : Number(tableId)),
            table: table ? table.table_number : (order.table_snapshot ? order.table_snapshot.table_number : null),
            customer_name: order.customer_name || (order.customer_ref && order.customer_ref.name) || null,
            token_no: order.token_no == null ? null : Number(order.token_no),
            customer_phone: order.customer_phone || (order.customer_ref && order.customer_ref.phone) || null,
            kitchen_notes: order.kitchen_notes || (order.immutable_metadata && order.immutable_metadata.kitchen_notes) || null,
            waiter: order.waiter || null,
            assigned_cashier: null, assigned_cashier_id: order.assigned_cashier_id || null,
            subtotal: order.totals && order.totals.subtotal_cents != null ? Number(order.totals.subtotal_cents) / 100 : Number(order.subtotal || 0),
            total_amount: orderTotal(order),
            unprinted_count: items.filter(function (i) { return !i.printed; }).length,
            kot_sent_at: order.kot_sent_at || (order.kot_job_id ? new Date(createdMs).toISOString() : null),
            items: items,
            created_at: created.toISOString(),
            created_time: String(created.getHours()).padStart(2, '0') + ':' + String(created.getMinutes()).padStart(2, '0')
        };
    }
    function listTables() {
        if (!bridge()) return null;
        var state = querySnapshot();
        var catalog = state.catalog || {};
        var tables = entities(catalog, 'tables');
        var floors = entities(catalog, 'floors');
        var claims = state.tables && typeof state.tables === 'object' ? state.tables : {};
        var open = openOrders(state);
        return tables.filter(function (t) {
            return !(t.active === false || t.is_active === false || t.deleted_at);
        }).map(function (t) {
            var id = String(t.id);
            var floor = floors.find(function (f) { return String(f.id) === String(t.floor_id); });
            var held = open.filter(function (entry) { return String(entry.order.table_id) === id; })
                .map(function (entry) {
                    var json = toOrderJson(entry.id, entry.order, tables, floors);
                    return { id: json.id, order_number: json.order_number, items_count: json.items.length,
                        total_amount: json.total_amount, local: json.local,
                        items: json.items.map(function (i) { return { name: i.name, quantity: i.quantity }; }) };
                });
            var claimed = !!claims[id];
            var status = held.length || claimed ? 'occupied' : String(t.status || 'available');
            var since = t.occupied_since || null;
            if (!since && claimed && claims[id].claimed_at_ms) since = new Date(Number(claims[id].claimed_at_ms)).toISOString();
            return {
                id: isNaN(Number(id)) ? id : Number(id), table_number: t.table_number,
                floor: floor ? floor.name : (t.floor || ''), seats: t.seats == null ? null : Number(t.seats),
                status: status, active_orders: held.length,
                occupied_since: status === 'occupied' ? since : null,
                order_id: held.length ? held[0].id : null,
                order_number: held.length ? held[0].order_number : null,
                held_orders: held, local_source: 'shop_pc'
            };
        });
    }
    function myOrders(userId) {
        if (!bridge()) return null;
        var state = querySnapshot();
        var catalog = state.catalog || {};
        var tables = entities(catalog, 'tables');
        var floors = entities(catalog, 'floors');
        var me = userId == null ? null : String(userId);
        return openOrders(state).filter(function (entry) {
            var order = entry.order;
            if (!['held', 'open'].includes(String(order.status))) return false;
            if (me == null) return true;
            var owner = isLocalOrder(order) ? order.held_by_user_id : order.created_by;
            return owner != null && String(owner) === me;
        }).sort(function (a, b) {
            return (Number(b.order.held_at_ms) || Number(new Date(b.order.created_at)) || 0) -
                (Number(a.order.held_at_ms) || Number(new Date(a.order.created_at)) || 0);
        }).map(function (entry) { return toOrderJson(entry.id, entry.order, tables, floors); });
    }
    window.TaxNestWaiterLocalCore = {
        available: function () { return !!bridge(); },
        buildOrderSnapshot: buildOrderSnapshot,
        buildKotDocument: buildKotDocument,
        fallbackOrder: fallbackOrder,
        listTables: listTables,
        myOrders: myOrders
    };
}());