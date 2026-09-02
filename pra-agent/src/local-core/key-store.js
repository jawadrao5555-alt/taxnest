'use strict';

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

function fsyncParentDir(file) {
    let fd = null;
    try {
        fd = fs.openSync(path.dirname(file), 'r');
        fs.fsyncSync(fd);
    } catch (e) {
        // Windows does not consistently permit directory handles to be fsynced.
    } finally {
        if (fd !== null) try { fs.closeSync(fd); } catch (e) {}
    }
}

function hasCoreJournal(root) {
    try {
        return fs.readdirSync(root, { withFileTypes: true }).some((entry) => {
            if (!entry.isDirectory() || !/^[a-f0-9]{64}$/.test(entry.name)) return false;
            const dir = path.join(root, entry.name);
            return fs.existsSync(path.join(dir, 'events.ndjson')) ||
                fs.existsSync(path.join(dir, 'outbox.ndjson'));
        });
    } catch (e) {
        return false;
    }
}

// safeStorage is injected by main.js. On the shipped Windows agent it wraps
// this random key with DPAPI; tests inject a deterministic stand-in.
function loadOrCreateCoreKey(options) {
    const opts = options || {};
    const root = String(opts.dataDir || '');
    const safeStorage = opts.safeStorage;
    if (!root) throw new Error('Local Core key dataDir is required');
    if (!safeStorage || typeof safeStorage.isEncryptionAvailable !== 'function' ||
        typeof safeStorage.encryptString !== 'function' || typeof safeStorage.decryptString !== 'function' ||
        !safeStorage.isEncryptionAvailable()) {
        throw new Error('Windows secure storage is unavailable; Local Core remains disabled');
    }

    const keyFile = path.join(root, 'core-key.dpapi');
    if (fs.existsSync(keyFile)) {
        let decoded;
        try {
            decoded = JSON.parse(safeStorage.decryptString(fs.readFileSync(keyFile)));
        } catch (e) {
            throw new Error('Local Core encryption key cannot be unlocked; refusing to replace it');
        }
        const key = decoded && decoded.v === 1 ? Buffer.from(String(decoded.key_b64 || ''), 'base64') : null;
        if (!key || key.length !== 32 || !/^[a-f0-9]{16}$/.test(String(decoded.key_id || ''))) {
            throw new Error('Local Core encryption key file is invalid; refusing to replace it');
        }
        return { key, keyId: decoded.key_id, created: false };
    }

    if (hasCoreJournal(root)) {
        throw new Error('Local Core journal exists but its encryption key is missing; restore the key backup');
    }

    const key = crypto.randomBytes(32);
    const keyId = crypto.createHash('sha256').update(key).digest('hex').slice(0, 16);
    const wrapped = safeStorage.encryptString(JSON.stringify({
        v: 1,
        key_id: keyId,
        key_b64: key.toString('base64'),
    }));
    if (!Buffer.isBuffer(wrapped) || wrapped.length < 1) {
        throw new Error('Windows secure storage returned an invalid wrapped key');
    }

    fs.mkdirSync(root, { recursive: true });
    const tmp = keyFile + '.tmp-' + process.pid;
    let fd = null;
    try {
        fd = fs.openSync(tmp, 'wx', 0o600);
        fs.writeFileSync(fd, wrapped);
        fs.fsyncSync(fd);
        fs.closeSync(fd);
        fd = null;
        fs.renameSync(tmp, keyFile);
        try { fs.chmodSync(keyFile, 0o600); } catch (e) {}
        fsyncParentDir(keyFile);
    } catch (e) {
        if (fd !== null) try { fs.closeSync(fd); } catch (ignored) {}
        try { fs.unlinkSync(tmp); } catch (ignored) {}
        throw new Error('Local Core encryption key could not be saved: ' + e.message);
    }
    return { key, keyId, created: true };
}

module.exports = { loadOrCreateCoreKey, hasCoreJournal };