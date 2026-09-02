'use strict';

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const { validateEvent } = require('./protocol');

class StoreReadOnlyError extends Error {
    constructor(message) { super(message); this.name = 'StoreReadOnlyError'; this.code = 'store_read_only'; }
}

function digest(value) {
    return crypto.createHash('sha256').update(JSON.stringify(value)).digest('hex');
}

function clone(value) { return JSON.parse(JSON.stringify(value)); }

// Append-only JSON-lines journals. Every record has a checksum, and a damaged
// journal is moved aside and made read-only rather than guessed at or repaired.
class EventStore {
    constructor(options) {
        const opts = options || {};
        this.dir = opts.dataDir || path.join(process.cwd(), 'local-core');
        this.eventsFile = path.join(this.dir, 'events.ndjson');
        this.outboxFile = path.join(this.dir, 'outbox.ndjson');
        this.events = new Map();
        this.outbox = new Map();
        this.sequence = 0;
        this.readOnly = false;
        this.corruption = null;
        fs.mkdirSync(this.dir, { recursive: true });
        this._load();
    }

    _quarantine(file, reason) {
        this.readOnly = true;
        this.corruption = { file: path.basename(file), reason: String(reason), at_ms: Date.now() };
        try {
            if (fs.existsSync(file)) fs.renameSync(file, file + '.corrupt-' + Date.now());
        } catch (e) {
            this.corruption.quarantine_error = e.message;
        }
    }

    _readJournal(file, apply) {
        if (!fs.existsSync(file)) return;
        let lines;
        try { lines = fs.readFileSync(file, 'utf8').split('\n'); } catch (e) { this._quarantine(file, e.message); return; }
        for (let i = 0; i < lines.length; i++) {
            if (!lines[i]) continue; // final newline is optional
            let record;
            try {
                record = JSON.parse(lines[i]);
                if (!record || record.v !== 1 || !record.data || record.checksum !== digest(record.data)) throw new Error('invalid record checksum');
                apply(record.data);
            } catch (e) {
                this._quarantine(file, 'line ' + (i + 1) + ': ' + e.message);
                return;
            }
        }
    }

    _load() {
        this._readJournal(this.eventsFile, (data) => {
            if (!Number.isInteger(data.seq) || data.seq < 1) throw new Error('invalid event sequence');
            const event = validateEvent(data.event);
            if (this.events.has(event.id)) throw new Error('duplicate event id in journal');
            this.events.set(event.id, event);
            this.outbox.set(event.id, { state: 'queued', attempts: 0, updated_at_ms: event.at_ms });
            this.sequence = Math.max(this.sequence, data.seq);
        });
        if (!this.readOnly) this._readJournal(this.outboxFile, (data) => {
            if (!this.events.has(data.id) || ['queued', 'sent'].indexOf(data.state) === -1 ||
                !Number.isInteger(data.at_ms) || !Number.isInteger(data.attempts) || data.attempts < 0) {
                throw new Error('invalid outbox record');
            }
            this.outbox.set(data.id, { state: data.state, attempts: data.attempts, updated_at_ms: data.at_ms });
        });
    }

    _append(file, data) {
        if (this.readOnly) throw new StoreReadOnlyError('local core store is read-only after corruption');
        const line = JSON.stringify({ v: 1, data: data, checksum: digest(data) }) + '\n';
        let fd;
        try {
            fd = fs.openSync(file, fs.existsSync(file) ? 'a' : 'ax', 0o600);
            fs.writeSync(fd, line, null, 'utf8');
            fs.fsyncSync(fd);
        } catch (e) {
            // An uncertain write must never continue accepting mutations.
            this.readOnly = true;
            this.corruption = { file: path.basename(file), reason: 'append failed: ' + e.message, at_ms: Date.now() };
            throw new StoreReadOnlyError(this.corruption.reason);
        } finally {
            if (fd !== undefined) try { fs.closeSync(fd); } catch (e) {}
        }
    }

    append(event) {
        if (this.readOnly) throw new StoreReadOnlyError('local core store is read-only after corruption');
        const safe = validateEvent(event);
        if (this.events.has(safe.id)) return { duplicate: true, event: clone(this.events.get(safe.id)) };
        const data = { seq: ++this.sequence, event: safe };
        this._append(this.eventsFile, data);
        this.events.set(safe.id, safe);
        this.outbox.set(safe.id, { state: 'queued', attempts: 0, updated_at_ms: Date.now() });
        // A crash before this second append is safe: replay defaults every event
        // to queued, so a durable event can never be silently lost.
        this._append(this.outboxFile, { id: safe.id, state: 'queued', attempts: 0, at_ms: Date.now() });
        return { duplicate: false, event: clone(safe) };
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
        if (this.readOnly) throw new StoreReadOnlyError('local core store is read-only after corruption');
        const wanted = new Set((ids || []).map(String));
        let changed = 0;
        for (const id of wanted) {
            const prior = this.outbox.get(id);
            if (!prior || prior.state === 'sent') continue;
            const next = { state: 'sent', attempts: prior.attempts + 1, updated_at_ms: Date.now() };
            this._append(this.outboxFile, { id: id, state: next.state, attempts: next.attempts, at_ms: next.updated_at_ms });
            this.outbox.set(id, next);
            changed++;
        }
        return changed;
    }

    noteAttempt(ids) {
        if (this.readOnly) return;
        for (const id of new Set((ids || []).map(String))) {
            const prior = this.outbox.get(id);
            if (!prior || prior.state !== 'queued') continue;
            const next = { state: 'queued', attempts: prior.attempts + 1, updated_at_ms: Date.now() };
            this._append(this.outboxFile, { id: id, state: next.state, attempts: next.attempts, at_ms: next.updated_at_ms });
            this.outbox.set(id, next);
        }
    }

    status() {
        let pending = 0;
        this.outbox.forEach((v) => { if (v.state === 'queued') pending++; });
        return { enabled: true, read_only: this.readOnly, event_count: this.events.size, pending_count: pending, corruption: this.corruption ? clone(this.corruption) : null };
    }
}

module.exports = { EventStore, StoreReadOnlyError };