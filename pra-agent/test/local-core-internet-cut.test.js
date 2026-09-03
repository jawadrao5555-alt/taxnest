'use strict';

/*
 * Electron release-gate harness. It launches the shipped main.js, the real
 * pos-preload bridge and a real BrowserWindow against this local origin.
 * LOCAL_CORE_RELEASE_GATE=1 makes an Electron/X server startup failure fatal.
 */
const assert = require('assert');
const childProcess = require('child_process');
const fs = require('fs');
const http = require('http');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const { SafeStorageKeyProvider, loadOrCreateCoreKey } = require('../src/local-core/key-store');
const { canonicalJson } = require('../src/local-core/snapshot-sync');
const { laravelJson } = require('../src/local-core/lease-chain');
const holdFixture = require('../../tests/Fixtures/local-core-held-order.json');

const token = 'electron-harness-token';
const scope = { company_id: '41', branch_id: '7', device_id: 'harness-device-01', user_id: '19' };
const lease = {
  lease_id: 7001,
  token: 'L'.repeat(80),
  expires_at: '2099-01-01T00:00:00.000Z',
  permission_version: crypto.createHash('sha256').update('harness-owner-permissions-v1').digest('hex'),
  allowed_actions: [
    'order.hold', 'order.claim', 'order.cancel', 'order.settle',
    'table.claim', 'table.release', 'stock.set', 'stock.adjust', 'customer.upsert', 'khata.debit',
    'wasooli.record', 'refund.record', 'cash.open', 'cash.expense', 'cash.close', 'staff.start',
    'staff.end', 'print.enqueue', 'print.claim', 'print.complete', 'print.fail',
  ],
  scope,
  chain: {
    algorithm: 'HMAC-SHA256',
    signing_secret: Buffer.alloc(32, 0x41).toString('base64url'),
    next_sequence: 1,
    prev_hash: '0'.repeat(64),
  },
};
const root = fs.mkdtempSync(path.join(os.tmpdir(), 'pra-electron-core-'));
let app;

