'use strict';
/*
 * Offline snapshot staleness tests — plain node, no Electron, no framework.
 *   node test/offline-snapshot.test.js
 *
 * The agent's GUI cannot boot here, so this is the only place the snapshot
 * ageing rules get proven before a Windows build. offline-snapshot.js pulls in
 * `electron` at require time, so we stub the module loader first — nothing in
 * these tests touches app/net, they only exercise pure age + banner logic.
 *
 * What is locked here (the incident these rules exist for):
 *   A snapshot used to have NO expiry and NO warning. A shop offline for days
 *   kept billing off a days-old catalogue with nothing but a timestamp on
 *   screen. The tiers below are the escalation that replaces that silence.
 *   Serving must still NEVER be refused — a stale screen can still take money,
 *   and blocking would turn a rate problem into a shutter-down problem.
 */

const assert = require('assert');
const os = require('os');
const Module = require('module');

const TMP = os.tmpdir();
const origLoad = Module._load;
Module._load = function (request) {
    if (request === 'electron') {
        return { app: { getPath: () => TMP }, net: {} };
    }
    return origLoad.apply(this, arguments);
};

const snap = require('../src/offline-snapshot');

let passed = 0;
const queue = [];
// Async tests must be awaited, not fired and forgotten — an assertion that
// throws inside a floating promise kills the run instead of failing the case.
function it(name, fn) {
    queue.push(async () => {
        try {
            await fn();
            passed++;
            console.log('  ok  ' + name);
        } catch (e) {
            console.error('  FAIL  ' + name + '\n        ' + (e && e.message));
            process.exitCode = 1;
        }
    });
}

const hoursAgo = (h) => new Date(Date.now() - h * 3600000).toISOString();

console.log('offline-snapshot staleness');

// ── age maths ──────────────────────────────────────────────────────────────
it('unparseable savedAt yields null age, not NaN', () => {
    assert.strictEqual(snap.snapshotAgeHours('not-a-date'), null);
    assert.strictEqual(snap.snapshotAgeHours(''), null);
    assert.strictEqual(snap.snapshotAgeHours(undefined), null);
});

it('a future savedAt clamps to 0, never negative', () => {
    // A shop PC with a wrong clock must not report "-9 hours old".
    const future = new Date(Date.now() + 5 * 3600000).toISOString();
    assert.strictEqual(snap.snapshotAgeHours(future), 0);
    assert.strictEqual(snap.snapshotTier(future), 'fresh');
});

it('age is measured in hours from savedAt', () => {
    const h = snap.snapshotAgeHours(hoursAgo(5));
    assert.ok(h > 4.9 && h < 5.1, 'expected ~5, got ' + h);
});

// ── tiers ──────────────────────────────────────────────────────────────────
it('under 24h is fresh', () => {
    assert.strictEqual(snap.snapshotTier(hoursAgo(0.5)), 'fresh');
    assert.strictEqual(snap.snapshotTier(hoursAgo(23.5)), 'fresh');
});

it('24h to 72h is stale', () => {
    assert.strictEqual(snap.snapshotTier(hoursAgo(24.5)), 'stale');
    assert.strictEqual(snap.snapshotTier(hoursAgo(71)), 'stale');
});

it('past 72h is old', () => {
    assert.strictEqual(snap.snapshotTier(hoursAgo(73)), 'old');
    assert.strictEqual(snap.snapshotTier(hoursAgo(24 * 30)), 'old');
});

it('tier boundaries match the exported constants', () => {
    assert.strictEqual(snap.SNAPSHOT_STALE_HOURS, 24);
    assert.strictEqual(snap.SNAPSHOT_OLD_HOURS, 72);
    assert.strictEqual(snap.snapshotTier(hoursAgo(snap.SNAPSHOT_STALE_HOURS + 0.1)), 'stale');
    assert.strictEqual(snap.snapshotTier(hoursAgo(snap.SNAPSHOT_OLD_HOURS + 0.1)), 'old');
});

it('unknown tier for an unreadable timestamp', () => {
    assert.strictEqual(snap.snapshotTier('rubbish'), 'unknown');
});

// ── human age text ─────────────────────────────────────────────────────────
it('age text uses minute / ghante / din', () => {
    assert.ok(/minute$/.test(snap.snapshotAgeText(hoursAgo(0.2))), snap.snapshotAgeText(hoursAgo(0.2)));
    assert.ok(/ghante$/.test(snap.snapshotAgeText(hoursAgo(6))), snap.snapshotAgeText(hoursAgo(6)));
    assert.ok(/din$/.test(snap.snapshotAgeText(hoursAgo(50))), snap.snapshotAgeText(hoursAgo(50)));
    assert.strictEqual(snap.snapshotAgeText(hoursAgo(72)), '3 din');
});

