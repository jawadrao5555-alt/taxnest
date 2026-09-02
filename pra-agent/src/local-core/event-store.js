'use strict';

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const { validateEvent } = require('./protocol');

const MAGIC = Buffer.from('PRAE');
const FORMAT_VERSION = 2;
const NONCE_BYTES = 12;
const TAG_BYTES = 16;
const FIXED_HEADER_BYTES = MAGIC.length + 1 + 1 + NONCE_BYTES + 4;
const MAX_KEY_ID_BYTES = 64;
const MAX_FRAME_BYTES = 1024 * 1024;

class StoreReadOnlyError extends Error {
    constructor(message) { super(message); this.name = 'StoreReadOnlyError'; this.code = 'store_read_only'; }
}

class StorageFullError extends Error {
    constructor(message) { super(message); this.name = 'StorageFullError'; this.code = 'storage_full'; }
}

function digest(value) {
    return crypto.createHash('sha256').update(JSON.stringify(value)).digest('hex');
}

function clone(value) { return JSON.parse(JSON.stringify(value)); }

function integerOption(value, fallback, minimum) {
    return Number.isInteger(value) && value >= minimum ? value : fallback;
}

function writeAll(fd, buffer) {
    let offset = 0;
    while (offset < buffer.length) {
        const written = fs.writeSync(fd, buffer, offset, buffer.length - offset);
        if (!Number.isInteger(written) || written <= 0) throw new Error('short journal write');
        offset += written;
    }
}

class EventStore {
    constructor(options) {
        const opts = options || {};
        if (!Buffer.isBuffer(opts.encryptionKey) || opts.encryptionKey.length !== 32) {
            throw new Error('encryptionKey must be an explicit 32-byte Buffer');
        }
        this.encryptionKey = Buffer.from(opts.encryptionKey);
        this.keyId = String(opts.encryptionKeyId ||
            crypto.createHash('sha256').update(this.encryptionKey).digest('hex').slice(0, 16));
        if (!this.keyId || Buffer.byteLength(this.keyId, 'utf8') > MAX_KEY_ID_BYTES) {
            throw new Error('encryptionKeyId must be 1-64 UTF-8 bytes');
        }
        this.keyIdBuffer = Buffer.from(this.keyId, 'utf8');
        this.dir = opts.dataDir || path.join(process.cwd(), 'local-core');
        this.eventsFile = path.join(this.dir, 'events.ndjson');
        this.outboxFile = path.join(this.dir, 'outbox.ndjson');
        this.allowPlaintextMigration = opts.allowPlaintextMigration === true;
        this.maxEvents = integerOption(opts.maxEvents, 100000, 1);
        this.maxBytes = integerOption(opts.maxBytes, 256 * 1024 * 1024, 1);
        this.minFreeBytes = integerOption(opts.minFreeBytes, 32 * 1024 * 1024, 0);
        this.sentRetentionMs = integerOption(opts.sentRetentionMs, 7 * 24 * 60 * 60 * 1000, 0);
        this.diskFreeBytes = typeof opts.diskFreeBytes === 'function'
            ? opts.diskFreeBytes
            : (dir) => {
                if (typeof fs.statfsSync !== 'function') return Number.MAX_SAFE_INTEGER;
                const stat = fs.statfsSync(dir);
                return Number(stat.bavail) * Number(stat.bsize);
            };
        this.now = typeof opts.now === 'function' ? opts.now : Date.now;
        this.events = new Map();
        this.outbox = new Map();
        this.sequence = 0;
        this.readOnly = false;
        this.storageFull = false;
        this.corruption = null;
        this._mutating = false;
        this.telemetry = { recovered_tail_frames: 0, plaintext_migrations: 0, compactions: 0 };
        fs.mkdirSync(this.dir, { recursive: true });
        this._load();
    }

    _failReadOnly(file, reason) {
        this.readOnly = true;
        this.corruption = { file: path.basename(file), reason: String(reason), at_ms: this.now() };
    }

