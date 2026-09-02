'use strict';
/* Plain Node tests: node test/local-core.test.js */
const assert = require('assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { EventStore } = require('../src/local-core/event-store');
const { loadOrCreateCoreKey } = require('../src/local-core/key-store');
const { validateEvent, PROTOCOL_VERSION } = require('../src/local-core/protocol');
const { CloudSyncClient } = require('../src/local-core/cloud-sync');
const { createBackup, restoreBackup, recoverInterruptedRestore } = require('../src/local-core/backup');

const KEY = Buffer.from('0123456789abcdef0123456789abcdef');
function storeOptions(dataDir, extra) {
    return Object.assign({ dataDir, encryptionKey: KEY, minFreeBytes: 0 }, extra || {});
}

let passed = 0;
async function it(name, fn) {
    try { await fn(); passed++; console.log('  ok  ' + name); }
    catch (e) { process.exitCode = 1; console.error('  FAIL  ' + name + ': ' + e.message); }
}
function event(id) {
    return { v: PROTOCOL_VERSION, id: id, type: 'sale.created', at_ms: 1700000000000, payload: { sale_id: 12 } };
}

function fakeSafeStorage() {
    return {
        isEncryptionAvailable: function () { return true; },
        encryptString: function (value) { return Buffer.from('wrapped:' + Buffer.from(value).toString('base64')); },
        decryptString: function (value) {
            const raw = Buffer.from(value).toString();
            if (!raw.startsWith('wrapped:')) throw new Error('not wrapped');
            return Buffer.from(raw.slice(8), 'base64').toString();
        },
    };
}

(async function () {
    console.log('Local Core tests');
    await it('strictly validates the versioned event contract', function () {
        assert.throws(() => validateEvent({}), /version/);
        assert.throws(() => validateEvent({ ...event('event-0001'), v: 2 }), /version/);
        assert.throws(() => validateEvent({ ...event('event-0001'), type: 'anything' }), /type/);
        assert.deepStrictEqual(validateEvent(event('event-0001')), event('event-0001'));
        const id64 = 'e' + 'a'.repeat(63);
        assert.strictEqual(validateEvent(event(id64)).id, id64, '64-character event id must be accepted');
        assert.throws(() => validateEvent(event('e' + 'a'.repeat(64))), (e) => e.code === 'invalid_event_id',
            '65-character event id must be rejected');
    });

    await it('requires an explicit 32-byte encryption key', function () {
        const keyDir = fs.mkdtempSync(path.join(os.tmpdir(), 'local-core-key-'));
        assert.throws(() => new EventStore({ dataDir: keyDir }), /32-byte/);
        assert.throws(() => new EventStore({ dataDir: keyDir, encryptionKey: Buffer.alloc(31) }), /32-byte/);
        fs.rmSync(keyDir, { recursive: true, force: true });
    });

    await it('wraps the journal key and refuses silent replacement', function () {
        const root = fs.mkdtempSync(path.join(os.tmpdir(), 'local-core-key-'));
        const secure = fakeSafeStorage();
        const created = loadOrCreateCoreKey({ dataDir: root, safeStorage: secure });
        assert.strictEqual(created.created, true);
        assert.strictEqual(created.key.length, 32);
        const keyFile = path.join(root, 'core-key.dpapi');
        const disk = fs.readFileSync(keyFile);
        assert.strictEqual(disk.includes(created.key), false, 'usable key bytes must not be stored directly');
        const reopened = loadOrCreateCoreKey({ dataDir: root, safeStorage: secure });
        assert.deepStrictEqual(reopened.key, created.key);
        assert.strictEqual(reopened.keyId, created.keyId);

        fs.writeFileSync(keyFile, 'damaged');
        assert.throws(
            () => loadOrCreateCoreKey({ dataDir: root, safeStorage: secure }),
            /refusing to replace/
        );
        fs.rmSync(root, { recursive: true, force: true });
    });

    await it('does not mint a new key over an existing journal', function () {
        const root = fs.mkdtempSync(path.join(os.tmpdir(), 'local-core-keyless-'));
        const partition = path.join(root, 'a'.repeat(64));
        fs.mkdirSync(partition);
        fs.writeFileSync(path.join(partition, 'events.ndjson'), 'existing');
        assert.throws(
            () => loadOrCreateCoreKey({ dataDir: root, safeStorage: fakeSafeStorage() }),
            /key is missing/
        );
        assert.throws(
            () => loadOrCreateCoreKey({
                dataDir: fs.mkdtempSync(path.join(os.tmpdir(), 'local-core-no-safe-')),
                safeStorage: { isEncryptionAvailable: function () { return false; } },
            }),
            /secure storage is unavailable/
        );
        fs.rmSync(root, { recursive: true, force: true });
    });

    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'local-core-'));
    await it('is durable, append-only, and idempotent across restart', function () {
        const store = new EventStore(storeOptions(dir));
        assert.strictEqual(store.append(event('event-0001')).duplicate, false);
        assert.strictEqual(store.append(event('event-0001')).duplicate, true);
        assert.strictEqual(store.pending().length, 1);
        assert.strictEqual(store.markSent(['event-0001']), 1);
        assert.strictEqual(store.pending().length, 0);
        const restarted = new EventStore(storeOptions(dir));
        assert.strictEqual(restarted.status().event_count, 1);
        assert.strictEqual(restarted.pending().length, 0);
        assert.strictEqual(restarted.append(event('event-0001')).duplicate, true);
        assert.strictEqual(restarted.status().encryption.algorithm, 'aes-256-gcm');
        const bytes = fs.readFileSync(path.join(dir, 'events.ndjson'));
        assert.strictEqual(bytes.subarray(0, 4).toString(), 'PRAE');
        assert.strictEqual(bytes.includes(Buffer.from('sale.created')), false, 'event must not be plaintext at rest');
    });

    await it('leaves authenticated corruption in place and refuses new writes', function () {
        const bad = fs.mkdtempSync(path.join(os.tmpdir(), 'local-core-bad-'));
        const initial = new EventStore(storeOptions(bad));
        initial.append(event('event-0002'));
        const file = path.join(bad, 'events.ndjson');
        const damaged = fs.readFileSync(file);
        damaged[damaged.length - 17] ^= 1;
        fs.writeFileSync(file, damaged);
        const store = new EventStore(storeOptions(bad));
        assert.strictEqual(store.status().read_only, true);
        assert.ok(store.status().corruption);
        assert.throws(() => store.append(event('event-0002')), (e) => e.code === 'store_read_only');
        assert.deepStrictEqual(fs.readFileSync(file), damaged, 'corrupt source must remain untouched');
        assert.strictEqual(fs.readdirSync(bad).some((n) => n.indexOf('.corrupt-') !== -1), false);
        fs.rmSync(bad, { recursive: true, force: true });
    });

    await it('recovers only an incomplete final frame by durable truncation', function () {
        const tornDir = fs.mkdtempSync(path.join(os.tmpdir(), 'local-core-torn-'));
        const first = new EventStore(storeOptions(tornDir));
        first.append(event('event-torn-1'));
        const file = path.join(tornDir, 'events.ndjson');
        const goodSize = fs.statSync(file).size;
        fs.appendFileSync(file, Buffer.from('PRAE\x02'));
        const recovered = new EventStore(storeOptions(tornDir));
        assert.strictEqual(recovered.status().read_only, false);
        assert.strictEqual(recovered.status().journal.recovered_tail_frames, 1);
        assert.strictEqual(fs.statSync(file).size, goodSize);
        assert.strictEqual(recovered.pending().length, 1);
        fs.rmSync(tornDir, { recursive: true, force: true });
    });

    await it('recovers a torn first encrypted-frame magic prefix', function () {
        for (let length = 1; length <= 3; length++) {
            const root = fs.mkdtempSync(path.join(os.tmpdir(), 'local-core-first-torn-'));
            const file = path.join(root, 'events.ndjson');
            fs.writeFileSync(file, Buffer.from('PRAE').subarray(0, length));
            const recovered = new EventStore(storeOptions(root, { allowPlaintextMigration: true }));
            assert.strictEqual(recovered.status().read_only, false, 'prefix length ' + length);
            assert.strictEqual(recovered.status().journal.recovered_tail_frames, 1);
            assert.strictEqual(fs.statSync(file).size, 0);
            fs.rmSync(root, { recursive: true, force: true });
        }
    });

    await it('imports valid v1 plaintext only with opt-in and atomically encrypts it', function () {
        const migrationDir = fs.mkdtempSync(path.join(os.tmpdir(), 'local-core-migrate-'));
        const data = { seq: 1, event: event('event-migrate-1') };
        const checksum = require('crypto').createHash('sha256').update(JSON.stringify(data)).digest('hex');
        const file = path.join(migrationDir, 'events.ndjson');
        fs.writeFileSync(file, JSON.stringify({ v: 1, data, checksum }) + '\n');
        const denied = new EventStore(storeOptions(migrationDir));
        assert.strictEqual(denied.status().read_only, true);
        assert.ok(fs.readFileSync(file, 'utf8').startsWith('{'), 'denied migration must not alter source');
        const migrated = new EventStore(storeOptions(migrationDir, { allowPlaintextMigration: true }));
        assert.strictEqual(migrated.status().read_only, false);
        assert.strictEqual(migrated.pending().length, 1);
        assert.strictEqual(fs.readFileSync(file).subarray(0, 4).toString(), 'PRAE');
        assert.strictEqual(migrated.status().journal.plaintext_migrations, 1);
        fs.rmSync(migrationDir, { recursive: true, force: true });
    });

    await it('enforces event, byte, and free-space caps with storage telemetry', function () {
        const capDir = fs.mkdtempSync(path.join(os.tmpdir(), 'local-core-cap-'));
        const capped = new EventStore(storeOptions(capDir, { maxEvents: 1 }));
        capped.append(event('event-cap-01'));
        assert.throws(() => capped.append(event('event-cap-02')), (e) => e.code === 'storage_full');
        assert.strictEqual(capped.status().storage_full, true);
        assert.strictEqual(capped.status().event_count, 1);

        const byteDir = fs.mkdtempSync(path.join(os.tmpdir(), 'local-core-byte-'));
        const byteCapped = new EventStore(storeOptions(byteDir, { maxBytes: 1 }));
        assert.throws(() => byteCapped.append(event('event-byte-1')), (e) => e.code === 'storage_full');

        const freeDir = fs.mkdtempSync(path.join(os.tmpdir(), 'local-core-free-'));
        const freeCapped = new EventStore(storeOptions(freeDir, {
            minFreeBytes: 1000,
            diskFreeBytes: () => 1000,
        }));
        assert.throws(() => freeCapped.append(event('event-free-1')), (e) => e.code === 'storage_full');
        assert.strictEqual(freeCapped.status().storage.free_bytes, 1000);
        fs.rmSync(capDir, { recursive: true, force: true });
        fs.rmSync(byteDir, { recursive: true, force: true });
        fs.rmSync(freeDir, { recursive: true, force: true });
    });

    await it('maps compaction disk exhaustion to a retriable storage-full error', function () {
        const root = fs.mkdtempSync(path.join(os.tmpdir(), 'local-core-compact-full-'));
        const store = new EventStore(storeOptions(root, { maxEvents: 1 }));
        store.append(event('event-cap-full-1'));
        store._compactInternal = function () {
            const error = new Error('disk quota');
            error.code = 'ENOSPC';
            throw error;
        };
        assert.throws(() => store.append(event('event-cap-full-2')), (e) => e.code === 'storage_full');
        assert.strictEqual(store.status().storage_full, true);
        fs.rmSync(root, { recursive: true, force: true });
    });

    await it('compacts only expired sent events and preserves queued idempotency across restart', function () {
        const compactDir = fs.mkdtempSync(path.join(os.tmpdir(), 'local-core-compact-'));
        let clock = 10000;
        const options = storeOptions(compactDir, { now: () => clock, sentRetentionMs: 100 });
        const compacted = new EventStore(options);
        compacted.append(event('event-oldsent'));
        compacted.append(event('event-queued1'));
        compacted.markSent(['event-oldsent']);
        clock += 101;
        assert.strictEqual(compacted.compact(), 1);
        assert.strictEqual(compacted.status().event_count, 1);
        assert.strictEqual(compacted.append(event('event-queued1')).duplicate, true);
        const restarted = new EventStore(options);
        assert.deepStrictEqual(restarted.pending().map((item) => item.id), ['event-queued1']);
        assert.strictEqual(restarted.append(event('event-queued1')).duplicate, true);
        fs.rmSync(compactDir, { recursive: true, force: true });
    });

    await it('turns uncertain append faults read-only and recovers the torn tail on restart', function () {
        const faultDir = fs.mkdtempSync(path.join(os.tmpdir(), 'local-core-fault-'));
        const faulty = new EventStore(storeOptions(faultDir));
        const originalWrite = fs.writeSync;
        let failed = false;
        fs.writeSync = function (fd, buffer, offset, length) {
            if (!failed && Buffer.isBuffer(buffer) && buffer.subarray(0, 4).toString() === 'PRAE') {
                failed = true;
                originalWrite.call(fs, fd, buffer, offset, Math.max(1, Math.floor(length / 2)));
                throw new Error('injected disk fault');
            }
            return originalWrite.apply(fs, arguments);
        };
        try {
            assert.throws(() => faulty.append(event('event-fault-1')), (e) => e.code === 'store_read_only');
        } finally {
            fs.writeSync = originalWrite;
        }
        assert.strictEqual(faulty.status().read_only, true);
        const restarted = new EventStore(storeOptions(faultDir));
        assert.strictEqual(restarted.status().read_only, false);
        assert.strictEqual(restarted.status().event_count, 0);
        assert.strictEqual(restarted.status().journal.recovered_tail_frames, 1);
        fs.rmSync(faultDir, { recursive: true, force: true });
    });

    await it('creates authenticated encrypted same-install backups and restores through staging', function () {
        const root = fs.mkdtempSync(path.join(os.tmpdir(), 'local-core-backup-'));
        const source = path.join(root, 'source');
        const backupDir = path.join(root, 'backups');
        const store = new EventStore(storeOptions(source));
        store.append(event('event-backup-1'));
        const backup = createBackup({ store, encryptionKey: KEY, partition: 'partition-a', backupDir,
            now: () => 1700000000123, automatic: true, maxRetained: 2 });
        const wire = fs.readFileSync(backup.path);
        assert.strictEqual(wire.includes(KEY), false, 'backup must not contain raw encryption key');
        assert.strictEqual(wire.includes(Buffer.from('sale.created')), false, 'backup must not contain plaintext event');
        const target = path.join(root, 'restored');
        restoreBackup({ backupPath: backup.path, targetDir: target, encryptionKey: KEY, partition: 'partition-a' });
        const restored = new EventStore(storeOptions(target));
        assert.deepStrictEqual(restored.pending().map((item) => item.id), ['event-backup-1']);
        const existing = path.join(root, 'existing');
        fs.mkdirSync(existing); fs.writeFileSync(path.join(existing, 'keep'), 'unchanged');
        assert.throws(() => restoreBackup({ backupPath: backup.path, targetDir: existing, encryptionKey: KEY, partition: 'partition-a' }), /replace=true/);
        assert.strictEqual(fs.readFileSync(path.join(existing, 'keep'), 'utf8'), 'unchanged');
        assert.throws(() => restoreBackup({ backupPath: backup.path, targetDir: existing, encryptionKey: Buffer.alloc(32, 7), partition: 'partition-a', replace: true }), /validation|authentication/);
        assert.strictEqual(fs.readFileSync(path.join(existing, 'keep'), 'utf8'), 'unchanged');
        fs.rmSync(root, { recursive: true, force: true });
    });

    await it('recovers an interrupted restore without ever creating an empty target', function () {
        const root = fs.mkdtempSync(path.join(os.tmpdir(), 'local-core-restore-crash-'));
        const source = new EventStore(storeOptions(path.join(root, 'source')));
        source.append(event('event-restore-source'));
        const backup = createBackup({ store: source, encryptionKey: KEY, partition: 'restore-p', backupDir: path.join(root, 'backups'),
            now: () => 1700000000999, minFreeBytes: 0 });
        const target = path.join(root, 'target');
        const active = new EventStore(storeOptions(target));
        active.append(event('event-active'));
        assert.throws(() => restoreBackup({ backupPath: backup.path, targetDir: target, encryptionKey: KEY, partition: 'restore-p', replace: true,
            fault: (stage) => { if (stage === 'after_active_rename') { const e = new Error('simulated power loss'); e.code = 'restore_interrupted_for_test'; throw e; } } }));
        assert.strictEqual(fs.existsSync(target), false);
        assert.deepStrictEqual(recoverInterruptedRestore(target), { recovered: true, action: 'rollback_restored' });
        assert.deepStrictEqual(new EventStore(storeOptions(target)).pending().map((e) => e.id), ['event-active']);
        fs.rmSync(root, { recursive: true, force: true });
    });

    await it('preflights backup reserve, retains active health, and avoids destination collisions', function () {
        const root = fs.mkdtempSync(path.join(os.tmpdir(), 'local-core-backup-space-'));
        const store = new EventStore(storeOptions(path.join(root, 'source')));
        store.append(event('event-space'));
        const options = { store, encryptionKey: KEY, partition: 'space-p', backupDir: path.join(root, 'backups'), now: () => 7,
            diskFreeBytes: () => 0, minFreeBytes: 1 };
        assert.throws(() => createBackup(options), /free space/);
        assert.strictEqual(store.readOnly, false);
        const good = createBackup(Object.assign({}, options, { diskFreeBytes: () => Number.MAX_SAFE_INTEGER, minFreeBytes: 0 }));
        assert.throws(() => createBackup(Object.assign({}, options, { backupPath: good.path, diskFreeBytes: () => Number.MAX_SAFE_INTEGER, minFreeBytes: 0 })), /already exists/);
        assert.strictEqual(good.backup_count, 1);
        assert.ok(good.backup_bytes > 0);
        fs.rmSync(root, { recursive: true, force: true });
    });

    await it('leaves the active store reserve plus room for the next offline write', function () {
        const root = fs.mkdtempSync(path.join(os.tmpdir(), 'local-core-backup-headroom-'));
        let free = 16 * 1024 * 1024;
        const sourceDir = path.join(root, 'source');
        const store = new EventStore(storeOptions(sourceDir, {
            minFreeBytes: 4096,
            diskFreeBytes: function () { return free; },
        }));
        store.append(event('event-headroom-1'));
        const backup = createBackup({
            store,
            encryptionKey: KEY,
            partition: 'headroom-p',
            backupDir: path.join(root, 'backups'),
            diskFreeBytes: function () { return free; },
            minFreeBytes: 4096,
            operationalMarginBytes: 1024 * 1024,
        });
        free -= backup.backup_bytes;
        assert.doesNotThrow(() => store.append(event('event-headroom-2')));

        fs.rmSync(path.join(root, 'backups'), { recursive: true, force: true });
        fs.mkdirSync(path.join(root, 'backups'));
        free = backup.backup_bytes + store.minFreeBytes + 1024 * 1024;
        assert.throws(() => createBackup({
            store,
            encryptionKey: KEY,
            partition: 'headroom-p',
            backupDir: path.join(root, 'backups'),
            diskFreeBytes: function () { return free; },
            minFreeBytes: store.minFreeBytes,
            operationalMarginBytes: 1024 * 1024,
        }), /free space/);
        fs.rmSync(root, { recursive: true, force: true });
    });

    await it('coalesces concurrent cloud drains into one request', async function () {
        const syncDir = fs.mkdtempSync(path.join(os.tmpdir(), 'local-core-sync-'));
        const store = new EventStore(storeOptions(syncDir));
        store.append(event('event-0003'));
        store.append(event('event-0004'));
        let calls = 0;
        let wire = null;
        const client = new CloudSyncClient({
            store,
            deviceUid: 'dev-test-1',
            request: async (body) => {
                calls++;
                await new Promise((resolve) => setTimeout(resolve, 15));
                wire = body;
                return { acknowledged_ids: [body.events[0].event_id, 'not-submitted-ever'] };
            },
        });
        const [a, b] = await Promise.all([client.sync(), client.sync()]);
        assert.strictEqual(calls, 1);
        assert.strictEqual(a.sent, 1);
        assert.strictEqual(b.sent, 1);
        assert.deepStrictEqual(wire, {
            version: 1,
            device_uid: 'dev-test-1',
            events: [
                { event_id: 'event-0003', event_type: 'sale.created', occurred_at: '2023-11-14T22:13:20.000Z', idempotency_key: 'event-0003', payload: { sale_id: 12 } },
                { event_id: 'event-0004', event_type: 'sale.created', occurred_at: '2023-11-14T22:13:20.000Z', idempotency_key: 'event-0004', payload: { sale_id: 12 } },
            ],
        });
        assert.strictEqual(store.status().pending_count, 1, 'unknown ACK must not mark arbitrary entries sent');
        fs.rmSync(syncDir, { recursive: true, force: true });
    });
    fs.rmSync(dir, { recursive: true, force: true });
    console.log(passed + ' passed' + (process.exitCode ? ' — WITH FAILURES' : ''));
    process.exit(process.exitCode || 0);
})();