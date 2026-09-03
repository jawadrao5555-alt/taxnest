'use strict';

// Same-install Local Core backups deliberately contain the already encrypted
// journals, never decoded records or key material.  The manifest is integrity
// protected with a key-derived HMAC so it cannot be repointed at another
// partition or key.
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const { EventStore } = require('./event-store');

const MAGIC = Buffer.from('PRABACK1');
const FORMAT = 1;
const FILES = ['events.ndjson', 'outbox.ndjson'];
const DEFAULT_MAX_FILE_BYTES = 256 * 1024 * 1024;
const DEFAULT_MAX_MANIFEST_BYTES = 16 * 1024;

function fsyncParent(file) {
    let fd;
    try {
        fd = fs.openSync(path.dirname(file), 'r');
        fs.fsyncSync(fd);
    } catch (e) {
        // Some Windows filesystems do not support directory fsync.
    } finally {
        if (fd !== undefined) try { fs.closeSync(fd); } catch (e) {}
    }
}

function sha256(bytes) { return crypto.createHash('sha256').update(bytes).digest('hex'); }
function hmac(key, value) { return crypto.createHmac('sha256', key).update(value).digest('hex'); }
function canonicalManifest(manifest) {
    return JSON.stringify({
        format: manifest.format,
        key_id: manifest.key_id,
        partition: manifest.partition,
        created_at_ms: manifest.created_at_ms,
        files: manifest.files,
    });
}
function keyIdFor(key) { return crypto.createHash('sha256').update(key).digest('hex').slice(0, 16); }
function validKey(key) { return Buffer.isBuffer(key) && key.length === 32; }
function validPartition(value) {
    return typeof value === 'string' && value.length > 0 && value.length <= 128 && !/[\/\\\0]/.test(value);
}
function maxFileBytes(options) {
    return Number.isInteger(options.maxFileBytes) && options.maxFileBytes >= 0
        ? options.maxFileBytes : DEFAULT_MAX_FILE_BYTES;
}
function readJournal(file, max) {
    try {
        const stat = fs.statSync(file);
        if (!stat.isFile() || stat.size > max) throw new Error('journal size exceeds backup limit');
        return fs.readFileSync(file);
    } catch (e) {
        if (e.code === 'ENOENT') return Buffer.alloc(0);
        throw e;
    }
}
function writeAll(fd, bytes) {
    let offset = 0;
    while (offset < bytes.length) {
        const written = fs.writeSync(fd, bytes, offset, bytes.length - offset);
        if (!written) throw new Error('short backup write');
        offset += written;
    }
}

function pruneAutomaticBackups(dir, partition, retain, retainedByteCap) {
    if (!Number.isInteger(retain) || retain < 0) return;
    const prefix = 'local-core-' + partition + '-';
    const files = fs.readdirSync(dir)
        .filter((name) => name.startsWith(prefix) && name.endsWith('.prab'))
        .map((name) => ({ name, stat: fs.statSync(path.join(dir, name)) }))
        .filter((item) => item.stat.isFile())
        .sort((a, b) => b.stat.mtimeMs - a.stat.mtimeMs || b.name.localeCompare(a.name));
    let total = files.reduce((n, item) => n + item.stat.size, 0);
    for (let i = files.length - 1; i >= 1; i--) {
        if (i >= retain || total > retainedByteCap) {
            fs.unlinkSync(path.join(dir, files[i].name));
            total -= files[i].stat.size;
        }
    }
    fsyncParent(path.join(dir, '.'));
}

function backupEntries(dir, partition) {
    const prefix = 'local-core-' + partition + '-';
    try {
        return fs.readdirSync(dir).filter((name) => name.startsWith(prefix) && name.endsWith('.prab'))
            .map((name) => ({ name, path: path.join(dir, name), stat: fs.statSync(path.join(dir, name)) }))
            .filter((item) => item.stat.isFile())
            .sort((a, b) => a.stat.mtimeMs - b.stat.mtimeMs || a.name.localeCompare(b.name));
    } catch (e) { return []; }
}

