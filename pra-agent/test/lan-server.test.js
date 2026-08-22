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
    const server = createLanServer({ dataDir: dataDir, port: 0, version: '9.9.9', log: function () {} });
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
            headers: { Origin: 'https://taxnest.com.pk' },
        });
        assert.strictEqual(ours.headers.get('access-control-allow-origin'), 'https://taxnest.com.pk');

        // A random site the shop PC happens to visit gets no CORS header, so
        // the browser refuses to hand it the shop's caller list.
        const theirs = await fetch(base + '/lan/caller/events', {
            headers: { Origin: 'https://evil.example.com' },
        });
        assert.strictEqual(theirs.headers.get('access-control-allow-origin'), null);
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

    await it('pending rings survive for the cloud sync, and clear once pushed', function () {
        const pending = server.pendingEvents();
        assert.strictEqual(pending.length, 1);
        server.markSynced([pending[0].id]);
        assert.strictEqual(server.pendingEvents().length, 0);
        assert.strictEqual(server.status().pending_events, 0);
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