function eventually(check, label, timeout) {
  const end = Date.now() + (timeout || 15000);
  return new Promise((resolve, reject) => {
    const tick = () => {
      try { const value = check(); if (value) return resolve(value); } catch (e) { return reject(e); }
      if (Date.now() >= end) return reject(new Error('timed out: ' + label));
      setTimeout(tick, 100);
    }; tick();
  });
}
async function stopElectron(instance) {
  if (!instance || instance.child.exitCode !== null || instance.child.signalCode !== null) return;
  const exited = new Promise(resolve => instance.child.once('exit', resolve));
  instance.child.kill('SIGTERM');
  const graceful = await Promise.race([exited.then(() => true), new Promise(resolve => setTimeout(() => resolve(false), 5000))]);
  if (!graceful && instance.child.exitCode === null && instance.child.signalCode === null) instance.child.kill('SIGKILL');
  if (!graceful) await exited;
}
function wireSale() {
  return {
    scope, offline_uuid: 'electron-cut-sale-0001', occurred_at_ms: 1700000000000,
    payment_method: 'cash', provisional: false, pra_reporting_enabled: true,
    inventory_enabled: false, order_type: null, customer_credit: false,
    items: [{ name: 'Electron Chai', quantity: 2, unit_price: 100, line_total: 200 }],
    totals: { subtotal: 200, discount_amount: 0, tax_amount: 32, total_amount: 232 },
    discount_type: 'percentage', discount_value: 0, cash_received: 250,
  };
}
function filesUnder(dir) {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap(entry => {
    const target = path.join(dir, entry.name);
    return entry.isDirectory() ? filesUnder(target) : [target];
  });
}
function cloud() {
  const state = { heartbeat: 0, scoped: 0, action: 'idle', reports: [], cut: false,
    loseResponse: false, syncRequests: 0, snapshotRequests: 0, attempts: 0,
    projected: new Map(), chainHead: lease.chain.prev_hash, chainSequence: 0 };
  function verifyEvent(event) {
    assert.deepStrictEqual(event.scope, scope, 'event scope must match the issued lease');
    assert.strictEqual(event.scope_lease_id == null || Number(event.scope_lease_id) === lease.lease_id, true,
      'scope lease id must match');
    if (!String(event.payload && event.payload.schema || '').startsWith('local-core.')) {
      assert.strictEqual(event.payload.schema, 'pra.manual-immediate.v1',
        'only the immediate-sale schema may use the non-domain event path');
      return;
    }
    const chain = event.lease_chain;
    assert.ok(chain, 'Local Core domain event requires a signed lease chain');
    assert.strictEqual(Number(chain.lease_id), lease.lease_id);
    const unsigned = {
      event_id: event.event_id, event_type: event.event_type, occurred_at: event.occurred_at,
      idempotency_key: event.idempotency_key, scope: event.scope, payload: event.payload,
      lease_id: lease.lease_id, sequence: Number(chain.sequence), prev_hash: chain.prev_hash,
    };
    const canonical = laravelJson(unsigned);
    const signature = crypto.createHmac('sha256', Buffer.from(lease.chain.signing_secret))
      .update(canonical).digest('hex');
    assert.strictEqual(chain.signature, signature, 'lease-chain HMAC must verify');
    const nextHash = crypto.createHash('sha256').update(canonical + ':' + signature).digest('hex');
    const duplicate = state.projected.get(event.event_id);
    if (!duplicate) {
      assert.strictEqual(Number(chain.sequence), state.chainSequence + 1, 'lease chain sequence gap');
      assert.strictEqual(chain.prev_hash, state.chainHead, 'lease chain previous hash mismatch');
      state.chainSequence = Number(chain.sequence); state.chainHead = nextHash;
    }
  }
  function snapshotEnvelope() {
    const signed = {
      schema: 'local-core.snapshot.v1',
      revision: 1,
      scope,
      payload: {
        catalog: {
          revision: 1,
          products: [{ id: '51', revision: 4 }, { id: '77', revision: 5 }, { id: '50', revision: 3 }],
          ingredients: [{ id: '51', revision: 1 }, { id: 'ingredient-4', revision: 8 }],
          tables: [{ id: '70', revision: 2 }],
          taxes: [],
        },
        stock: { '51': 5, 'ingredient-4': 5 },
        recipes: { '50': [{ stock_id: 'ingredient-4', quantity: 1 }] },
        customers: {}, tables: {}, orders: {},
        cash_days: {}, staff_sessions: {},
        settings: {
          allowed_actions: lease.allowed_actions,
          permission_version: lease.permission_version,
          session_identity: { user_id: scope.user_id, name: 'Harness Owner', role: 'owner' },
        },
      },
    };
    return Object.assign({}, signed, {
      hash_algorithm: 'sha256',
      hash: crypto.createHash('sha256').update(canonicalJson(signed)).digest('hex'),
      generated_at: '2026-09-03T00:00:00.000Z',
      mode: 'full-refresh-merge',
    });
  }
  const server = http.createServer((req, res) => {
    const body = []; req.on('data', c => body.push(c)); req.on('end', () => {
      const json = () => { res.setHeader('content-type', 'application/json'); };
      const payload = body.length ? JSON.parse(Buffer.concat(body).toString()) : {};
      // The POS is sent through the same login redirect/cookie boundary used
      // by Laravel before its authenticated scope endpoint is allowed.
      if (req.url === '/pos/invoice/create' && !/(?:^|;\s*)pra_harness_session=ok(?:;|$)/.test(req.headers.cookie || '')) {
        res.writeHead(302, { location: '/pos/login' }).end(); return;
      }
      if (req.url === '/pos/login') {
        res.writeHead(302, { location: '/pos/invoice/create', 'set-cookie': 'pra_harness_session=ok; Path=/; HttpOnly' }).end(); return;
      }
      if (req.url === '/pos/invoice/create') {
        res.setHeader('content-type', 'text/html');
        // The real production web client calls through the shipped preload and
        // main IPC handler into the encrypted LocalCoreDomain.
        res.end('<!doctype html><script src="/nestpos-local-core.js"></script><script>let submitted=false;async function go(){let c=await fetch("/control").then(x=>x.json());if(c.action==="hold"&&!submitted&&window.NestPosLocal){submitted=true;let result=await window.NestPosLocal.heldOrder.hold(' +
          JSON.stringify(holdFixture.aggregate_id) + ',' + JSON.stringify(holdFixture.snapshot) + ',' +
          JSON.stringify(holdFixture.revision) + ');let replay=await window.NestPosLocal.heldOrder.hold(' +
          JSON.stringify(holdFixture.aggregate_id) + ',' + JSON.stringify(holdFixture.snapshot) + ',' +
          JSON.stringify(holdFixture.revision) + ');let orders=await window.NestPosLocal.query("orders");let tables=await window.NestPosLocal.query("tables");await fetch("/renderer-report",{method:"POST",headers:{"content-type":"application/json"},body:JSON.stringify({result,replay,orders,tables})});}}setInterval(go,100);</script>');
        return;
      }
      if (req.url === '/nestpos-local-core.js') {
        res.setHeader('content-type', 'application/javascript');
        res.end(fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'js', 'nestpos-local-core.js')));
        return;
      }
      if (req.url === '/control') { json(); res.end(JSON.stringify({ action: state.action })); return; }
      if (req.url === '/renderer-report') { state.reports.push(payload); json(); res.end('{}'); return; }
      if (req.url === '/pos/desktop/local-core-scope') {
        if (!/(?:^|;\s*)pra_harness_session=ok(?:;|$)/.test(req.headers.cookie || '')) {
          res.writeHead(401).end(); return;
        }
        assert.strictEqual(req.headers['x-nestpos-device-uid'], scope.device_id,
          'scope lease must be bound to the configured device');
        state.scoped++; json(); res.end(JSON.stringify({ success: true, company_id: scope.company_id,
          branch_id: scope.branch_id, user_id: scope.user_id, lease })); return;
      }
      if (req.headers.authorization !== 'Bearer ' + token) { res.writeHead(401).end(); return; }
      if (req.url === '/heartbeat') {
        const capability = {
          enabled: true, device_registered: true, company_id: scope.company_id, device_uid: scope.device_id,
          scope_lease_id: lease.lease_id, permission_version: lease.permission_version,
          allowed_actions: lease.allowed_actions, snapshot: {
            endpoint: '/v2/snapshot', schema: 'local-core.snapshot.v1', hash_algorithm: 'sha256',
          },
        };
        state.heartbeat++; json(); res.end(JSON.stringify({
          ok: true, company: { id: Number(scope.company_id), name: 'Harness Company' },
          local_core_enabled: true, local_core: capability, capabilities: { local_core: capability },
          healed: 0, repromoted: 0, stuck_transaction_ids: [],
        })); return;
      }
      if (req.url === '/v2/snapshot') {
        assert.deepStrictEqual(payload, {
          device_uid: scope.device_id, branch_id: scope.branch_id,
          lease_id: lease.lease_id, lease_token: lease.token,
        }, 'snapshot request must carry the current scoped lease');
        state.snapshotRequests++; json(); res.end(JSON.stringify(snapshotEnvelope())); return;
      }
      if (req.url === '/v2/events') {
        state.syncRequests++;
        if (state.cut) { req.socket.destroy(); return; }
        assert.strictEqual(payload.device_uid, scope.device_id);
        assert.strictEqual(payload.version, 1);
        const event = payload.events[0]; state.attempts++; verifyEvent(event);
        assert.strictEqual(event.event_type, 'order.held', 'durable outbox event must be canonical order.held');
        if (!state.projected.has(event.event_id)) state.projected.set(event.event_id, {
            event_id: event.event_id, status: 'projected', transaction_id: 8801,
            invoice_number: 'PRA-8801', pra_status: 'reported',
            scope_lease_id: event.scope_lease_id || null,
            lease_sequence: event.lease_chain ? event.lease_chain.sequence : null,
          });
        if (state.loseResponse) { state.loseResponse = false; req.socket.destroy(); return; }
        json(); res.end(JSON.stringify({ acknowledged_ids: [event.event_id], results: [state.projected.get(event.event_id)] })); return;
      }
      res.writeHead(404).end();
    });
  });
  return { server, state };
}
function launch(origin) {
  const electron = path.join(__dirname, '..', 'node_modules', 'electron', 'dist', 'electron');
  const args = [path.join(__dirname, '..'), '--pos', '--user-data-dir=' + root,
    '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage', '--headless'];
  // Xvfb is preferred for BrowserWindow. The headless flags remain present for
  // CI images whose Electron supports native headless startup.
  const command = process.env.XVFB_RUN || 'xvfb-run';
  const hasXvfb = childProcess.spawnSync('sh', ['-c', 'command -v ' + command]).status === 0;
  const shim = path.join(__dirname, 'harness', 'electron-safe-storage-shim.js');
  const harnessKey = crypto.createHash('sha256').update('safe-storage\0' + root).digest('hex');
  const env = Object.assign({}, process.env, {
    NODE_OPTIONS: [process.env.NODE_OPTIONS, '--require=' + shim].filter(Boolean).join(' '),
    PRA_HARNESS_SAFE_STORAGE_PROFILE: root,
    PRA_HARNESS_SAFE_STORAGE_KEY: harnessKey,
  });
  const options = { cwd: path.join(__dirname, '..'), env, stdio: ['ignore', 'pipe', 'pipe'] };
  const child = hasXvfb
    ? childProcess.spawn(command, ['-a', electron].concat(args), options)
    : childProcess.spawn(electron, args, options);
  let output = '';
  const instance = { child, output: () => output, command: hasXvfb ? command + ' -a' : electron + ' (headless fallback)', exited: false };
  child.stdout.on('data', b => { output += b; });
  child.stderr.on('data', b => { output += b; });
  child.once('exit', () => { instance.exited = true; });
  return instance;
}

