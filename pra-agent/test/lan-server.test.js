'use strict';
/*
 * LAN server smoke test — plain node, no Electron, no test framework.
 *   node test/lan-server.test.js
 *
 * The agent's GUI cannot boot in CI, so this is the only place the LAN lane
 * gets proven before a Windows build. Keep it dependency-free.
 */

const assert = require('assert');
const fs = require('fs');
const os = require('os');
const path = require('path');

const { createLanServer, isPrivateAddress } = require('../src/lan-server');
const { EventStore } = require('../src/local-core/event-store');
const { capabilities: coreCapabilities } = require('../src/local-core/protocol');

let passed = 0;
async function it(name, fn) {
    try {
        await fn();
        passed++;
        console.log('  ok  ' + name);
    } catch (e) {
        console.error('  FAIL  ' + name + '\n        ' + (e && e.message));
        process.exitCode = 1;
    }
}

function j(res) { return res.json(); }

(async function run() {
    console.log('LAN server tests');

    await it('isPrivateAddress accepts LAN, refuses the internet', function () {
        ['127.0.0.1', '::1', '::ffff:192.168.1.20', '10.0.0.5', '172.16.4.9', '192.168.100.2']
            .forEach(function (a) { assert.strictEqual(isPrivateAddress(a), true, a + ' should be private'); });
        ['8.8.8.8', '39.32.100.7', '172.32.0.1', '203.0.113.5', '', null]
            .forEach(function (a) { assert.strictEqual(isPrivateAddress(a), false, String(a) + ' should NOT be private'); });
    });

    const dataDir = fs.mkdtempSync(path.join(os.tmpdir(), 'lan-test-'));
    const coreStore = new EventStore({ dataDir: path.join(dataDir, 'core') });
    const server = createLanServer({
        dataDir: dataDir, port: 0, version: '9.9.9', log: function () {},
        coreProvider: function () {
            return { append: function (ev) { return coreStore.append(ev); }, status: function () { return coreStore.status(); },
                capabilities: function () { return coreCapabilities(); } };
        },
    });
    const st = await server.start();
    const base = 'http://127.0.0.1:' + st.port;

    await it('starts and reports its own LAN URLs', function () {
        assert.strictEqual(st.running, true);
        assert.ok(st.port > 0, 'port assigned');
        assert.ok(Array.isArray(st.urls));
        assert.match(String(st.pair_code), /^\d{6}$/);
    });

    await it('health needs no pairing and leaks no shop data', async function () {
        const res = await fetch(base + '/lan/health');
        assert.strictEqual(res.status, 200);
        const body = await j(res);
        assert.strictEqual(body.app, 'nestpos-lan');
        assert.strictEqual(body.version, '9.9.9');
        assert.ok(!('devices' in body) && !('pair_code' in body), 'health must stay anonymous');
    });

    await it('writing needs a paired device, reading from this PC does not', async function () {
        // A phone that never paired cannot push rings…
        const ring = await fetch(base + '/lan/caller/ring', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ number: '03001234567' }),
        });
        assert.strictEqual(ring.status, 401);
        assert.strictEqual((await fetch(base + '/lan/whoami')).status, 401);

        // …but the POS page on the shop's OWN PC reads rings without pairing:
        // whoever is at this machine is already inside the POS.
        const res = await fetch(base + '/lan/caller/events');
        assert.strictEqual(res.status, 200);
    });

    await it('only our own pages may read this server from a browser', async function () {
        const ours = await fetch(base + '/lan/caller/events', {
            headers: { Origin: 'https://taxnest.pk' },
        });
        assert.strictEqual(ours.headers.get('access-control-allow-origin'), 'https://taxnest.pk');

        // Anything else gets no CORS header, so the browser refuses to hand it
        // the shop's caller list: a random site, a laptop-hosted page on the
        // same shop WiFi, and a lookalike domain all stay locked out.
        const hostile = [
            'https://evil.example.com',
            'http://192.168.1.50:3000',
            'https://taxnest.pk.evil.com',
            'https://someone.replit.dev',
        ];
        for (const origin of hostile) {
            const res = await fetch(base + '/lan/caller/events', { headers: { Origin: origin } });
            assert.strictEqual(
                res.headers.get('access-control-allow-origin'), null,
                'must not hand data to ' + origin
            );
        }
    });

    // Own instance: the brute-force lock is per IP, and over loopback the
    // "attacker" and the shop's real tablet would otherwise share one address.
    await it('a wrong pair code is refused, then rate-limited', async function () {
        const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'lan-brute-'));
        const victim = createLanServer({ dataDir: dir, port: 0, version: '9.9.9', log: function () {} });
        const s = await victim.start();
        const url = 'http://127.0.0.1:' + s.port + '/lan/pair';
        const attempt = function (code) {
            return fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ code: code, device: 'thief' }),
            });
        };
        for (let i = 0; i < 10; i++) {
            const res = await attempt('000000');
            assert.strictEqual(res.status, 403, 'attempt ' + (i + 1) + ' must be refused');
        }
        assert.strictEqual((await attempt('000000')).status, 429, 'brute force must hit the wall');
        // Even the RIGHT code stays locked out while the window is open.
        assert.strictEqual((await attempt(victim.status().pair_code)).status, 429);
        await victim.stop();
        fs.rmSync(dir, { recursive: true, force: true });
    });

    let token = null;
    await it('the real code pairs a tablet and then rotates', async function () {
        const before = server.status().pair_code;
        const res = await fetch(base + '/lan/pair', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code: before, device: 'Waiter Tablet 1', kind: 'waiter' }),
        });
        assert.strictEqual(res.status, 200);
        const body = await j(res);
        assert.ok(body.token && body.token.length >= 32, 'token issued');
        token = body.token;
        assert.notStrictEqual(server.status().pair_code, before, 'code must rotate after use');
        assert.strictEqual(server.status().devices, 1);

        const who = await fetch(base + '/lan/whoami', { headers: { Authorization: 'Bearer ' + token } });
        assert.strictEqual(who.status, 200);
        assert.strictEqual((await j(who)).name, 'Waiter Tablet 1');
    });

    await it('a paired phone can push a ring, and a retry does not double it', async function () {
        const post = function (payload) {
            return fetch(base + '/lan/caller/ring', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token },
                body: JSON.stringify(payload),
            });
        };
        const first = await j(await post({ number: '0300-1234567', name: 'Ali', uuid: 'ring-1' }));
        assert.strictEqual(first.ok, true);
        const retry = await j(await post({ number: '0300-1234567', name: 'Ali', uuid: 'ring-1' }));
        assert.strictEqual(retry.id, first.id, 'same uuid must reuse the same event');
        assert.strictEqual(retry.duplicate, true);

        const bad = await post({ number: '' });
        assert.strictEqual(bad.status, 422);
    });

    await it('Core routes share LAN pairing and expose no credentials', async function () {
        assert.strictEqual((await fetch(base + '/core/status')).status, 401, 'unpaired core read must be refused');
        const headers = { Authorization: 'Bearer ' + token, 'Content-Type': 'application/json' };
        const caps = await j(await fetch(base + '/core/capabilities', { headers: headers }));
        assert.strictEqual(caps.ok, true);
        assert.ok(caps.capabilities.protocol_versions.indexOf(1) !== -1);
        assert.ok(JSON.stringify(caps).indexOf(token) === -1, 'credentials must never be returned');
        const ev = { v: 1, id: 'lan-core-0001', type: 'sale.created', at_ms: Date.now(), payload: { sale_id: 1 } };
        const accepted = await fetch(base + '/core/events', { method: 'POST', headers: headers, body: JSON.stringify(ev) });
        assert.strictEqual(accepted.status, 202);
        const duplicate = await fetch(base + '/core/events', { method: 'POST', headers: headers, body: JSON.stringify(ev) });
        assert.strictEqual((await j(duplicate)).duplicate, true);
        const status = await j(await fetch(base + '/core/status', { headers: headers }));
        assert.strictEqual(status.status.pending_count, 1);
    });

    await it('the POS can poll rings with a cursor', async function () {
        const res = await fetch(base + '/lan/caller/events?after=0&t=' + token);
        assert.strictEqual(res.status, 200);
        const body = await j(res);
        assert.strictEqual(body.events.length, 1);
        assert.strictEqual(body.events[0].number, '03001234567', 'number normalised');
        assert.strictEqual(body.events[0].source, 'sim');

        const after = await j(await fetch(base + '/lan/caller/events?after=' + body.last_id + '&t=' + token));
        assert.strictEqual(after.events.length, 0, 'cursor must not replay');
    });

    /* ---- the browser door (Task 1533) ----------------------------------- */

    const HTML = { Accept: 'text/html,application/xhtml+xml,*/*;q=0.8' };

    await it('the advertised address opens a page, never raw JSON', async function () {
        const res = await fetch(base + '/', { headers: HTML });
        assert.strictEqual(res.status, 200);
        assert.match(String(res.headers.get('content-type')), /text\/html/);
        const html = await res.text();
        assert.match(html, /NestPOS/);
        assert.match(html, /Pairing code/i, 'the page must ask for the code');
        assert.match(html, /id="pairForm"/);
        // Rule: nothing about the shop before pairing.
        assert.ok(html.indexOf(server.status().pair_code) === -1, 'the page must never contain the code itself');
        assert.ok(!/juday huay devices/i.test(html), 'no device count before pairing');
    });

    await it('a mistyped path still lands on the page, and a bare probe still gets JSON', async function () {
        const page = await fetch(base + '/waiter', { headers: HTML });
        assert.strictEqual(page.status, 200);
        assert.match(String(page.headers.get('content-type')), /text\/html/);

        // No Accept: text/html = not a browser navigation = the old JSON
        // contract, byte for byte (an unpaired caller still hits the token
        // gate before it can reach the 404).
        const probe = await fetch(base + '/waiter', { headers: { Accept: 'application/json' } });
        assert.strictEqual(probe.status, 401);
        assert.strictEqual((await j(probe)).error, 'pair_required');

        const known = await fetch(base + '/nope?t=' + token, { headers: { Accept: 'application/json' } });
        assert.strictEqual(known.status, 404, 'a paired caller still gets the plain 404');
        assert.strictEqual((await j(known)).error, 'not_found');
    });

    await it('API paths keep their exact JSON even from a browser', async function () {
        const health = await fetch(base + '/lan/health', { headers: HTML });
        assert.match(String(health.headers.get('content-type')), /application\/json/);
        const body = await j(health);
        assert.strictEqual(body.app, 'nestpos-lan');
        assert.ok(!('devices' in body) && !('pair_code' in body), 'health must stay anonymous');

        const who = await fetch(base + '/lan/whoami', { headers: HTML });
        assert.strictEqual(who.status, 401);
        assert.strictEqual((await j(who)).error, 'pair_required');
    });

    await it('LAN Mode switched off reads as a sentence, not a blob', async function () {
        const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'lan-off-'));
        let on = false;
        const off = createLanServer({
            dataDir: dir, port: 0, version: '9.9.9', log: function () {},
            isEnabled: function () { return on; },
        });
        const s = await off.start();
        const res = await fetch('http://127.0.0.1:' + s.port + '/', { headers: HTML });
        assert.strictEqual(res.status, 503);
        const html = await res.text();
        assert.match(html, /LAN Mode band hai/);
        assert.ok(html.indexOf('{') === -1 || !/"ok"\s*:/.test(html), 'no JSON blob on the page');
        // The switch is read fresh, not cached at construction.
        on = true;
        assert.strictEqual((await fetch('http://127.0.0.1:' + s.port + '/', { headers: HTML })).status, 200);
        await off.stop();
        fs.rmSync(dir, { recursive: true, force: true });
    });

    await it('pairing from the page works end to end, and a wrong code is counted', async function () {
        const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'lan-page-'));
        const srv = createLanServer({ dataDir: dir, port: 0, version: '9.9.9', log: function () {} });
        const s = await srv.start();
        const at = 'http://127.0.0.1:' + s.port;
        const post = function (payload) {
            return fetch(at + '/lan/pair', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
        };

        // What the page does when the shopkeeper fat-fingers the code.
        const wrong = await post({ code: '000001', device: 'Tablet', kind: 'waiter' });
        assert.strictEqual(wrong.status, 403);
        assert.strictEqual((await j(wrong)).error, 'bad_code');

        const code = srv.status().pair_code;
        const good = await post({ code: code, device: 'Waiter Tablet 2', kind: 'waiter' });
        assert.strictEqual(good.status, 200);
        const tok = (await j(good)).token;
        assert.ok(tok, 'token issued to the page');
        assert.strictEqual(srv.status().devices, 1, 'agent window count goes up');
        assert.notStrictEqual(srv.status().pair_code, code, 'a fresh code waits for the next device');

        // Re-opening the address on THIS device: the page asks whoami first and
        // goes straight to status.
        const who = await fetch(at + '/lan/whoami', { headers: { 'X-Lan-Token': tok } });
        assert.strictEqual(who.status, 200);
        assert.strictEqual((await j(who)).name, 'Waiter Tablet 2');
        // …and the status page can read this PC's recent calls with its token.
        assert.strictEqual((await fetch(at + '/lan/caller/events?after=0', {
            headers: { 'X-Lan-Token': tok },
        })).status, 200);

        // The device drops ITSELF from the status page.
        const bye = await fetch(at + '/lan/unpair', { method: 'POST', headers: { 'X-Lan-Token': tok } });
        assert.strictEqual(bye.status, 200);
        assert.strictEqual(srv.status().devices, 0);
        assert.strictEqual((await fetch(at + '/lan/whoami', { headers: { 'X-Lan-Token': tok } })).status, 401);
        // An unpair with no token can never remove somebody else's device.
        assert.strictEqual((await fetch(at + '/lan/unpair', { method: 'POST' })).status, 401);

        await srv.stop();
        fs.rmSync(dir, { recursive: true, force: true });
    });

    await it('the page itself is same-origin only — no CORS, no outside assets', async function () {
        const res = await fetch(base + '/', { headers: { Accept: 'text/html', Origin: 'https://taxnest.pk' } });
        assert.strictEqual(res.headers.get('access-control-allow-origin'), null,
            'the page must not be readable from another origin');
        const html = await res.text();
        assert.ok(!/src="https?:\/\//.test(html) && !/href="https?:\/\//.test(html),
            'the page must not load anything from outside this server');
    });

    await it('pending rings survive for the cloud sync, and clear once pushed', function () {
        const pending = server.pendingEvents();
        assert.strictEqual(pending.length, 1);
        server.markSynced([pending[0].id]);
        assert.strictEqual(server.pendingEvents().length, 0);
        assert.strictEqual(server.status().pending_events, 0);
    });

    await it('the device list names every tablet, and never leaks a token', async function () {
        const list = server.listDevices();
        assert.ok(list.length >= 1, 'the paired tablet must appear in the list');
        const row = list[0];
        ['id', 'name', 'kind', 'paired_at', 'last_seen'].forEach(function (k) {
            assert.ok(k in row, 'a row must carry ' + k);
        });
        const blob = JSON.stringify(list);
        assert.ok(blob.indexOf(token) === -1, 'the pairing token must never reach the renderer');
        assert.notStrictEqual(row.id, token, 'the row id must not be the token itself');
        assert.strictEqual(row.id, server.listDevices()[0].id, 'the id must be stable between reads');
    });

    await it('removing one device leaves the others paired', async function () {
        // Pair a second device with whatever code is current now.
        const second = await fetch(base + '/lan/pair', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code: server.status().pair_code, device: 'Waiter Tablet 2', kind: 'waiter' }),
        });
        assert.strictEqual(second.status, 200, 'the second tablet must pair');
        const secondToken = (await j(second)).token;
        assert.strictEqual(server.listDevices().length, 2);

        const victim = server.listDevices().find(function (d) { return d.name === 'Waiter Tablet 2'; });
        assert.strictEqual(server.removeDevice(victim.id), true, 'removing a real device must report success');
        assert.strictEqual(server.listDevices().length, 1, 'only the removed device may disappear');

        const dead = await fetch(base + '/lan/whoami', { headers: { Authorization: 'Bearer ' + secondToken } });
        assert.strictEqual(dead.status, 401, 'the removed device must have to pair again');
        const alive = await fetch(base + '/lan/whoami', { headers: { Authorization: 'Bearer ' + token } });
        assert.strictEqual(alive.status, 200, 'the OTHER tablet must stay paired');
    });

    await it('an unknown device id removes nothing', function () {
        const before = server.listDevices().length;
        [null, '', 'not-a-real-id', '0'].forEach(function (bad) {
            assert.strictEqual(server.removeDevice(bad), false, String(bad) + ' must not report a removal');
        });
        assert.strictEqual(server.listDevices().length, before, 'a bad id must not touch the store');
    });

    await it('the device list is never readable over the LAN', async function () {
        for (const p of ['/lan/devices', '/lan/device-list']) {
            const anon = await fetch(base + p);
            assert.strictEqual(anon.status, 401, p + ' must not answer an unpaired caller');
            const paired = await fetch(base + p, { headers: { Authorization: 'Bearer ' + token } });
            assert.strictEqual(paired.status, 404, p + ' must not exist even for a paired device');
        }
    });

    await it('pairing survives an agent restart', async function () {
        await server.stop();
        const again = createLanServer({ dataDir: dataDir, port: 0, version: '9.9.9', log: function () {} });
        const st2 = await again.start();
        const who = await fetch('http://127.0.0.1:' + st2.port + '/lan/whoami', {
            headers: { Authorization: 'Bearer ' + token },
        });
        assert.strictEqual(who.status, 200, 'a paired tablet must not re-pair after a PC restart');
        again.forgetDevices();
        const gone = await fetch('http://127.0.0.1:' + st2.port + '/lan/whoami', {
            headers: { Authorization: 'Bearer ' + token },
        });
        assert.strictEqual(gone.status, 401, 'forgetDevices must actually unpair');
        await again.stop();
    });

    fs.rmSync(dataDir, { recursive: true, force: true });
    console.log(passed + ' passed' + (process.exitCode ? ' — WITH FAILURES' : ''));
    // Explicit: a half-closed keep-alive socket must not hang CI.
    process.exit(process.exitCode || 0);
})();
