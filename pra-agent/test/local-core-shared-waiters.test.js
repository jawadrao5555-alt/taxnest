'use strict';

const assert = require('assert');
const crypto = require('crypto');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { LocalCoreDomain } = require('../src/local-core/domain-engine');

const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'shared-waiters-'));
const key = crypto.createHash('sha256').update('shared-waiters').digest();
const secret = Buffer.alloc(32, 7).toString('base64url');
const rootScope = { company_id: 'co', branch_id: 'br', device_id: 'pc', user_id: 'owner' };
function authority(id, scope, owner) {
    const actions = owner ? ['*'] : ['order.hold'];
    return { lease_id: id, token: 'opaque-shared-waiter-lease-' + id, signing_secret: secret,
        next_sequence: 1, prev_hash: '0'.repeat(64), allowed_actions: actions,
        permissions: actions, expires_at_ms: Date.now() + 60000, scope, owner: !!owner, role: owner ? 'owner' : 'waiter' };
}
let serial = 0;
function command(scope, type, aggregate, revision, payload) {
    return { v: 1, id: 'shared-command-' + (++serial), type, aggregate_id: aggregate,
        expected_revision: revision, at_ms: Date.now() + serial, scope, payload: payload || {} };
}
function orderSnapshot(orderId, tableId) {
    return {
        order_id: orderId, business_date: '2026-09-03',
        order_type: tableId == null ? 'takeaway' : 'dine_in',
        catalog_revision: 1, table_id: tableId == null ? null : String(tableId),
        table_revision: tableId == null ? null : 2,
        table_snapshot: tableId == null ? null : { id: String(tableId), revision: 2, table_number: '7' },
        lines: [{
            line_id: 'line-' + orderId, product_id: 'tea', product_revision: 3,
            name: 'Tea', quantity: 1, unit_price_cents: 100,
            tax_snapshot: { rate_basis_points: 0 }, recipe_snapshot: [],
            deal_snapshot: [], direct_consumption_snapshot: [],
        }],
        totals: { subtotal_cents: 100, tax_cents: 0, discount_cents: 0, total_cents: 100 },
    };
}

try {
    const domain = new LocalCoreDomain({
        dataDir: dir, encryptionKey: key, authorityScope: rootScope,
        authority: authority(1, rootScope, true), allowAuthorityRotation: true,
    });
    domain.importSnapshot({
        schema: 'local-core.snapshot.v1', revision: 1, scope: rootScope,
        payload: {
            catalog: { revision: 1, products: [{ id: 'tea', revision: 3, name: 'Tea' }],
                ingredients: [], tables: [{ id: '7', revision: 2, table_number: '7' }] },
            orders: {}, sales: {}, tables: {}, stock: {}, recipes: {}, customers: {},
            cash_days: {}, staff_sessions: {}, settings: {},
        },
        hash: 'b'.repeat(64),
    });
    const waiterA = { company_id: 'co', branch_id: 'br', device_id: 'tablet-a', user_id: 'waiter-a' };
    const waiterB = { company_id: 'co', branch_id: 'br', device_id: 'tablet-b', user_id: 'waiter-b' };
    domain.registerActorSession(authority(2, waiterA, false));
    domain.registerActorSession(authority(3, waiterB, false));
    domain.execute(command(waiterB, 'order.hold', 'order-b', 0, {
        order_snapshot: orderSnapshot('order-b', null),
    }));
    domain.execute(command(waiterA, 'order.hold', 'order-a', 0, {
        order_snapshot: orderSnapshot('order-a', '7'),
    }));
    assert.throws(() => domain.execute(command(waiterB, 'order.hold', 'order-b-race', 0, {
        order_snapshot: orderSnapshot('order-b-race', '7'),
    })),
        (error) => error && error.code === 'already_claimed');
    assert.strictEqual(domain.snapshot().tables['7'].order_id, 'order-a', 'one shared table has exactly one winner');
    assert.strictEqual(domain.snapshot().orders['order-b-race'], undefined,
        'losing atomic hold leaves no partial order');
    assert.throws(() => domain.execute(command(waiterA, 'order.cancel', 'order-a', 1)),
        (error) => error && error.code === 'permission_denied');
    const ids = domain.events(0, 100).map((event) => event.id);
    assert.strictEqual(new Set(ids).size, ids.length, 'shared-domain event IDs are globally unique');
    const actorUsers = new Set(domain.events(0, 100).map((event) => event.scope.user_id));
    assert.deepStrictEqual(Array.from(actorUsers).sort(), ['waiter-a', 'waiter-b'],
        'both waiter authorities append to one shared event sequence');
    domain.close();
    console.log('shared waiter authority tests passed');
} finally {
    fs.rmSync(dir, { recursive: true, force: true });
}