function prepareBackupSpace(dir, partition, needed, options) {
    const opts = options || {};
    const retain = Number.isInteger(opts.maxRetained) && opts.maxRetained >= 0 ? opts.maxRetained : 7;
    const byteCap = Number.isInteger(opts.maxRetainedBytes) && opts.maxRetainedBytes >= 0
        ? opts.maxRetainedBytes : Number.MAX_SAFE_INTEGER;
    const entries = backupEntries(dir, partition);
    const total = entries.reduce((n, item) => n + item.stat.size, 0);
    // Never prune before publish: an ENOSPC or power loss while writing the
    // replacement must leave every previously durable snapshot untouched.
    if (retain === 0 || needed > byteCap) throw new Error('backup retention capacity exceeded');
    const configuredReserve = Number.isInteger(opts.minFreeBytes) && opts.minFreeBytes >= 0
        ? opts.minFreeBytes : 32 * 1024 * 1024;
    const storeReserve = opts.store && Number.isInteger(opts.store.minFreeBytes) && opts.store.minFreeBytes >= 0
        ? opts.store.minFreeBytes : 0;
    // A backup must leave enough room for the store's own reserve AND a
    // bounded next write. Merely stopping at EventStore.minFreeBytes would make
    // the first valid offline mutation fail immediately after backup.
    const operationalMargin = Number.isInteger(opts.operationalMarginBytes) && opts.operationalMarginBytes >= 0
        ? opts.operationalMarginBytes : 2 * 1024 * 1024;
    const reserve = Math.max(configuredReserve, storeReserve) + operationalMargin;
    const free = typeof opts.diskFreeBytes === 'function' ? Number(opts.diskFreeBytes(dir)) :
        (typeof fs.statfsSync === 'function' ? (() => { const s = fs.statfsSync(dir); return Number(s.bavail) * Number(s.bsize); })() : Number.MAX_SAFE_INTEGER);
    if (!Number.isFinite(free) || free <= needed + reserve) throw new Error('insufficient free space for backup reserve');
    fs.readdirSync(dir).filter((name) => /\.prab\.tmp-/.test(name) || /\.prab\.reserve-/.test(name))
        .forEach((name) => { try { fs.unlinkSync(path.join(dir, name)); } catch (e) {} });
    return { retained_count: entries.length, retained_bytes: total };
}

function reserveOutput(dir, partition, timestamp, explicit) {
    if (explicit) {
        if (fs.existsSync(explicit)) throw new Error('backup destination already exists');
        const claim = explicit + '.reserve-' + process.pid;
        fs.openSync(claim, 'wx', 0o600);
        return { output: explicit, claim };
    }
    for (let i = 0; i < 1000; i++) {
        const suffix = i ? '-' + i : '';
        const output = path.join(dir, 'local-core-' + partition + '-' + timestamp + suffix + '.prab');
        const claim = output + '.reserve-' + process.pid;
        try { fs.openSync(claim, 'wx', 0o600); return { output, claim }; } catch (e) { if (e.code !== 'EEXIST') throw e; }
    }
    throw new Error('could not reserve unique backup destination');
}

function createBackup(options) {
    const opts = options || {};
    if (!opts.store || opts.store.readOnly) throw new Error('healthy writable EventStore is required for backup');
    if (!validKey(opts.encryptionKey)) throw new Error('backup requires a 32-byte encryption key');
    if (!validPartition(opts.partition)) throw new Error('backup partition is invalid');
    const keyId = String(opts.encryptionKeyId || opts.store.keyId || keyIdFor(opts.encryptionKey));
    if (keyId !== String(opts.store.keyId || keyId)) throw new Error('backup key-id does not match EventStore');
    const limit = maxFileBytes(opts);
    const bytes = FILES.map((name) => readJournal(path.join(opts.store.dir, name), limit));
    const manifest = {
        format: FORMAT,
        key_id: keyId,
        partition: opts.partition,
        created_at_ms: typeof opts.now === 'function' ? opts.now() : Date.now(),
        files: FILES.map((name, index) => ({ name, size: bytes[index].length, sha256: sha256(bytes[index]) })),
    };
    manifest.hmac_sha256 = hmac(opts.encryptionKey, canonicalManifest(manifest));
    const manifestBytes = Buffer.from(JSON.stringify(manifest), 'utf8');
    if (manifestBytes.length > DEFAULT_MAX_MANIFEST_BYTES) throw new Error('backup manifest is too large');
    const destinationDir = opts.backupDir || path.dirname(opts.backupPath || '');
    if (!destinationDir) throw new Error('backupDir or backupPath is required');
    fs.mkdirSync(destinationDir, { recursive: true, mode: 0o700 });
    const needed = MAGIC.length + 4 + manifestBytes.length + bytes.reduce((n, item) => n + item.length, 0);
    prepareBackupSpace(destinationDir, opts.partition, needed, opts);
    const reserved = reserveOutput(destinationDir, opts.partition, manifest.created_at_ms, opts.backupPath);
    const output = reserved.output;
    const temporary = output + '.tmp-' + process.pid + '-' + crypto.randomBytes(6).toString('hex');
    let fd;
    try {
        fd = fs.openSync(temporary, 'wx', 0o600);
        writeAll(fd, MAGIC);
        const length = Buffer.alloc(4);
        length.writeUInt32BE(manifestBytes.length, 0);
        writeAll(fd, length);
        writeAll(fd, manifestBytes);
        bytes.forEach((item) => writeAll(fd, item));
        fs.fsyncSync(fd);
        fs.closeSync(fd); fd = undefined;
        fs.renameSync(temporary, output);
        fs.unlinkSync(reserved.claim);
        try { fs.chmodSync(output, 0o600); } catch (e) {}
        fsyncParent(output);
    } catch (e) {
        if (fd !== undefined) try { fs.closeSync(fd); } catch (ignored) {}
        try { fs.unlinkSync(temporary); } catch (ignored) {}
        try { fs.unlinkSync(reserved.claim); } catch (ignored) {}
        throw e;
    }
    if (opts.automatic === true) pruneAutomaticBackups(destinationDir, opts.partition,
        Number.isInteger(opts.maxRetained) ? opts.maxRetained : 7,
        Number.isInteger(opts.maxRetainedBytes) ? opts.maxRetainedBytes : Number.MAX_SAFE_INTEGER);
    const retained = backupEntries(destinationDir, opts.partition);
    return { path: output, manifest: JSON.parse(JSON.stringify(manifest)), backup_count: retained.length,
        backup_bytes: retained.reduce((n, item) => n + item.stat.size, 0), created_at_ms: manifest.created_at_ms };
}