it('sub-minute age never reads as "0 minute"', () => {
    assert.strictEqual(snap.snapshotAgeText(new Date().toISOString()), '1 minute');
});

// ── banner escalation ──────────────────────────────────────────────────────
const PAGE = '<html><body><div id="app">sale screen</div></body></html>';

it('banner is injected before </body>, page markup preserved', () => {
    const out = snap.injectOfflineBanner(PAGE, hoursAgo(1));
    assert.ok(out.includes('id="app"'), 'original markup lost');
    assert.ok(out.indexOf('tn-offline-banner') < out.lastIndexOf('</body>'), 'banner not inside body');
});

it('a page with no </body> still gets the banner appended', () => {
    const out = snap.injectOfflineBanner('<div>bare</div>', hoursAgo(1));
    assert.ok(out.includes('tn-offline-banner'));
});

it('fresh snapshot: amber, and it does NOT cry stale', () => {
    const out = snap.injectOfflineBanner(PAGE, hoursAgo(2));
    assert.ok(out.includes('data-tn-tier="fresh"'));
    assert.ok(out.includes('#B45309'), 'expected amber');
    assert.ok(!out.includes('purani hai'), 'fresh snapshot must not warn about age');
});

it('stale snapshot: stronger colour and an explicit rate warning', () => {
    const out = snap.injectOfflineBanner(PAGE, hoursAgo(30));
    assert.ok(out.includes('data-tn-tier="stale"'));
    assert.ok(out.includes('#C2410C'), 'expected escalated colour');
    assert.ok(out.includes('purani hai'), 'stale snapshot must state its age');
    assert.ok(/rate/i.test(out), 'stale snapshot must mention rates');
});

it('old snapshot: red and says the rates are not in it', () => {
    const out = snap.injectOfflineBanner(PAGE, hoursAgo(96));
    assert.ok(out.includes('data-tn-tier="old"'));
    assert.ok(out.includes('#B91C1C'), 'expected red');
    assert.ok(out.includes('4 din purani'), 'old snapshot must state days');
    assert.ok(/naye product/i.test(out), 'old snapshot must warn the catalogue is behind');
});

it('every tier still tells the shop bills are safe and offers a retry', () => {
    for (const age of [1, 30, 96]) {
        const out = snap.injectOfflineBanner(PAGE, hoursAgo(age));
        assert.ok(/mehfooz/i.test(out), age + 'h: missing the "bills are safe" promise');
        assert.ok(out.includes('Dobara Try'), age + 'h: missing the retry button');
    }
});

it('tier and age ride on window.__tnOfflineSnapshot for the page to read', () => {
    const out = snap.injectOfflineBanner(PAGE, hoursAgo(30));
    assert.ok(out.includes('window.__tnOfflineSnapshot'));
    assert.ok(out.includes('"stale"'), 'tier not exposed to the page');
    assert.ok(/ageHours:/.test(out), 'ageHours not exposed to the page');
});

// ── the invariant that must survive all of the above ───────────────────────
it('serving is never refused because a snapshot is old', () => {
    // A stale screen can still take money. Blocking would turn a rate problem
    // into a shutter-down problem, so nothing in the ageing code may reject.
    // Scan serveOffline's own body only — the exports block legitimately names
    // the constants, and slicing to end-of-file would always false-positive.
    const src = require('fs').readFileSync(require.resolve('../src/offline-snapshot'), 'utf8');
    const from = src.indexOf('async function serveOffline');
    assert.ok(from > -1, 'serveOffline not found');
    const to = src.indexOf('// ─── Interception', from);
    assert.ok(to > from, 'could not bound serveOffline');
    const serveBody = src.slice(from, to);
    assert.ok(!/SNAPSHOT_(STALE|OLD)_HOURS/.test(serveBody),
        'serveOffline must not gate on snapshot age');
    assert.ok(!/snapshotTier|snapshotAgeHours/.test(serveBody.replace(/injectOfflineBanner\([^)]*\)/g, '')),
        'serveOffline must not branch on snapshot age');
});

it('non-GET requests are never answered from the snapshot', async () => {
    const res = await snap.serveOffline({ method: 'POST', url: 'https://x.test/pos/invoice/create' }, 'https://x.test');
    assert.strictEqual(res, null);
});

(async () => {
    for (const run of queue) await run();
    console.log('\n' + passed + ' passed');
    if (process.exitCode) console.log('SOME TESTS FAILED');
})();
