'use strict';

/* Pure Node tests: node test/local-core-domain.test.js */
const assert = require('assert');
const crypto = require('crypto');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { LocalCoreDomain } = require('../src/local-core/domain');
const { createBackup, restoreBackup } = require('../src/local-core/backup');
const { EventStore } = require('../src/local-core/event-store');
const { signWireEvent } = require('../src/local-core/lease-chain');
const leaseFixture = require('../src/local-core/lease-chain-fixture.json');
const settlementFixture = require('../../tests/Fixtures/local-core-held-settlement.json');
const holdFixture = require('../../tests/Fixtures/local-core-held-order.json');

const KEY = crypto.createHash('sha256').update('local-core-domain-tests').digest();
const scope = { company_id: 'co-1', branch_id: 'br-1', device_id: 'dev-1', user_id: 'user-1' };
let serial = 0;
function command(type, aggregate, revision, payload, overrides) {
    serial++;
    return Object.assign({ v: 1, id: 'command-' + String(serial).padStart(6, '0'), type,
        aggregate_id: aggregate, expected_revision: revision, at_ms: 1700000000000 + serial,
        scope, payload: payload || {} }, overrides || {});
}
function temp() { return fs.mkdtempSync(path.join(os.tmpdir(), 'local-domain-')); }
function expect(code, fn) { assert.throws(fn, (e) => e && e.code === code); }
function ownerAuthority() {
    return { lease_id: 17, token: 'opaque-owner-token', signing_secret: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
        next_sequence: 1, prev_hash: '0'.repeat(64), scope, owner: true, allowed_actions: ['*'] };
}
function baseline(engine, products, ingredients, stock, tables, recipes) {
    engine.importSnapshot({
        schema: 'local-core.snapshot.v1', revision: 1, scope,
        hash: crypto.createHash('sha256').update(JSON.stringify([products, ingredients, stock, tables, recipes])).digest('hex'),
        payload: { catalog: { revision: 1, products, ingredients: ingredients || [], tables: tables || [] },
            orders: {}, sales: {}, tables: {}, stock: stock || {}, recipes: recipes || {},
            customers: {}, cash_days: {}, staff_sessions: {}, settings: {} },
    });
}
function heldSnapshot(orderId, businessDate, lines, extras) {
    const subtotal = lines.reduce((sum, line) => sum + line.unit_price_cents * line.quantity, 0);
    return Object.assign({ order_id: orderId, business_date: businessDate, order_type: 'takeaway',
        lines, totals: { subtotal_cents: subtotal, tax_cents: 0, discount_cents: 0, total_cents: subtotal },
    }, extras || {});
}