    _withMutation(fn) {
        if (this._mutating) {
            const error = new Error('a synchronous store mutation is already in progress');
            error.code = 'concurrent_mutation';
            throw error;
        }
        this._mutating = true;
        try { return fn(); } finally { this._mutating = false; }
    }

    _frame(data) {
        const plaintext = Buffer.from(JSON.stringify(data), 'utf8');
        if (plaintext.length > MAX_FRAME_BYTES) throw new Error('journal record is too large');
        const nonce = crypto.randomBytes(NONCE_BYTES);
        const header = Buffer.alloc(FIXED_HEADER_BYTES + this.keyIdBuffer.length);
        MAGIC.copy(header, 0);
        header.writeUInt8(FORMAT_VERSION, 4);
        header.writeUInt8(this.keyIdBuffer.length, 5);
        this.keyIdBuffer.copy(header, 6);
        nonce.copy(header, 6 + this.keyIdBuffer.length);
        header.writeUInt32BE(plaintext.length, 6 + this.keyIdBuffer.length + NONCE_BYTES);
        const cipher = crypto.createCipheriv('aes-256-gcm', this.encryptionKey, nonce);
        cipher.setAAD(header);
        const ciphertext = Buffer.concat([cipher.update(plaintext), cipher.final()]);
        return Buffer.concat([header, ciphertext, cipher.getAuthTag()]);
    }

    _legacyRecords(buffer, file) {
        if (!this.allowPlaintextMigration) {
            throw new Error('legacy plaintext journal requires allowPlaintextMigration=true');
        }
        const records = [];
        const lines = buffer.toString('utf8').split('\n');
        for (let i = 0; i < lines.length; i++) {
            if (!lines[i]) continue;
            let record;
            try {
                record = JSON.parse(lines[i]);
                if (!record || record.v !== 1 || !record.data ||
                    record.checksum !== digest(record.data)) throw new Error('invalid record checksum');
            } catch (e) {
                throw new Error('legacy line ' + (i + 1) + ': ' + e.message);
            }
            records.push(record.data);
        }
        this._validateLegacyRecords(file, records);
        this._replace(file, records);
        this.telemetry.plaintext_migrations++;
        return records;
    }

    _validateLegacyRecords(file, records) {
        if (file === this.eventsFile) {
            const ids = new Set();
            for (const data of records) {
                if (!Number.isInteger(data.seq) || data.seq < 1) throw new Error('invalid event sequence');
                const event = validateEvent(data.event);
                if (ids.has(event.id)) throw new Error('duplicate event id in journal');
                ids.add(event.id);
                if (ids.size > this.maxEvents) throw new Error('journal exceeds configured maxEvents');
            }
            return;
        }
        for (const data of records) {
            if (!this.events.has(data.id) || ['queued', 'sent'].indexOf(data.state) === -1 ||
                !Number.isInteger(data.at_ms) || !Number.isInteger(data.attempts) || data.attempts < 0) {
                throw new Error('invalid outbox record');
            }
        }
    }

    _truncateTail(file, length) {
        let fd;
        try {
            fd = fs.openSync(file, 'r+');
            fs.ftruncateSync(fd, length);
            fs.fsyncSync(fd);
            this.telemetry.recovered_tail_frames++;
        } finally {
            if (fd !== undefined) try { fs.closeSync(fd); } catch (e) {}
        }
    }