(async () => {
  const mock = cloud();
  try {
    // This exercises the unmodified production provider separately: without
    // OS secure storage it must refuse to create or replace a Core key.
    const unavailable = new SafeStorageKeyProvider({
      isEncryptionAvailable: () => false,
      encryptString: () => { throw new Error('must not wrap'); },
      decryptString: () => { throw new Error('must not unwrap'); },
    });
    assert.strictEqual(unavailable.available(), false);
    assert.throws(() => loadOrCreateCoreKey({
      dataDir: path.join(root, 'fail-closed-check'), keyProvider: unavailable,
    }), /secure storage is unavailable/);

    await new Promise((resolve, reject) => mock.server.listen(0, '127.0.0.1', e => e ? reject(e) : resolve()));
    const origin = 'http://127.0.0.1:' + mock.server.address().port;
    // electron-store is deliberately seeded as an isolated installation
    // profile; subsequent launches reuse it exactly like a shop installation.
    fs.writeFileSync(path.join(root, 'config.json'), JSON.stringify({
      config: { serverUrl: origin, apiKey: token, companyId: scope.company_id },
      posSettings: { openOnStartup: false, kiosk: false, offlineMode: true },
      deviceUid: scope.device_id,
    }));
    app = launch(origin);
    await eventually(() => {
      if (app.exited) throw new Error('Electron exited before heartbeat');
      return mock.state.heartbeat && mock.state.scoped && mock.state.snapshotRequests;
    }, 'Electron heartbeat, scope, and canonical snapshot', 45000);
    mock.state.cut = true; mock.state.action = 'hold';
    const held = await eventually(() => mock.state.reports[0], 'renderer production client order.hold');
    assert.strictEqual(held.result.success, true, JSON.stringify(held.result));
    assert.strictEqual(held.result.state, 'pending');
    assert.strictEqual(held.replay.success, true, JSON.stringify(held.replay));
    assert.ok(held.orders.data[holdFixture.aggregate_id], 'durable held order missing from projection');
    assert.strictEqual(held.tables.data['70'].order_id, holdFixture.aggregate_id, 'table reservation missing');
    await eventually(() => mock.state.syncRequests === 1, 'cut cloud submit');
    await stopElectron(app); app = null;
    for (const file of filesUnder(path.join(root, 'local-core'))) {
      const bytes = fs.readFileSync(file, 'utf8');
      assert.strictEqual(bytes.includes('Burger Combo'), false, 'plaintext held order on disk');
      assert.strictEqual(bytes.includes(holdFixture.aggregate_id), false, 'plaintext identity on disk');
    }
    mock.state.cut = false; mock.state.action = 'idle'; mock.state.loseResponse = true;
    app = launch(origin);
    await eventually(() => mock.state.attempts >= 1, 'first post-restart cloud attempt');
    await eventually(() => mock.state.attempts >= 2, 'idempotent retry after lost response', 45000);
    await eventually(() => mock.state.snapshotRequests >= 1, 'canonical scoped snapshot sync');
    assert.strictEqual(mock.state.projected.size, 1, 'exactly one cloud projection after relaunch');
    const projected = Array.from(mock.state.projected.values());
    assert.strictEqual(projected.length, 1);
    assert.strictEqual(mock.state.syncRequests, 3, 'one cut attempt, one lost response, one retry');
    console.log('PASS Electron Local Core release-gate harness');
  } catch (error) {
    const detail = app ? app.output().trim() : '';
    console.error('Electron release-gate harness failed:', error.message);
    if (app) console.error('Electron command:', app.command, '\nElectron output:\n' + (detail || '(no output)'));
    // A missing display server is a real blocker, not a passing GUI skip.
    process.exitCode = 1;
  } finally {
    await stopElectron(app);
    if (typeof mock.server.closeAllConnections === 'function') mock.server.closeAllConnections();
    await new Promise(resolve => mock.server.close(resolve));
    fs.rmSync(root, { recursive: true, force: true, maxRetries: 10, retryDelay: 100 });
  }
})();