(function run() {
    const fixtureResult = signWireEvent(leaseFixture.wire_event, Object.assign({
        signing_secret: leaseFixture.signing_secret,
    }, leaseFixture.authority));
    assert.strictEqual(fixtureResult.canonical_json, leaseFixture.canonical_json);
    assert.strictEqual(fixtureResult.signature, leaseFixture.signature);
    assert.strictEqual(fixtureResult.chain_hash, leaseFixture.chain_hash);

    const root = temp();
    try {
        let engine = new LocalCoreDomain({ dataDir: root, encryptionKey: KEY, authorityScope: scope, authority: ownerAuthority() });
        baseline(engine, [{ id: 'tea', revision: 4 }], [{ id: 'milk', revision: 2 }], { milk: 10 });
        const hold = command('order.hold', 'order-1', 0, { order_snapshot: heldSnapshot('order-1', '2026-09-02', [{
            line_id: 'line-1', product_id: 'tea', product_revision: 4, name: 'Tea', quantity: 2,
            unit_price_cents: 100, tax_snapshot: { rate_basis_points: 1600 },
            recipe_snapshot: [{ stock_id: 'milk', ingredient_revision: 2, quantity: 2 }],
            deal_snapshot: [], direct_consumption_snapshot: [],
        }], { catalog_revision: 1 }) });
        assert.strictEqual(engine.execute(hold).duplicate, false);
        assert.strictEqual(engine.execute(hold).duplicate, true);
        const wireEvent = engine.eventStore.pending(1)[0];
        assert.deepStrictEqual(Object.keys(wireEvent.payload), [
            'schema', 'command_type', 'aggregate_id', 'aggregate_revision', 'data',
        ]);
        assert.strictEqual(wireEvent.type, 'order.held');
        assert.strictEqual(wireEvent.payload.schema, 'local-core.order.v1');
        assert.ok(wireEvent.scope_lease_id && wireEvent.scope_lease);
        assert.strictEqual(engine.snapshot().stock.milk, 6);
        assert.strictEqual(engine.snapshot().orders['order-1'].held_by_user_id, 'user-1');
        assert.strictEqual(engine.snapshot().orders['order-1'].kot_job_id, null, 'no kot document = no kitchen slip');
        engine.execute(command('order.cancel', 'order-1', 1));
        assert.strictEqual(engine.snapshot().stock.milk, 10, 'only consumed inventory is restored');

        // Offline KOT rides inside order.hold as a LOCAL-ONLY print job.
        const kotLine = { line_id: 'kot-line-1', product_id: 'tea', product_revision: 4, name: 'Tea', quantity: 1,
            unit_price_cents: 100, tax_snapshot: { rate_basis_points: 1600 },
            recipe_snapshot: [{ stock_id: 'milk', ingredient_revision: 2, quantity: 1 }],
            deal_snapshot: [], direct_consumption_snapshot: [] };
        expect('invalid_kot_document', () => engine.execute(command('order.hold', 'kot-bad', 0, {
            order_snapshot: heldSnapshot('kot-bad', '2026-09-02', [kotLine], { catalog_revision: 1 }),
            kot_document: { kind: 'kot', lines: [{ line_id: 'missing', name: 'Tea', quantity: 1 }] },
        })));
        assert.ok(!engine.snapshot().orders['kot-bad'], 'a bad kot document rejects the whole hold');
        const kotHold = command('order.hold', 'kot-order', 0, {
            order_snapshot: heldSnapshot('kot-order', '2026-09-02', [kotLine], { catalog_revision: 1 }),
            kot_document: { kind: 'kot', order_id: 'kot-order', order_type: 'dine_in', table_label: 'T4',
                lines: [{ line_id: 'kot-line-1', name: 'Tea', quantity: 1, special_notes: 'less sugar' }] },
        });
        engine.execute(kotHold);
        const kotWire = engine.eventStore.pending(50).find((e) => e.payload.aggregate_id === 'kot-order');
        assert.strictEqual(kotWire.payload.data.kot_document.lines[0].name, 'Tea', 'cloud receives the same kot document');
        assert.strictEqual(engine.snapshot().orders['kot-order'].kot_job_id, 'kot:kot-order');
        let jobs = engine.localPrintJobs();
        assert.strictEqual(jobs.length, 1);
        assert.strictEqual(jobs[0].id, 'kot:kot-order');
        assert.strictEqual(jobs[0].kind, 'kot');
        expect('claim_conflict', () => engine.execute(command('print.claim', 'kot:kot-order', 0, { claim_token: 'x' })),
            'local-only slips are never claimable through the outbox vocabulary');
        assert.strictEqual(engine.eventStore.pending(50).some((e) => e.payload.aggregate_id === 'kot:kot-order'), false,
            'a local kitchen slip never becomes an outbox event');
        const claimed = engine.claimLocalPrint('kot:kot-order', 'dev-1');
        assert.strictEqual(claimed.status, 'claimed');
        assert.strictEqual(engine.localPrintJobs().length, 0, 'claimed jobs leave the drain list');
        expect('claim_conflict', () => engine.finishLocalPrint('kot:kot-order', 'wrong-token', true));
        const requeued = engine.finishLocalPrint('kot:kot-order', claimed.claim_token, false, 'printer offline');
        assert.strictEqual(requeued.status, 'queued');
        assert.strictEqual(requeued.last_error, 'printer offline');
        assert.strictEqual(engine.localPrintJobs(Date.now()).length, 0, 'a failed slip waits out its backoff');
        assert.strictEqual(engine.localPrintJobs(Date.now() + 10 * 60 * 1000).length, 1, 'then retries');
        const again = engine.claimLocalPrint('kot:kot-order', 'dev-1');
        assert.strictEqual(engine.finishLocalPrint('kot:kot-order', again.claim_token, true).status, 'completed');
        assert.strictEqual(engine.eventStore.pending(50).some((e) => e.payload.aggregate_id === 'kot:kot-order'), false);
        assert.strictEqual(engine.snapshot().print_queue['kot:kot-order'].status, 'completed');
        engine.execute(command('order.cancel', 'kot-order', 1));
        assert.strictEqual(engine.snapshot().orders['order-1'].lines[0].tax_snapshot.rate_basis_points, 1600);

        engine.execute(command('customer.upsert', 'customer-1', 0, { name: 'A' }));
        engine.execute(command('khata.debit', 'customer-1', 1, { amount_cents: 500, reference: 'sale-1' }));
        expect('exceeds_outstanding', () => engine.execute(command('wasooli.record', 'customer-1', 2, { amount_cents: 501 })));
        assert.strictEqual(engine.snapshot().customers['customer-1'].balance_cents, 500);
        engine.execute(command('wasooli.record', 'customer-1', 2, { amount_cents: 300 }));
        assert.strictEqual(engine.snapshot().customers['customer-1'].balance_cents, 200);

        engine.execute(command('cash.open', 'cash-open-1', 0, { business_date: '2026-09-03', opening_cents: 1000 }));
        engine.execute(command('cash.close', 'cash-close-1', 0, { business_date: '2026-09-03', counted_cents: 1000 }));
        expect('business_day_closed', () => engine.execute(command('cash.expense', 'expense-1', 0, {
            business_date: '2026-09-03', amount_cents: 1,
        })));
        expect('business_day_closed', () => engine.execute(command('order.hold', 'late-order', 0, {
            order_snapshot: heldSnapshot('late-order', '2026-09-03', [{
                line_id: 'late-line', product_id: 'tea', quantity: 1, unit_price_cents: 1,
                tax_snapshot: {}, recipe_snapshot: [], deal_snapshot: [], direct_consumption_snapshot: [],
            }]),
        })));

        engine.execute(command('print.enqueue', 'print-1', 0, { document: { kind: 'receipt', order_id: 'order-1' } }));
        engine.execute(command('print.claim', 'print-1', 1, { claim_token: 'claim-a' }));
        expect('revision_conflict', () => engine.execute(command('print.claim', 'print-1', 1, { claim_token: 'claim-b' })));
        expect('claim_conflict', () => engine.execute(command('print.complete', 'print-1', 2, { claim_token: 'claim-b' })));
        engine.execute(command('print.complete', 'print-1', 2, { claim_token: 'claim-a' }));

        expect('scope_mismatch', () => engine.execute(command('stock.set', 'foreign', 0, { quantity: 1 }, {
            scope: Object.assign({}, scope, { branch_id: 'br-2' }),
        })));
        const identity = engine.nextIdentity('receipt');
        assert.strictEqual(identity, 1);
        engine.close();
        engine = new LocalCoreDomain({ dataDir: root, encryptionKey: KEY, authorityScope: scope, authority: ownerAuthority() });
        assert.strictEqual(engine.nextIdentity('receipt'), 2);
        assert.ok(!fs.readFileSync(path.join(root, 'domain-state.bin')).includes(Buffer.from('customer-1')));

        const bytes = fs.readFileSync(path.join(root, 'domain-state.bin'));
        bytes[bytes.length - 20] ^= 1;
        fs.writeFileSync(path.join(root, 'domain-state.bin'), bytes);
        expect('state_corrupt', () => new LocalCoreDomain({ dataDir: root, encryptionKey: KEY, authorityScope: scope }));
    } finally { fs.rmSync(root, { recursive: true, force: true }); }

    const crashRoot = temp();
    try {
        const stable = new LocalCoreDomain({ dataDir: crashRoot, encryptionKey: KEY, authorityScope: scope, authority: ownerAuthority() });
        stable.execute(command('stock.set', 'stock-1', 0, { quantity: 5 }));
        const faulty = new LocalCoreDomain({ dataDir: crashRoot, encryptionKey: KEY, authorityScope: scope,
            authority: ownerAuthority(), fault: (stage) => { if (stage === 'before_commit') throw new Error('power loss'); } });
        assert.throws(() => faulty.execute(command('stock.adjust', 'stock-1', 1, { delta: -1 })), /power loss/);
        const recovered = new LocalCoreDomain({ dataDir: crashRoot, encryptionKey: KEY, authorityScope: scope, authority: ownerAuthority() });
        assert.strictEqual(recovered.snapshot().stock['stock-1'], 4);
        assert.strictEqual(recovered.snapshot().sequence, 2);
    } finally { fs.rmSync(crashRoot, { recursive: true, force: true }); }

    // Each durable transaction seam recovers to one projection and one queued
    // canonical cloud event (never one without the other).
    ['after_marker', 'after_event_append', 'after_generation', 'before_commit', 'after_state_commit'].forEach((stage) => {
        const root = temp();
        try {
            const base = new LocalCoreDomain({ dataDir: root, encryptionKey: KEY, authorityScope: scope, authority: ownerAuthority() });
            base.execute(command('stock.set', 'recovery-stock', 0, { quantity: 2 }));
            const interrupted = new LocalCoreDomain({ dataDir: root, encryptionKey: KEY, authorityScope: scope,
                authority: ownerAuthority(), fault: (at) => { if (at === stage) throw new Error('simulated loss ' + stage); } });
            assert.throws(() => interrupted.execute(command('stock.adjust', 'recovery-stock', 1, { delta: 1 })));
            const recovered = new LocalCoreDomain({ dataDir: root, encryptionKey: KEY, authorityScope: scope, authority: ownerAuthority() });
            assert.strictEqual(recovered.snapshot().stock['recovery-stock'], 3, stage);
            assert.strictEqual(recovered.events(0, 99).length, 2, stage);
            assert.strictEqual(new EventStore({ dataDir: root, encryptionKey: KEY }).pending(99)
                .filter((e) => e.payload && e.payload.command_type === 'stock.adjust').length, 1, stage);
        } finally { fs.rmSync(root, { recursive: true, force: true }); }
    });

    // Holding is a single recoverable stock/order/table/event transaction.
    ['after_marker', 'after_event_append', 'after_generation', 'before_commit', 'after_state_commit'].forEach((stage) => {
        const root = temp();
        try {
            const base = new LocalCoreDomain({ dataDir: root, encryptionKey: KEY,
                authorityScope: scope, authority: ownerAuthority() });
            baseline(base, [{ id: '50', revision: 3 }], [{ id: 'ingredient-4', revision: 8 }],
                { 'ingredient-4': 5 }, [{ id: '70', revision: 2 }]);
            const hold = command('order.hold', 'crash-hold-' + stage, 0, {
                order_snapshot: heldSnapshot('crash-hold-' + stage, '2026-09-04', [{
                    line_id: 'fixture-line', product_id: '50', product_revision: 3,
                    quantity: 2, unit_price_cents: 500, tax_snapshot: {},
                    recipe_snapshot: [{ stock_id: 'ingredient-4', ingredient_revision: 8, quantity: 1 }],
                    deal_snapshot: [], direct_consumption_snapshot: [],
                }], { table_id: '70', table_revision: 2, catalog_revision: 1 }),
            });
            const interrupted = new LocalCoreDomain({ dataDir: root, encryptionKey: KEY,
                authorityScope: scope, authority: ownerAuthority(),
                fault: (at) => { if (at === stage) throw new Error('hold loss ' + stage); } });
            assert.throws(() => interrupted.execute(hold));
            const recovered = new LocalCoreDomain({ dataDir: root, encryptionKey: KEY,
                authorityScope: scope, authority: ownerAuthority() });
            assert.strictEqual(recovered.snapshot().stock['ingredient-4'], 3, stage);
            assert.strictEqual(recovered.snapshot().tables['70'].order_id, 'crash-hold-' + stage, stage);
            assert.strictEqual(recovered.snapshot().orders['crash-hold-' + stage].lines.length, 1, stage);
            const events = recovered.eventStore.pending(99).filter((event) =>
                event.type === 'order.held' && event.payload.aggregate_id === 'crash-hold-' + stage);
            assert.strictEqual(events.length, 1, stage);
            assert.strictEqual(recovered.execute(hold).duplicate, true, stage);
            assert.strictEqual(recovered.snapshot().stock['ingredient-4'], 3, stage);
        } finally { fs.rmSync(root, { recursive: true, force: true }); }
    });

    const contentionRoot = temp();
    try {
        const first = new LocalCoreDomain({ dataDir: contentionRoot, encryptionKey: KEY,
            authorityScope: scope, authority: ownerAuthority() });
        baseline(first, [{ id: '50' }], [{ id: 'ingredient-4' }], { 'ingredient-4': 2 },
            [{ id: '70' }, { id: '71' }]);
        const second = new LocalCoreDomain({ dataDir: contentionRoot, encryptionKey: KEY,
            authorityScope: scope, authority: ownerAuthority() });
        const snapshotFor = (id, table) => heldSnapshot(id, '2026-09-04', [{
            line_id: id + '-line', product_id: '50', quantity: 2, unit_price_cents: 500,
            tax_snapshot: {}, recipe_snapshot: [{ stock_id: 'ingredient-4', quantity: 1 }],
            deal_snapshot: [], direct_consumption_snapshot: [],
        }], { table_id: table });
        first.execute(command('order.hold', 'winner-order', 0, { order_snapshot: snapshotFor('winner-order', '70') }));
        expect('insufficient_stock', () => second.execute(command('order.hold', 'stock-loser', 0,
            { order_snapshot: snapshotFor('stock-loser', '71') })));
        assert.ok(!second.snapshot().orders['stock-loser']);
        assert.strictEqual(second.snapshot().stock['ingredient-4'], 0);
        first.execute(command('order.cancel', 'winner-order', 1));
        assert.strictEqual(first.snapshot().stock['ingredient-4'], 2);
        const tableWinner = command('order.hold', 'table-winner', 0,
            { order_snapshot: snapshotFor('table-winner', '70') });
        first.execute(tableWinner);
        const tableOnly = heldSnapshot('table-loser', '2026-09-04', [{
            line_id: 'table-loser-line', product_id: '50', quantity: 1, unit_price_cents: 500,
            tax_snapshot: {}, recipe_snapshot: [], deal_snapshot: [], direct_consumption_snapshot: [],
        }], { table_id: '70' });
        expect('already_claimed', () => second.execute(command('order.hold', 'table-loser', 0,
            { order_snapshot: tableOnly })));
        assert.strictEqual(second.snapshot().stock['ingredient-4'], 0,
            'failed table contender must not consume additional stock');
        assert.strictEqual(first.execute(tableWinner).duplicate, true);
        assert.strictEqual(first.snapshot().stock['ingredient-4'], 0);
        expect('invalid_command_type', () => first.execute(Object.assign(
            command('order.hold', 'unsafe-open', 0, {}), { type: 'order.open' })));
        assert.ok(!first.snapshot().orders['unsafe-open']);
    } finally { fs.rmSync(contentionRoot, { recursive: true, force: true }); }

    // Shared universal hold snapshot survives encrypted restart as one order,
    // one table reservation and one durable outbox event; a fresh trusted
    // timestamp on replay must not defeat browser-command idempotency.
    const fixtureHoldRoot = temp();
    try {
        let fixtureEngine = new LocalCoreDomain({ dataDir: fixtureHoldRoot, encryptionKey: KEY,
            authorityScope: scope, authority: ownerAuthority() });
        baseline(fixtureEngine,
            [{ id: '51', revision: 4 }, { id: '77', revision: 5 }, { id: '50', revision: 3 }],
            [{ id: '51', revision: 1 }, { id: 'ingredient-4', revision: 8 }],
            { '51': 5, 'ingredient-4': 5 }, [{ id: '70', revision: 2 }],
            { '50': [{ stock_id: 'ingredient-4', quantity: 1 }] });
        const fixtureCommand = command('order.hold', holdFixture.aggregate_id, holdFixture.revision,
            { order_snapshot: holdFixture.snapshot }, { id: 'order-hold:' + holdFixture.snapshot.idempotency_key });
        assert.strictEqual(fixtureEngine.execute(fixtureCommand).duplicate, false);
        fixtureEngine.close();
        fixtureEngine = new LocalCoreDomain({ dataDir: fixtureHoldRoot, encryptionKey: KEY,
            authorityScope: scope, authority: ownerAuthority() });
        assert.strictEqual(Object.keys(fixtureEngine.snapshot().orders).length, 1);
        assert.strictEqual(fixtureEngine.snapshot().tables['70'].order_id, holdFixture.aggregate_id);
        assert.strictEqual(fixtureEngine.eventStore.pending(99).filter((event) =>
            event.type === 'order.held' && event.payload.aggregate_id === holdFixture.aggregate_id).length, 1);
        const replay = Object.assign({}, fixtureCommand, { at_ms: fixtureCommand.at_ms + 5000 });
        assert.strictEqual(fixtureEngine.execute(replay).duplicate, true);
        assert.strictEqual(Object.keys(fixtureEngine.snapshot().orders).length, 1);
        assert.strictEqual(Object.keys(fixtureEngine.snapshot().tables).length, 1);
    } finally { fs.rmSync(fixtureHoldRoot, { recursive: true, force: true }); }

    ['after_marker', 'after_event_append', 'after_generation', 'before_commit', 'after_state_commit'].forEach((stage) => {
        const root = temp();
        try {
            const base = new LocalCoreDomain({ dataDir: root, encryptionKey: KEY, authorityScope: scope, authority: ownerAuthority() });
            baseline(base, [{ id: 'tea' }], [], {});
            base.execute(command('order.hold', 'settle-order', 0, {
                order_snapshot: heldSnapshot('settle-order', '2026-09-04', [{
                    line_id: 'settle-line', product_id: 'tea', name: 'Tea', quantity: 1, unit_price_cents: 100,
                    tax_snapshot: {}, recipe_snapshot: [], deal_snapshot: [], direct_consumption_snapshot: [],
                }]),
            }));
            const snapshot = { order_id: 'settle-order', business_date: '2026-09-04',
                items: [{ name: 'Tea', quantity: 1, unit_price_cents: 100,
                    tax_snapshot: { rate: 0 }, recipe_snapshot: [] }],
                totals: { total_cents: 100, tax_cents: 0 }, payment: { method: 'cash' } };
            const interrupted = new LocalCoreDomain({ dataDir: root, encryptionKey: KEY,
                authorityScope: scope, authority: ownerAuthority(),
                fault: (at) => { if (at === stage) throw new Error('settle loss ' + stage); } });
            assert.throws(() => interrupted.execute(command('order.settle', 'settle-order', 1,
                { sale_snapshot: snapshot })));
            const recovered = new LocalCoreDomain({ dataDir: root, encryptionKey: KEY,
                authorityScope: scope, authority: ownerAuthority() });
            assert.strictEqual(recovered.snapshot().orders['settle-order'].status, 'settled', stage);
            assert.deepStrictEqual(recovered.snapshot().sales['settle-order'].snapshot, snapshot, stage);
            const sent = recovered.eventStore.pending(99).filter((event) => event.type === 'order.settled');
            assert.strictEqual(sent.length, 1, stage);
            assert.strictEqual(sent[0].payload.command_type, 'order.settle');
            const replay = new EventStore({ dataDir: root, encryptionKey: KEY }).pending(99)
                .find((event) => event.type === 'order.settled');
            assert.deepStrictEqual(replay, sent[0], 'restart must replay the exact signed settlement');
        } finally { fs.rmSync(root, { recursive: true, force: true }); }
    });

    const securityRoot = temp();
    try {
        let allowed = true;
        const authority = { lease_id: 91, token: 'opaque-scope-token',
            signing_secret: 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB',
            next_sequence: 1, prev_hash: '0'.repeat(64), scope, owner: false,
            allowed_actions: ['staff.start', 'stock.set'] };
        const owner = new LocalCoreDomain({ dataDir: securityRoot, encryptionKey: KEY, authorityScope: scope, authority });
        owner.execute(command('staff.start', 'shift-user-1', 0, { user_id: 'user-1', permissions: ['stock.set'] }));
        const cashier = new LocalCoreDomain({ dataDir: securityRoot, encryptionKey: KEY, authorityScope: scope,
            permissionProvider: () => allowed, authority: Object.assign({}, authority, {
                owner: false, staff_session_id: 'shift-user-1',
            }) });
        cashier.execute(command('stock.set', 'secure-stock', 0, { quantity: 1 }));
        expect('permission_denied', () => cashier.execute(command('stock.adjust', 'secure-stock', 1, { delta: 1 })));
        allowed = false;
        expect('permission_revoked', () => cashier.execute(command('stock.set', 'other-stock', 0, { quantity: 1 })));
        const otherScope = Object.assign({}, scope, { user_id: 'user-2' });
        const sharedBranch = new LocalCoreDomain({
            dataDir: securityRoot, encryptionKey: KEY, authorityScope: otherScope,
        });
        assert.strictEqual(sharedBranch.snapshot().scope.branch_id, scope.branch_id,
            'different actors share one company/branch operational state');
        sharedBranch.close();
    } finally { fs.rmSync(securityRoot, { recursive: true, force: true }); }

    const fixtureRoot = temp();
    try {
        const aggregate = settlementFixture.aggregate_id;
        const input = settlementFixture.universal_input;
        const expected = settlementFixture.normalized;
        const normalized = Object.assign({}, input, {
            order_id: aggregate,
            payment: Object.assign({}, input.payment),
            items: [
                Object.assign({}, input.items[0], expected.line, {
                    unit_price: expected.line.unit_price_cents / 100,
                    line_total: expected.line.unit_price_cents * expected.line.quantity / 100,
                }),
                Object.assign({}, input.items[1], expected.deal_line, {
                    unit_price: expected.deal_line.unit_price_cents / 100,
                    line_total: expected.deal_line.unit_price_cents * expected.deal_line.quantity / 100,
                }),
            ],
            totals: Object.assign({}, input.totals, {
                subtotal_cents: expected.subtotal_cents, tax_cents: expected.tax_cents,
                discount_cents: expected.discount_cents, total_cents: expected.total_cents,
            }),
        });
        const domain = new LocalCoreDomain({
            dataDir: fixtureRoot, encryptionKey: KEY, authorityScope: scope, authority: ownerAuthority(),
        });
        baseline(domain, [{ id: '50' }, { id: '77' }], [{ id: 'ingredient-4' }],
            { 'ingredient-4': 10 }, [{ id: '70' }], { 50: [{ stock_id: 'ingredient-4', quantity: 1 }] });
        domain.execute(command('order.hold', aggregate, 0, {
            order_snapshot: heldSnapshot(aggregate, expected.business_date, normalized.items.map((item) =>
                Object.assign({}, item, {
                    line_id: item.line_id, product_id: String(item.product_id), unit_price_cents: item.unit_price_cents,
                    recipe_snapshot: item.recipe_snapshot || [], deal_snapshot: item.deal_snapshot || [],
                    direct_consumption_snapshot: [],
                })), { order_type: normalized.order_type || input.order_type, table_id: String(input.table_id),
                totals: normalized.totals }),
        }));
        assert.strictEqual(domain.snapshot().stock['ingredient-4'], 7,
            'product and deal recipe snapshots reserve stock once');
        domain.execute(command('order.settle', aggregate, 1, { sale_snapshot: normalized }));
        assert.strictEqual(domain.snapshot().stock['ingredient-4'], 7,
            'settlement must not consume held stock again');
        assert.strictEqual(domain.snapshot().orders[aggregate].status, 'settled');
        assert.deepStrictEqual(domain.snapshot().sales[aggregate].snapshot, normalized);
        const restarted = new LocalCoreDomain({
            dataDir: fixtureRoot, encryptionKey: KEY, authorityScope: scope, authority: ownerAuthority(),
        });
        assert.strictEqual(restarted.snapshot().orders[aggregate].status, 'settled');
        assert.deepStrictEqual(restarted.snapshot().sales[aggregate].snapshot, normalized);
        assert.strictEqual(restarted.eventStore.pending(99)
            .filter((event) => event.type === 'order.settled' &&
                event.payload && event.payload.aggregate_id === aggregate).length, 1);
    } finally { fs.rmSync(fixtureRoot, { recursive: true, force: true }); }

    const backupRoot = temp();
    try {
        const sourceDir = path.join(backupRoot, 'source');
        const store = new EventStore({ dataDir: sourceDir, encryptionKey: KEY, minFreeBytes: 0 });
        const domain = new LocalCoreDomain({ dataDir: sourceDir, encryptionKey: KEY, authorityScope: scope, authority: ownerAuthority() });
        domain.execute(command('stock.set', 'stock-backup', 0, { quantity: 8 }));
        assert.strictEqual(domain.nextIdentity('bill'), 1);
        const backup = createBackup({ store, encryptionKey: KEY, partition: 'domain-p',
            backupDir: path.join(backupRoot, 'backups'), minFreeBytes: 0 });
        const restoredDir = path.join(backupRoot, 'restored');
        restoreBackup({ backupPath: backup.path, targetDir: restoredDir, encryptionKey: KEY, partition: 'domain-p' });
        const restored = new LocalCoreDomain({ dataDir: restoredDir, encryptionKey: KEY, authorityScope: scope, authority: ownerAuthority() });
        assert.strictEqual(restored.snapshot().stock['stock-backup'], 8);
        assert.strictEqual(restored.nextIdentity('bill'), 2, 'restore preserves monotonic identities');
    } finally { fs.rmSync(backupRoot, { recursive: true, force: true }); }

    console.log('local-core-domain tests passed');
}());