    _readRecords(file) {
        if (!fs.existsSync(file)) return [];
        const buffer = fs.readFileSync(file);
        if (!buffer.length) return [];
        if (!buffer.subarray(0, MAGIC.length).equals(MAGIC)) {
            // A crash may leave only the first 1-3 bytes of the very first
            // encrypted frame. That is a recoverable incomplete final frame,
            // not a plaintext legacy journal. Legacy migration is entered only
            // for a positive JSON-object signature; every other prefix is
            // authenticated-format corruption and must fail closed.
            if (buffer.length < MAGIC.length && MAGIC.subarray(0, buffer.length).equals(buffer)) {
                this._truncateTail(file, 0);
                return [];
            }
            if (buffer[0] === 0x7b) return this._legacyRecords(buffer, file); // {
            throw new Error('invalid journal format');
        }
        const records = [];
        let offset = 0;
        while (offset < buffer.length) {
            const remaining = buffer.length - offset;
            if (remaining < FIXED_HEADER_BYTES) {
                this._truncateTail(file, offset);
                break;
            }
            if (!buffer.subarray(offset, offset + 4).equals(MAGIC)) {
                throw new Error('invalid frame magic at byte ' + offset);
            }
            const version = buffer.readUInt8(offset + 4);
            const keyIdLength = buffer.readUInt8(offset + 5);
            if (version !== FORMAT_VERSION) throw new Error('unsupported journal version ' + version);
            if (!keyIdLength || keyIdLength > MAX_KEY_ID_BYTES) throw new Error('invalid key-id metadata');
            const headerLength = FIXED_HEADER_BYTES + keyIdLength;
            if (remaining < headerLength) {
                this._truncateTail(file, offset);
                break;
            }
            const keyId = buffer.subarray(offset + 6, offset + 6 + keyIdLength).toString('utf8');
            if (keyId !== this.keyId) throw new Error('journal encryption key-id mismatch');
            const nonceOffset = offset + 6 + keyIdLength;
            const length = buffer.readUInt32BE(nonceOffset + NONCE_BYTES);
            if (length > MAX_FRAME_BYTES) throw new Error('invalid frame length');
            const frameLength = headerLength + length + TAG_BYTES;
            if (remaining < frameLength) {
                this._truncateTail(file, offset);
                break;
            }
            const header = buffer.subarray(offset, offset + headerLength);
            const ciphertext = buffer.subarray(offset + headerLength, offset + headerLength + length);
            const tag = buffer.subarray(offset + headerLength + length, offset + frameLength);
            try {
                const decipher = crypto.createDecipheriv('aes-256-gcm', this.encryptionKey,
                    buffer.subarray(nonceOffset, nonceOffset + NONCE_BYTES));
                decipher.setAAD(header);
                decipher.setAuthTag(tag);
                const plaintext = Buffer.concat([decipher.update(ciphertext), decipher.final()]);
                records.push(JSON.parse(plaintext.toString('utf8')));
            } catch (e) {
                throw new Error('authentication failed at byte ' + offset + ': ' + e.message);
            }
            offset += frameLength;
        }
        return records;
    }

    _load() {
        try {
            for (const data of this._readRecords(this.eventsFile)) {
                if (!Number.isInteger(data.seq) || data.seq < 1) throw new Error('invalid event sequence');
                const event = validateEvent(data.event);
                if (this.events.has(event.id)) throw new Error('duplicate event id in journal');
                this.events.set(event.id, event);
                this.outbox.set(event.id, { state: 'queued', attempts: 0, updated_at_ms: event.at_ms });
                this.sequence = Math.max(this.sequence, data.seq);
                if (this.events.size > this.maxEvents) throw new Error('journal exceeds configured maxEvents');
            }
        } catch (e) {
            this._failReadOnly(this.eventsFile, e.message);
            return;
        }
        try {
            for (const data of this._readRecords(this.outboxFile)) {
                if (!this.events.has(data.id) || ['queued', 'sent'].indexOf(data.state) === -1 ||
                    !Number.isInteger(data.at_ms) || !Number.isInteger(data.attempts) || data.attempts < 0) {
                    throw new Error('invalid outbox record');
                }
                this.outbox.set(data.id, { state: data.state, attempts: data.attempts, updated_at_ms: data.at_ms });
            }
        } catch (e) {
            this._failReadOnly(this.outboxFile, e.message);
        }
    }

    _journalBytes() {
        const size = (file) => {
            try { return fs.statSync(file).size; } catch (e) { return e.code === 'ENOENT' ? 0 : (() => { throw e; })(); }
        };
        return { events: size(this.eventsFile), outbox: size(this.outboxFile) };
    }