function markerPath(target) { return path.join(path.dirname(target), '.' + path.basename(target) + '.restore-transaction.json'); }
function writeMarker(file, value) {
    const temp = file + '.tmp-' + process.pid;
    const fd = fs.openSync(temp, 'wx', 0o600);
    try { writeAll(fd, Buffer.from(JSON.stringify(value), 'utf8')); fs.fsyncSync(fd); } finally { fs.closeSync(fd); }
    fs.renameSync(temp, file); fsyncParent(file);
}
function recoverInterruptedRestore(targetDir) {
    const target = path.resolve(targetDir);
    const marker = markerPath(target);
    if (!fs.existsSync(marker)) return { recovered: false };
    let record;
    try { record = JSON.parse(fs.readFileSync(marker, 'utf8')); } catch (e) { throw new Error('restore recovery marker is invalid'); }
    if (!record || record.v !== 1 || record.target !== target || typeof record.rollback !== 'string') throw new Error('restore recovery marker is invalid');
    // An absent canonical store always rolls back. Never create an empty target.
    if (!fs.existsSync(target) && fs.existsSync(record.rollback)) {
        fs.renameSync(record.rollback, target); fsyncParent(target);
        try { fs.unlinkSync(marker); } catch (e) {}
        return { recovered: true, action: 'rollback_restored' };
    }
    // Target plus rollback is a completed-but-unfinalized transaction. Preserve
    // the rollback copy for manual recovery rather than silently deleting it.
    if (fs.existsSync(target)) return { recovered: false, action: 'rollback_preserved' };
    throw new Error('interrupted restore has no recoverable active store');
}

function parseBackup(backupPath, key, partition, options) {
    const opts = options || {};
    const cap = maxFileBytes(opts);
    const manifestCap = Number.isInteger(opts.maxManifestBytes) ? opts.maxManifestBytes : DEFAULT_MAX_MANIFEST_BYTES;
    const stat = fs.statSync(backupPath);
    if (!stat.isFile() || stat.size > (MAGIC.length + 4 + manifestCap + cap * FILES.length)) throw new Error('backup exceeds size limit');
    const all = fs.readFileSync(backupPath);
    if (all.length < MAGIC.length + 4 || !all.subarray(0, MAGIC.length).equals(MAGIC)) throw new Error('invalid backup format');
    const manifestLength = all.readUInt32BE(MAGIC.length);
    const start = MAGIC.length + 4;
    if (!manifestLength || manifestLength > manifestCap || start + manifestLength > all.length) throw new Error('invalid backup manifest length');
    let manifest;
    try { manifest = JSON.parse(all.subarray(start, start + manifestLength).toString('utf8')); } catch (e) { throw new Error('invalid backup manifest'); }
    const expectedKeyId = String(opts.encryptionKeyId || keyIdFor(key));
    if (!manifest || manifest.format !== FORMAT || manifest.key_id !== expectedKeyId ||
        manifest.partition !== partition || !Number.isInteger(manifest.created_at_ms) ||
        !Array.isArray(manifest.files) || manifest.files.length !== FILES.length ||
        typeof manifest.hmac_sha256 !== 'string' || !/^[a-f0-9]{64}$/.test(manifest.hmac_sha256)) throw new Error('backup manifest validation failed');
    const expected = Buffer.from(hmac(key, canonicalManifest(manifest)), 'hex');
    const supplied = Buffer.from(manifest.hmac_sha256, 'hex');
    if (!crypto.timingSafeEqual(expected, supplied)) throw new Error('backup manifest authentication failed');
    let offset = start + manifestLength;
    const files = [];
    for (let i = 0; i < FILES.length; i++) {
        const entry = manifest.files[i];
        if (!entry || entry.name !== FILES[i] || !Number.isInteger(entry.size) || entry.size < 0 || entry.size > cap ||
            typeof entry.sha256 !== 'string' || !/^[a-f0-9]{64}$/.test(entry.sha256) || offset + entry.size > all.length) {
            throw new Error('backup file metadata validation failed');
        }
        const data = all.subarray(offset, offset + entry.size);
        if (sha256(data) !== entry.sha256) throw new Error('backup file hash validation failed');
        files.push(data); offset += entry.size;
    }
    if (offset !== all.length) throw new Error('backup has trailing data');
    return { manifest, files };
}