    _freeBytes() {
        const value = Number(this.diskFreeBytes(this.dir));
        return Number.isFinite(value) && value >= 0 ? value : 0;
    }

    _ensureCapacity(extraBytes, extraEvents) {
        let sizes = this._journalBytes();
        let full = this.events.size + extraEvents > this.maxEvents ||
            sizes.events + sizes.outbox + extraBytes > this.maxBytes ||
            this._freeBytes() - extraBytes < this.minFreeBytes;
        if (full) {
            try {
                this._compactInternal();
            } catch (e) {
                if (e && ['ENOSPC', 'EDQUOT'].indexOf(e.code) !== -1) {
                    this.storageFull = true;
                    throw new StorageFullError('local core storage capacity reached');
                }
                throw e;
            }
            sizes = this._journalBytes();
            full = this.events.size + extraEvents > this.maxEvents ||
                sizes.events + sizes.outbox + extraBytes > this.maxBytes ||
                this._freeBytes() - extraBytes < this.minFreeBytes;
        }
        this.storageFull = full;
        if (full) throw new StorageFullError('local core storage capacity reached');
    }

    _append(file, data, skipCapacity) {
        if (this.readOnly) throw new StoreReadOnlyError('local core store is read-only after corruption');
        const frame = this._frame(data);
        if (!skipCapacity) this._ensureCapacity(frame.length, 0);
        let fd;
        try {
            fd = fs.openSync(file, fs.existsSync(file) ? 'a' : 'ax', 0o600);
            writeAll(fd, frame);
            fs.fsyncSync(fd);
        } catch (e) {
            this.readOnly = true;
            this.corruption = { file: path.basename(file), reason: 'append failed: ' + e.message, at_ms: this.now() };
            throw new StoreReadOnlyError(this.corruption.reason);
        } finally {
            if (fd !== undefined) try { fs.closeSync(fd); } catch (e) {}
        }
    }

    _fsyncDirectory() {
        let fd;
        try {
            fd = fs.openSync(this.dir, 'r');
            fs.fsyncSync(fd);
        } catch (e) {
            // Directory fsync is unsupported on some platforms.
        } finally {
            if (fd !== undefined) try { fs.closeSync(fd); } catch (e) {}
        }
    }

    _replace(file, records) {
        const temp = file + '.tmp-' + process.pid + '-' + crypto.randomBytes(6).toString('hex');
        let fd;
        try {
            fd = fs.openSync(temp, 'wx', 0o600);
            for (const record of records) {
                const frame = this._frame(record);
                writeAll(fd, frame);
            }
            fs.fsyncSync(fd);
            fs.closeSync(fd);
            fd = undefined;
            fs.renameSync(temp, file);
            this._fsyncDirectory();
        } catch (e) {
            if (fd !== undefined) try { fs.closeSync(fd); } catch (ignored) {}
            try { fs.unlinkSync(temp); } catch (ignored) {}
            throw e;
        }
    }

    _compactInternal() {
        if (this.readOnly) return 0;
        const cutoff = this.now() - this.sentRetentionMs;
        const remove = [];
        for (const [id, state] of this.outbox) {
            if (state.state === 'sent' && state.updated_at_ms <= cutoff) remove.push(id);
        }
        const keptIds = new Set(Array.from(this.events.keys()).filter((id) => remove.indexOf(id) === -1));
        const outboxRecords = [];
        for (const [id, state] of this.outbox) {
            if (keptIds.has(id)) outboxRecords.push({ id, state: state.state, attempts: state.attempts, at_ms: state.updated_at_ms });
        }
        const eventRecords = [];
        for (const [id, event] of this.events) {
            if (keptIds.has(id)) eventRecords.push({ seq: ++this.sequence, event });
        }
        // Outbox first: a crash between replacements can only cause a safe resend.
        this._replace(this.outboxFile, outboxRecords);
        this._replace(this.eventsFile, eventRecords);
        for (const id of remove) {
            this.events.delete(id);
            this.outbox.delete(id);
        }
        this.telemetry.compactions++;
        return remove.length;
    }