function restoreBackup(options) {
    const opts = options || {};
    if (!validKey(opts.encryptionKey)) throw new Error('restore requires a 32-byte encryption key');
    if (!validPartition(opts.partition) || !opts.targetDir || !opts.backupPath) throw new Error('restore options are invalid');
    const target = path.resolve(opts.targetDir);
    const parsed = parseBackup(opts.backupPath, opts.encryptionKey, opts.partition, opts);
    if (fs.existsSync(target) && opts.replace !== true) throw new Error('restore target already exists; replace=true is required');
    const staging = target + '.restore-staging-' + process.pid + '-' + crypto.randomBytes(6).toString('hex');
    const rollback = target + '.restore-rollback-' + process.pid + '-' + crypto.randomBytes(6).toString('hex');
    const marker = markerPath(target);
    let movedTarget = false;
    let completed = false;
    try {
        fs.mkdirSync(staging, { recursive: false, mode: 0o700 });
        FILES.forEach((name, i) => {
            const file = path.join(staging, name);
            const fd = fs.openSync(file, 'wx', 0o600);
            try { writeAll(fd, parsed.files[i]); fs.fsyncSync(fd); } finally { fs.closeSync(fd); }
        });
        fsyncParent(path.join(staging, '.'));
        const validated = new EventStore({ dataDir: staging, encryptionKey: opts.encryptionKey, encryptionKeyId: parsed.manifest.key_id,
            minFreeBytes: 0, allowPlaintextMigration: false, maxBytes: maxFileBytes(opts) * FILES.length });
        if (validated.readOnly) throw new Error('restored EventStore validation failed');
        writeMarker(marker, { v: 1, target, rollback, staging });
        if (fs.existsSync(target)) { fs.renameSync(target, rollback); movedTarget = true; }
        if (typeof opts.fault === 'function') opts.fault('after_active_rename');
        fs.renameSync(staging, target);
        if (typeof opts.fault === 'function') opts.fault('after_staging_rename');
        fsyncParent(target);
        if (movedTarget) fs.rmSync(rollback, { recursive: true, force: true });
        try { fs.unlinkSync(marker); fsyncParent(marker); } catch (e) { throw e; }
        completed = true;
        return { manifest: parsed.manifest, targetDir: target };
    } catch (e) {
        // If install failed after moving the active directory, restore it before
        // returning. Validation failures occur before this point touches target.
        if (e && e.code === 'restore_interrupted_for_test') {
            // Test-only crash seam: deliberately leave the durable marker and
            // rollback exactly as a process loss would, for startup recovery.
            throw e;
        }
        if (movedTarget) {
            try { if (fs.existsSync(target)) fs.rmSync(target, { recursive: true, force: true }); fs.renameSync(rollback, target); fsyncParent(target); } catch (ignored) {}
        }
        throw e;
    } finally {
        try { fs.rmSync(staging, { recursive: true, force: true }); } catch (e) {}
        // If rollback itself could not be restored, preserve it rather than
        // destroying the only active-store copy during an I/O failure.
        if (completed || !movedTarget) {
            try { if (fs.existsSync(rollback)) fs.rmSync(rollback, { recursive: true, force: true }); } catch (e) {}
        }
    }
}

module.exports = { createBackup, restoreBackup, recoverInterruptedRestore };