    compact() {
        return this._withMutation(() => this._compactInternal());
    }

    append(event) {
        return this._withMutation(() => {
            if (this.readOnly) throw new StoreReadOnlyError('local core store is read-only after corruption');
            const safe = validateEvent(event);
            if (this.events.has(safe.id)) return { duplicate: true, event: clone(this.events.get(safe.id)) };
            const now = this.now();
            const outboxData = { id: safe.id, state: 'queued', attempts: 0, at_ms: now };
            const required = this._frame({ seq: this.sequence + 1, event: safe }).length + this._frame(outboxData).length;
            this._ensureCapacity(required, 1);
            const eventData = { seq: ++this.sequence, event: safe };
            this._append(this.eventsFile, eventData, true);
            this.events.set(safe.id, safe);
            this.outbox.set(safe.id, { state: 'queued', attempts: 0, updated_at_ms: now });
            this._append(this.outboxFile, outboxData, true);
            this.storageFull = false;
            return { duplicate: false, event: clone(safe) };
        });
    }

    pending(limit) {
        const max = Number.isInteger(limit) ? Math.max(0, limit) : 100;
        const out = [];
        for (const [id, event] of this.events) {
            const state = this.outbox.get(id);
            if (state && state.state === 'queued') {
                out.push(clone(event));
                if (out.length >= max) break;
            }
        }
        return out;
    }

    markSent(ids) {
        return this._withMutation(() => {
            if (this.readOnly) throw new StoreReadOnlyError('local core store is read-only after corruption');
            let changed = 0;
            for (const id of new Set((ids || []).map(String))) {
                const prior = this.outbox.get(id);
                if (!prior || prior.state === 'sent') continue;
                const next = { state: 'sent', attempts: prior.attempts + 1, updated_at_ms: this.now() };
                this._append(this.outboxFile,
                    { id, state: next.state, attempts: next.attempts, at_ms: next.updated_at_ms });
                this.outbox.set(id, next);
                changed++;
            }
            return changed;
        });
    }

    noteAttempt(ids) {
        return this._withMutation(() => {
            if (this.readOnly) return;
            for (const id of new Set((ids || []).map(String))) {
                const prior = this.outbox.get(id);
                if (!prior || prior.state !== 'queued') continue;
                const next = { state: 'queued', attempts: prior.attempts + 1, updated_at_ms: this.now() };
                this._append(this.outboxFile,
                    { id, state: next.state, attempts: next.attempts, at_ms: next.updated_at_ms });
                this.outbox.set(id, next);
            }
        });
    }

    status() {
        let pending = 0;
        this.outbox.forEach((value) => { if (value.state === 'queued') pending++; });
        const sizes = this._journalBytes();
        let freeBytes;
        try { freeBytes = this._freeBytes(); } catch (e) { freeBytes = null; }
        return {
            enabled: true,
            read_only: this.readOnly,
            storage_full: this.storageFull,
            event_count: this.events.size,
            pending_count: pending,
            corruption: this.corruption ? clone(this.corruption) : null,
            encryption: { enabled: true, algorithm: 'aes-256-gcm', format_version: FORMAT_VERSION, key_id: this.keyId },
            journal: {
                events_bytes: sizes.events,
                outbox_bytes: sizes.outbox,
                total_bytes: sizes.events + sizes.outbox,
                recovered_tail_frames: this.telemetry.recovered_tail_frames,
                plaintext_migrations: this.telemetry.plaintext_migrations,
                compactions: this.telemetry.compactions,
            },
            storage: {
                max_events: this.maxEvents, max_bytes: this.maxBytes, min_free_bytes: this.minFreeBytes,
                free_bytes: freeBytes,
            },
        };
    }
}

module.exports = { EventStore, StoreReadOnlyError, StorageFullError };