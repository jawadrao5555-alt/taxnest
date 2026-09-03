'use strict';

// The operational state and its immutable event stream share one authenticated,
// atomically-replaced file. Thus no observable state can contain a projection
// without its event (or a high-water mark without both).
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const { validateCommand, validateDomainEvent, EVENT_SCHEMA_VERSION, EVENT_SCHEMAS } = require('./schemas');
const { validateEvent } = require('./protocol');
const { EventStore } = require('./event-store');
const { validateAuthority, signWireEvent } = require('./lease-chain');

const MAGIC = Buffer.from('PRADOM1');
const FILE = 'domain-state.bin';
const TXN_FILE = 'domain-transaction.bin';
const MAX_BYTES = 128 * 1024 * 1024;

function clone(value) { return JSON.parse(JSON.stringify(value)); }
function commandDigest(command) {
    // at_ms is injected by trusted Main on every IPC attempt. It is deliberately
    // excluded so a byte-identical browser command ID/payload remains idempotent
    // across process restarts instead of conflicting solely because wall time moved.
    const semantic = Object.assign({}, command);
    delete semantic.at_ms;
    return crypto.createHash('sha256').update(JSON.stringify(semantic)).digest('hex');
}
function fail(code, message) { const e = new Error(message); e.code = code; throw e; }
function cents(value, name) {
    if (!Number.isInteger(value) || value < 0 || value > 99999999999) fail('invalid_money', name + ' must be non-negative integer cents');
    return value;
}
function quantity(value, name) {
    if (!Number.isFinite(value) || value <= 0 || value > 1000000000) fail('invalid_quantity', name + ' is invalid');
    return value;
}
function emptyState(scope, authority) {
    const rootScope = { company_id: scope.company_id, branch_id: scope.branch_id };
    return {
        v: 1, scope: rootScope, sequence: 0, revisions: {}, command_results: {}, events: [],
        catalog: null, orders: {}, sales: {}, tables: {}, stock: {}, recipes: {}, customers: {},
        cash_days: {}, staff_sessions: {}, print_queue: {}, identities: {}, settings: {},
        bootstrap: null, actors: {},
    };
}
function sameAuthority(a, b) {
    return String(a.company_id) === String(b.company_id) && String(a.branch_id) === String(b.branch_id);
}
function actorKey(scope) { return String(scope.device_id) + ':' + String(scope.user_id); }
function writeAll(fd, bytes) {
    let offset = 0;
    while (offset < bytes.length) {
        const written = fs.writeSync(fd, bytes, offset, bytes.length - offset);
        if (!written) throw new Error('short state write');
        offset += written;
    }
}

class LocalCoreDomain {
    constructor(options) {
        const opts = options || {};
        if (!Buffer.isBuffer(opts.encryptionKey) || opts.encryptionKey.length !== 32) {
            throw new Error('encryptionKey must be an explicit 32-byte Buffer');
        }
        this.key = Buffer.from(opts.encryptionKey);
        this.keyId = String(opts.encryptionKeyId || crypto.createHash('sha256').update(this.key).digest('hex').slice(0, 16));
        this.dir = opts.dataDir || path.join(process.cwd(), 'local-core');
        this.file = path.join(this.dir, FILE);
        this.transactionFile = path.join(this.dir, TXN_FILE);
        this.lockFile = path.join(this.dir, '.domain.lock');
        this.scope = opts.authorityScope ? clone(opts.authorityScope) : null;
        this.fault = typeof opts.fault === 'function' ? opts.fault : null;
        this.isTrustedOwner = typeof opts.isTrustedOwner === 'function' ? opts.isTrustedOwner : () => false;
        // The session snapshot prevents a command from gaining permissions it
        // did not have at shift start; the injected current-authority check
        // makes revocation effective immediately when available.
        this.permissionProvider = typeof opts.permissionProvider === 'function' ? opts.permissionProvider : null;
        this.allowAuthorityRotation = opts.allowAuthorityRotation === true;
        this.now = typeof opts.now === 'function' ? opts.now : Date.now;
        const explicitRoot = opts.rootIdentity || opts.eventIdPrefix;
        this.eventIdPrefix = typeof explicitRoot === 'string' &&
            /^[a-z0-9-]{1,48}$/.test(explicitRoot) ? explicitRoot :
            'core-' + crypto.createHash('sha256').update([
                this.scope && this.scope.company_id, this.scope && this.scope.branch_id, this.keyId,
            ].join('|')).digest('hex').slice(0, 24);
        this.authorities = new Map();
        const suppliedAuthority = opts.authority || opts.scopeLease;
        this.authority = suppliedAuthority ? validateAuthority({
            lease_id: suppliedAuthority.lease_id || suppliedAuthority.id,
            signing_secret: suppliedAuthority.signing_secret,
            next_sequence: suppliedAuthority.next_sequence || (suppliedAuthority.chain && suppliedAuthority.chain.next_sequence),
            prev_hash: suppliedAuthority.prev_hash || (suppliedAuthority.chain && suppliedAuthority.chain.prev_hash),
            allowed_actions: suppliedAuthority.allowed_actions || suppliedAuthority.permissions,
            expires_at_ms: Number.isInteger(suppliedAuthority.expires_at_ms)
                ? suppliedAuthority.expires_at_ms
                : (typeof suppliedAuthority.expires_at === 'string' && Number.isFinite(Date.parse(suppliedAuthority.expires_at))
                    ? Date.parse(suppliedAuthority.expires_at) : undefined),
            scope: suppliedAuthority.scope,
            token: suppliedAuthority.token,
            owner: suppliedAuthority.owner === true,
            staff_session_id: suppliedAuthority.staff_session_id,
            role: suppliedAuthority.role || null,
            permissions: suppliedAuthority.permissions || suppliedAuthority.allowed_actions,
        }, this.scope) : null;
        if (this.authority) this.authorities.set(actorKey(this.authority.scope), this.authority);
        this.eventStore = opts.eventStore || new EventStore({
            dataDir: this.dir, encryptionKey: this.key, encryptionKeyId: this.keyId,
            authorityScope: null,
        });
        if (!this.eventStore || typeof this.eventStore.append !== 'function') throw new Error('EventStore is required');
        // Domain is the company/branch authority; EventStore's older
        // single-device gate would incorrectly create a waiter-specific store.
        this.eventStore.authorityScope = null;
        fs.mkdirSync(this.dir, { recursive: true, mode: 0o700 });
        this.state = this._read();
        // A first command can be interrupted after the authenticated marker
        // exists but before the inaugural state file is installed.
        if (!this.state && !this.scope) {
            const pending = this._readEncrypted(this.transactionFile);
            if (pending && pending.state && pending.state.scope) this.scope = clone(pending.state.scope);
        }
        if (!this.state) {
            if (!this.scope) throw new Error('authorityScope is required for a new domain store');
            this.state = emptyState(this.scope, this.authority);
        } else if (this.scope && !sameAuthority(this.state.scope, this.scope)) {
            fail('scope_mismatch', 'domain store authority scope does not match');
        } else {
            this.scope = clone(this.state.scope);
        }
        this.state.actors = this.state.actors || {};
        this.state.sales = this.state.sales || {};
        if (!this.state.root_identity) {
            const priorId = this.state.events[0] && this.state.events[0].id;
            const priorRoot = typeof priorId === 'string' ? priorId.match(/^([a-z0-9-]{1,48})-\d{12}$/) : null;
            this.state.root_identity = priorRoot ? priorRoot[1] : this.eventIdPrefix;
        }
        this.eventIdPrefix = this.state.root_identity;
        if (this.authority && this.state.lease_chain &&
            this.state.lease_chain.lease_id === this.authority.lease_id &&
            !this.state.actors[actorKey(this.authority.scope)]) {
            this.authority.next_sequence = this.state.lease_chain.next_sequence;
            this.authority.prev_hash = this.state.lease_chain.prev_hash;
        }
        if (this.authority) this._installActorInMemory(this.state, this.authority, this.allowAuthorityRotation);
        this._recoverTransaction();
    }

    _encode(state) {
        const plaintext = Buffer.from(JSON.stringify(state));
        if (plaintext.length > MAX_BYTES) fail('storage_full', 'domain state exceeds size limit');
        const nonce = crypto.randomBytes(12);
        const id = Buffer.from(this.keyId);
        if (!id.length || id.length > 64) throw new Error('invalid encryption key id');
        const header = Buffer.concat([MAGIC, Buffer.from([1, id.length]), id, nonce]);
        const cipher = crypto.createCipheriv('aes-256-gcm', this.key, nonce);
        cipher.setAAD(header);
        return Buffer.concat([header, cipher.update(plaintext), cipher.final(), cipher.getAuthTag()]);
    }

    _read() {
        if (!fs.existsSync(this.file)) return null;
        const all = fs.readFileSync(this.file);
        // Backups represent an absent optional state file as a zero-length
        // entry. It is equivalent to absence, never to an unauthenticated state.
        if (!all.length) return null;
        if (all.length < MAGIC.length + 2 + 12 + 16 || !all.subarray(0, MAGIC.length).equals(MAGIC)) {
            fail('state_corrupt', 'invalid encrypted domain state');
        }
        const idLength = all[MAGIC.length + 1];
        const headerLength = MAGIC.length + 2 + idLength + 12;
        if (!idLength || idLength > 64 || all.length < headerLength + 16) fail('state_corrupt', 'invalid domain state header');
        if (all.subarray(MAGIC.length + 2, MAGIC.length + 2 + idLength).toString() !== this.keyId) {
            fail('state_key_mismatch', 'domain state encryption key does not match');
        }
        try {
            const decipher = crypto.createDecipheriv('aes-256-gcm', this.key, all.subarray(headerLength - 12, headerLength));
            decipher.setAAD(all.subarray(0, headerLength));
            decipher.setAuthTag(all.subarray(all.length - 16));
            const value = JSON.parse(Buffer.concat([
                decipher.update(all.subarray(headerLength, all.length - 16)), decipher.final(),
            ]).toString());
            this._validateState(value);
            return value;
        } catch (e) {
            if (e.code) throw e;
            fail('state_corrupt', 'domain state authentication failed');
        }
    }

    _readEncrypted(file) {
        if (!fs.existsSync(file)) return null;
        const all = fs.readFileSync(file);
        if (!all.length) return null;
        if (all.length < MAGIC.length + 2 + 12 + 16 || !all.subarray(0, MAGIC.length).equals(MAGIC)) fail('state_corrupt', 'invalid encrypted domain transaction');
        const idLength = all[MAGIC.length + 1];
        const headerLength = MAGIC.length + 2 + idLength + 12;
        if (!idLength || idLength > 64 || all.length < headerLength + 16 ||
            all.subarray(MAGIC.length + 2, MAGIC.length + 2 + idLength).toString() !== this.keyId) fail('state_corrupt', 'invalid domain transaction header');
        try {
            const decipher = crypto.createDecipheriv('aes-256-gcm', this.key, all.subarray(headerLength - 12, headerLength));
            decipher.setAAD(all.subarray(0, headerLength)); decipher.setAuthTag(all.subarray(all.length - 16));
            return JSON.parse(Buffer.concat([decipher.update(all.subarray(headerLength, all.length - 16)), decipher.final()]).toString());
        } catch (e) { fail('state_corrupt', 'domain transaction authentication failed'); }
    }

    _writeEncrypted(file, value) {
        const temp = file + '.tmp-' + process.pid + '-' + crypto.randomBytes(4).toString('hex');
        let fd;
        try {
            fd = fs.openSync(temp, 'wx', 0o600); writeAll(fd, this._encode(value)); fs.fsyncSync(fd);
            fs.closeSync(fd); fd = undefined; fs.renameSync(temp, file);
            try { const d = fs.openSync(this.dir, 'r'); fs.fsyncSync(d); fs.closeSync(d); } catch (e) {}
        } finally { if (fd !== undefined) try { fs.closeSync(fd); } catch (e) {} try { fs.unlinkSync(temp); } catch (e) {} }
    }

    _recoverTransaction() {
        const transaction = this._readEncrypted(this.transactionFile);
        if (!transaction) return;
        if (!transaction || ![1, 2].includes(transaction.v) || !transaction.state ||
            (transaction.v === 1 && !transaction.event)) fail('state_corrupt', 'domain transaction is invalid');
        this._validateState(transaction.state);
        if (transaction.event) {
            validateEvent(transaction.event);
            this.eventStore.append(transaction.event); // append is idempotent after an interrupted append.
        }
        this.eventStore.setGeneration(transaction.state.generation || 0);
        this._persist(transaction.state);
        fs.unlinkSync(this.transactionFile);
        this.state = transaction.state;
    }

    _validateState(value) {
        if (!value || value.v !== 1 || !Number.isInteger(value.sequence) || !Array.isArray(value.events) ||
            value.events.length !== value.sequence || !value.revisions || !value.command_results ||
            (value.generation != null && (!Number.isInteger(value.generation) || value.generation < 0)) ||
            (value.root_identity != null && !/^[a-z0-9-]{1,48}$/.test(value.root_identity))) {
            fail('state_corrupt', 'domain state structure is invalid');
        }
        value.events.forEach(validateDomainEvent);
        for (let i = 0; i < value.events.length; i++) {
            if (value.events[i].sequence !== i + 1) fail('state_corrupt', 'domain event sequence is not contiguous');
        }
        for (const actor of Object.values(value.actors || {})) {
            if (!actor || !actor.scope || !sameAuthority(actor.scope, value.scope) || !actor.chain ||
                !Number.isInteger(actor.chain.lease_id) || !Number.isInteger(actor.chain.next_sequence) ||
                actor.chain.next_sequence < 1 || !/^[a-f0-9]{64}$/.test(actor.chain.prev_hash)) {
                fail('state_corrupt', 'domain actor session is invalid');
            }
        }
    }

    _persist(next) {
        const temp = this.file + '.tmp-' + process.pid + '-' + crypto.randomBytes(4).toString('hex');
        let fd;
        try {
            fd = fs.openSync(temp, 'wx', 0o600);
            writeAll(fd, this._encode(next));
            fs.fsyncSync(fd); fs.closeSync(fd); fd = undefined;
            if (this.fault) this.fault('before_commit');
            fs.renameSync(temp, this.file);
            try { const d = fs.openSync(this.dir, 'r'); fs.fsyncSync(d); fs.closeSync(d); } catch (e) {}
        } finally {
            if (fd !== undefined) try { fs.closeSync(fd); } catch (e) {}
            try { fs.unlinkSync(temp); } catch (e) {}
        }
    }

    _locked(fn) {
        return this.eventStore.withSnapshotBarrier(() => this._domainLocked(fn));
    }

    _domainLocked(fn) {
        let fd;
        try {
            fd = fs.openSync(this.lockFile, 'wx', 0o600);
        } catch (e) {
            if (e.code === 'EEXIST') fail('concurrent_mutation', 'another domain mutation is in progress');
            throw e;
        }
        try {
            // Reload under the process lock so two engine instances cannot both win.
            const disk = this._read();
            if (disk) this.state = disk;
            return fn();
        } finally {
            if (fd !== undefined) fs.closeSync(fd);
            try { fs.unlinkSync(this.lockFile); } catch (e) {}
        }
    }

    execute(input) {
        const command = validateCommand(input);
        if (!sameAuthority(command.scope, this.scope)) fail('scope_mismatch', 'command is outside this store scope');
        return this._locked(() => {
            const prior = this.state.command_results[command.id];
            if (prior) {
                if (prior.digest !== commandDigest(command)) {
                    fail('idempotency_conflict', 'command id was reused with different content');
                }
                return Object.assign({ duplicate: true }, clone(prior.result));
            }
            const current = this.state.revisions[command.aggregate_id] || 0;
            if (current !== command.expected_revision) fail('revision_conflict', 'aggregate revision does not match');
            this._authorize(command);
            const next = clone(this.state);
            const result = this._project(next, command);
            const revision = current + 1;
            const sequence = next.sequence + 1;
            next.generation = (this.state.generation || 0) + 1;
            const signed = this._canonicalEvent(command, sequence, revision);
            const canonical = signed.event;
            const event = validateDomainEvent({
                schema_v: EVENT_SCHEMA_VERSION, id: canonical.id,
                command_id: command.id, type: canonical.type, aggregate_id: command.aggregate_id,
                revision, sequence, at_ms: command.at_ms, scope: command.scope,
                payload: clone(canonical.payload),
            });
            next.sequence = sequence;
            next.revisions[command.aggregate_id] = revision;
            next.events.push(event);
            const actor = next.actors[actorKey(command.scope)];
            actor.chain = { lease_id: canonical.lease_chain.lease_id,
                next_sequence: canonical.lease_chain.sequence + 1, prev_hash: signed.chain_hash };
            const response = { event, result: result || null };
            next.command_results[command.id] = {
                digest: commandDigest(command),
                result: response,
            };
            // The marker is encrypted and fsynced before either participant.
            // Recovery appends/reuses this exact event, commits this exact
            // projection, then removes the marker. No caller observes success
            // before all three operations have completed.
            const transaction = { v: 1, state: next, event: canonical };
            this._writeEncrypted(this.transactionFile, transaction);
            if (this.fault) this.fault('after_marker');
            this.eventStore.append(canonical);
            if (this.fault) this.fault('after_event_append');
            this.eventStore.setGeneration(next.generation);
            if (this.fault) this.fault('after_generation');
            this._persist(next);
            if (this.fault) this.fault('after_state_commit');
            fs.unlinkSync(this.transactionFile);
            this.state = next;
            return Object.assign({ duplicate: false }, clone(response));
        });
    }

    _installActorInMemory(state, authority, allowRotation) {
        if (!sameAuthority(state.scope, authority.scope)) fail('scope_mismatch', 'actor is outside branch authority');
        const key = actorKey(authority.scope);
        const existing = state.actors[key];
        if (existing && existing.revoked_at_ms) fail('actor_revoked', 'actor session is revoked');
        if (existing && existing.chain.lease_id !== authority.lease_id && !allowRotation) {
            fail('scope_lease_invalid', 'actor lease cannot replace active chain');
        }
        if (existing && existing.chain.lease_id === authority.lease_id) {
            authority.next_sequence = existing.chain.next_sequence;
            authority.prev_hash = existing.chain.prev_hash;
            return;
        }
        state.actors[key] = {
            scope: clone(authority.scope), role: authority.role || null,
            permissions: clone(authority.permissions || authority.allowed_actions),
            allowed_actions: clone(authority.allowed_actions), owner: authority.owner === true,
            staff_session_id: authority.staff_session_id || null, registered_at_ms: this.now(),
            revoked_at_ms: null, chain: { lease_id: authority.lease_id,
                next_sequence: authority.next_sequence, prev_hash: authority.prev_hash },
        };
    }

    registerActorSession(input) {
        const authority = validateAuthority(input, input && input.scope);
        if (!sameAuthority(authority.scope, this.scope)) fail('scope_mismatch', 'actor is outside branch authority');
        return this._locked(() => {
            const next = clone(this.state);
            this._installActorInMemory(next, authority, input && input.allow_rotation === true);
            next.generation = (next.generation || 0) + 1;
            this._commitStateOnly(next, 'actor-register');
            this.authorities.set(actorKey(authority.scope), authority);
            return clone(next.actors[actorKey(authority.scope)]);
        });
    }

    revokeActorSession(scope) {
        if (!sameAuthority(scope || {}, this.scope)) fail('scope_mismatch', 'actor is outside branch authority');
        return this._locked(() => {
            const next = clone(this.state); const key = actorKey(scope);
            if (!next.actors[key]) fail('not_found', 'actor session does not exist');
            next.actors[key].revoked_at_ms = this.now();
            next.generation = (next.generation || 0) + 1;
            this._commitStateOnly(next, 'actor-revoke');
            this.authorities.delete(key);
            return true;
        });
    }

    _commitStateOnly(next, kind) {
        const transaction = { v: 2, kind, state: next };
        this._writeEncrypted(this.transactionFile, transaction);
        this.eventStore.setGeneration(next.generation);
        this._persist(next); fs.unlinkSync(this.transactionFile); this.state = next;
    }

    /**
     * Installs an authenticated cloud baseline under the same journal/domain
     * generation barrier used by commands. Pending local aggregates are copied
     * over the baseline, so bootstrap can never erase work that has not ACKed.
     */
    importSnapshot(snapshot) {
        if (!snapshot || snapshot.schema !== 'local-core.snapshot.v1' ||
            !Number.isInteger(snapshot.revision) || snapshot.revision < 1 ||
            !snapshot.scope || !sameAuthority(snapshot.scope, this.scope) ||
            !snapshot.payload || typeof snapshot.payload !== 'object' || Array.isArray(snapshot.payload) ||
            typeof snapshot.hash !== 'string' || !/^[a-f0-9]{64}$/.test(snapshot.hash)) {
            fail('snapshot_invalid', 'snapshot structure or scope is invalid');
        }
        const fields = ['orders', 'sales', 'tables', 'stock', 'recipes', 'customers', 'cash_days', 'staff_sessions', 'settings'];
        for (const field of fields) {
            const value = snapshot.payload[field];
            if (value != null && (!value || typeof value !== 'object' || Array.isArray(value))) {
                fail('snapshot_invalid', field + ' must be an object');
            }
        }
        return this._locked(() => {
            const prior = this.state.bootstrap;
            if (prior && snapshot.revision < prior.revision) fail('stale_snapshot', 'snapshot is older than installed baseline');
            if (prior && snapshot.revision === prior.revision) {
                if (prior.hash !== snapshot.hash) fail('snapshot_revision_conflict', 'snapshot revision hash changed');
                return { imported: false, duplicate: true, revision: prior.revision, hash: prior.hash };
            }

            const next = clone(this.state);
            next.catalog = clone(snapshot.payload.catalog || null);
            for (const field of fields) next[field] = clone(snapshot.payload[field] || {});

            const pendingIds = new Set(this.eventStore.pending(Number.MAX_SAFE_INTEGER).map((event) => event.id));
            for (const event of this.state.events) {
                if (!pendingIds.has(event.id)) continue;
                const command = event.payload && event.payload.command_type;
                const id = String(event.aggregate_id);
                let field = null;
                if (command && command.startsWith('order.')) field = 'orders';
                else if (command && command.startsWith('table.')) field = 'tables';
                else if (command && command.startsWith('stock.')) field = 'stock';
                else if (command === 'recipe.set') field = 'recipes';
                else if (['customer.upsert', 'khata.debit', 'wasooli.record'].includes(command)) field = 'customers';
                else if (command && command.startsWith('staff.')) field = 'staff_sessions';
                else if (command && command.startsWith('print.')) field = 'print_queue';
                if (field) {
                    if (Object.prototype.hasOwnProperty.call(this.state[field], id)) next[field][id] = clone(this.state[field][id]);
                    else delete next[field][id];
                }
                if (command === 'order.settle' && Object.prototype.hasOwnProperty.call(this.state.sales || {}, id)) {
                    next.sales[id] = clone(this.state.sales[id]);
                }
                // A held order's branch stock reservation and optional table
                // claim are one projection. Until its event is ACKed, a cloud
                // refresh must preserve all three rather than only the order.
                if (['order.hold', 'order.cancel'].includes(command)) {
                    next.stock = clone(this.state.stock);
                    next.tables = clone(this.state.tables);
                }
                if (command && (command.startsWith('cash.') || command === 'refund.record')) {
                    next.cash_days = clone(this.state.cash_days);
                }
            }
            next.events = clone(this.state.events);
            next.sequence = this.state.sequence;
            next.revisions = clone(this.state.revisions);
            next.command_results = clone(this.state.command_results);
            next.identities = clone(this.state.identities);
            next.print_queue = next.print_queue || clone(this.state.print_queue);
            next.actors = clone(this.state.actors);
            next.root_identity = this.state.root_identity;
            next.generation = (this.state.generation || 0) + 1;
            next.bootstrap = { revision: snapshot.revision, hash: snapshot.hash,
                imported_at_ms: this.now(), mode: 'full-refresh-merge' };
            this._validateState(next);

            const transaction = { v: 2, kind: 'snapshot', state: next };
            this._writeEncrypted(this.transactionFile, transaction);
            if (this.fault) this.fault('after_snapshot_marker');
            this.eventStore.setGeneration(next.generation);
            if (this.fault) this.fault('after_snapshot_generation');
            this._persist(next);
            if (this.fault) this.fault('after_snapshot_commit');
            fs.unlinkSync(this.transactionFile);
            this.state = next;
            return { imported: true, duplicate: false, revision: snapshot.revision,
                hash: snapshot.hash, pending_preserved: pendingIds.size };
        });
    }

    _canonicalEvent(command, sequence, revision) {
        const types = {
            'order.hold': 'order.held',
            'order.claim': 'order.updated', 'order.cancel': 'order.cancelled', 'order.settle': 'order.settled',
            'table.claim': 'order.updated', 'table.release': 'order.updated', 'table.shift': 'order.updated',
            'stock.set': 'stock.adjusted', 'stock.adjust': 'stock.adjusted',
            'customer.upsert': 'customer.ledger.posted', 'khata.debit': 'customer.khata.posted',
            'wasooli.record': 'customer.wasooli.posted', 'refund.record': 'customer.refund.posted',
            'cash.open': 'cash.opened', 'cash.expense': 'expense.created', 'cash.close': 'day-close.created',
            'staff.start': 'staff.shift.recorded', 'staff.end': 'staff.shift.recorded',
            'print.enqueue': 'print.requested', 'print.claim': 'print.requested',
            'print.complete': 'print.completed', 'print.fail': 'print.requested',
        };
        const type = types[command.type];
        if (!type) fail('invalid_command_type', 'command has no canonical event');
        const lease = this._lease(command.scope);
        const payload = { schema: EVENT_SCHEMAS[type], command_type: command.type,
            aggregate_id: command.aggregate_id, aggregate_revision: revision, data: clone(command.payload) };
        const id = this.eventIdPrefix + '-' + String(sequence).padStart(12, '0');
        const idempotency = 'domain-command:' + command.id;
        const wire = { event_id: id, event_type: type, occurred_at: new Date(command.at_ms).toISOString(),
            idempotency_key: idempotency, scope: command.scope, payload };
        const signed = signWireEvent(wire, lease);
        return { chain_hash: signed.chain_hash, event: validateEvent({
            v: 1, id, idempotency_key: idempotency, scope: command.scope, type, at_ms: command.at_ms,
            scope_lease_id: lease.lease_id, scope_lease: lease.token || 'signed-chain-v1',
            lease_chain: { lease_id: lease.lease_id, sequence: signed.sequence,
                prev_hash: signed.prev_hash, signature: signed.signature },
            payload,
        }) };
    }

    _authorize(command) {
        const lease = this._lease(command.scope);
        const actor = this.state.actors[actorKey(command.scope)];
        if (!actor || actor.revoked_at_ms) fail('actor_session_required', 'active actor session is required');
        if (!sameAuthority(lease.scope, command.scope) ||
            (Number.isInteger(lease.expires_at_ms) && this.now() > lease.expires_at_ms)) {
            fail('scope_lease_invalid', 'trusted scope lease does not authorize this command');
        }
        const leasePermissions = lease.allowed_actions;
        if (!leasePermissions.includes('*') && !leasePermissions.includes(command.type)) fail('permission_denied', 'scope lease denies this command');
        if (actor.owner === true || this.isTrustedOwner(clone(command.scope), command) === true) return;
        const permissions = Array.isArray(actor.permissions) ? actor.permissions : [];
        if (!permissions.includes('*') && !permissions.includes(command.type)) fail('permission_denied', 'permission snapshot denies this command');
        if (this.permissionProvider && this.permissionProvider(clone(command.scope), command.type, clone(actor)) !== true) {
            fail('permission_revoked', 'current permission authority denies this command');
        }
    }

    _lease(scope) {
        const authority = this.authorities.get(actorKey(scope));
        const actor = this.state && this.state.actors && this.state.actors[actorKey(scope)];
        if (authority && actor && !actor.revoked_at_ms) {
            authority.next_sequence = actor.chain.next_sequence;
            authority.prev_hash = actor.chain.prev_hash;
            return authority;
        }
        fail('scope_lease_required', 'a trusted scope lease is required');
    }

    _project(s, c) {
        const p = c.payload;
        const plainObject = (value) => !!value && typeof value === 'object' && !Array.isArray(value);
        const entityId = (value) => String(value);
        const catalogEntities = (name) => {
            if (!s.catalog) return [];
            const values = s.catalog[name];
            if (Array.isArray(values)) return values;
            if (plainObject(values)) return Object.values(values);
            return [];
        };
        const findEntity = (name, id) => catalogEntities(name).find((entry) =>
            entry && entityId(entry.id == null ? entry[`${name.slice(0, -1)}_id`] : entry.id) === entityId(id));
        const validateRevision = (expected, entity, name) => {
            if (expected == null) return;
            if ((!Number.isInteger(expected) || expected < 0) &&
                (typeof expected !== 'string' || !expected.length)) {
                fail('invalid_revision', `${name} revision is invalid`);
            }
            const actual = entity && (entity.revision == null
                ? (entity.version == null ? entity.updated_at : entity.version) : entity.revision);
            if (actual == null || String(actual) !== String(expected)) {
                fail('revision_conflict', `${name} revision does not match`);
            }
        };
        const stockQuantity = (id) => {
            const value = s.stock[entityId(id)];
            return plainObject(value) ? Number(value.quantity == null ? value.current_stock : value.quantity) : Number(value);
        };
        const setStockQuantity = (id, value) => {
            const key = entityId(id);
            if (plainObject(s.stock[key])) {
                if (Object.prototype.hasOwnProperty.call(s.stock[key], 'quantity')) s.stock[key].quantity = value;
                else s.stock[key].current_stock = value;
            } else s.stock[key] = value;
        };
        const validateConsumption = (part, multiplier, reserved) => {
            if (!plainObject(part) || part.stock_id == null) fail('invalid_order_snapshot', 'consumption stock id is required');
            quantity(part.quantity, 'consumption quantity');
            const key = entityId(part.stock_id);
            if (!Object.prototype.hasOwnProperty.call(s.stock, key) || !Number.isFinite(stockQuantity(key))) {
                fail('ingredient_not_found', 'consumption ingredient does not exist');
            }
            const ingredient = findEntity('ingredients', key);
            if (!ingredient) fail('ingredient_not_found', 'consumption ingredient is not in catalog');
            if (ingredient.active === false || ingredient.is_active === false || ingredient.deleted_at) {
                fail('ingredient_unavailable', 'consumption ingredient is unavailable');
            }
            validateRevision(part.ingredient_revision == null ? part.stock_revision : part.ingredient_revision,
                ingredient || (plainObject(s.stock[key]) ? s.stock[key] : null), 'ingredient');
            reserved[key] = (reserved[key] || 0) + part.quantity * multiplier;
        };
        const validateRecipe = (product, supplied, expectedRevision) => {
            let current = s.recipes[entityId(product.id)];
            if (current == null) current = product.recipe_snapshot == null ? product.recipe : product.recipe_snapshot;
            if (current == null) return;
            const parts = Array.isArray(current) ? current : current.parts;
            if (!Array.isArray(parts)) fail('invalid_recipe', 'current product recipe is invalid');
            validateRevision(expectedRevision, Array.isArray(current) ? product : current, 'recipe');
            const normalized = (values) => values.map((part) => ({
                stock_id: entityId(part.stock_id == null ? part.ingredient_id : part.stock_id),
                quantity: Number(part.quantity == null ? part.quantity_needed : part.quantity),
            })).sort((a, b) => a.stock_id.localeCompare(b.stock_id));
            if (JSON.stringify(normalized(parts)) !== JSON.stringify(normalized(supplied))) {
                fail('recipe_conflict', 'frozen recipe does not match current recipe');
            }
        };
        const getOrder = () => {
            const order = s.orders[c.aggregate_id];
            if (!order) fail('not_found', 'order does not exist');
            if (order.status !== 'open') fail('order_closed', 'order is immutable after settlement or cancellation');
            return order;
        };
        switch (c.type) {
        case 'catalog.replace':
            if (!Array.isArray(p.products)) fail('invalid_catalog', 'catalog products are required');
            s.catalog = { id: c.aggregate_id, captured_at_ms: c.at_ms, products: clone(p.products), taxes: clone(p.taxes || []) };
            return s.catalog;
        case 'order.hold': {
            if (s.orders[c.aggregate_id]) fail('already_exists', 'order already exists');
            const snapshot = p.order_snapshot;
            if (!plainObject(snapshot) || snapshot.order_id !== c.aggregate_id ||
                typeof snapshot.business_date !== 'string' || !snapshot.business_date ||
                typeof snapshot.order_type !== 'string' || !snapshot.order_type ||
                !Array.isArray(snapshot.lines) || !snapshot.lines.length ||
                !plainObject(snapshot.totals)) {
                fail('invalid_order_snapshot', 'complete immutable order snapshot is required');
            }
            if (s.cash_days[snapshot.business_date] && s.cash_days[snapshot.business_date].closed) {
                fail('business_day_closed', 'business date is closed');
            }
            cents(snapshot.totals.total_cents, 'total');
            cents(snapshot.totals.subtotal_cents == null ? snapshot.totals.total_cents : snapshot.totals.subtotal_cents, 'subtotal');
            cents(snapshot.totals.tax_cents || 0, 'tax');
            cents(snapshot.totals.discount_cents || 0, 'discount');
            validateRevision(snapshot.catalog_revision, s.catalog, 'catalog');
            const products = catalogEntities('products');
            if (!s.catalog || !products.length) fail('catalog_unavailable', 'a product catalog is required to hold an order');
            const reserved = {};
            const lineIds = new Set();
            let calculatedSubtotal = 0;
            snapshot.lines.forEach((line) => {
                if (!plainObject(line) || typeof line.line_id !== 'string' || !line.line_id ||
                    line.product_id == null || !plainObject(line.tax_snapshot) ||
                    !Array.isArray(line.recipe_snapshot) || !Array.isArray(line.deal_snapshot) ||
                    !Array.isArray(line.direct_consumption_snapshot)) {
                    fail('invalid_order_snapshot', 'order line snapshots are incomplete');
                }
                if (lineIds.has(line.line_id)) fail('invalid_order_snapshot', 'order line ids must be unique');
                lineIds.add(line.line_id);
                quantity(line.quantity, 'line quantity'); cents(line.unit_price_cents, 'line price');
                calculatedSubtotal += line.unit_price_cents * line.quantity;
                const product = findEntity('products', line.product_id);
                if (!product) fail('product_not_found', 'order product is not in catalog');
                if (product.active === false || product.is_active === false || product.deleted_at) {
                    fail('product_unavailable', 'order product is unavailable');
                }
                validateRevision(line.product_revision, product, 'product');
                validateRecipe(product, line.recipe_snapshot, line.recipe_revision);
                line.recipe_snapshot.forEach((part) => validateConsumption(part, line.quantity, reserved));
                line.direct_consumption_snapshot.forEach((part) => validateConsumption(part, line.quantity, reserved));
                (line.deal_snapshot || []).forEach((component) => {
                    if (!plainObject(component) || component.product_id == null) {
                        fail('invalid_order_snapshot', 'deal component snapshot is incomplete');
                    }
                    const componentProduct = findEntity('products', component.product_id);
                    if (!componentProduct) fail('product_not_found', 'deal component product is not in catalog');
                    if (componentProduct.active === false || componentProduct.is_active === false ||
                        componentProduct.deleted_at) fail('product_unavailable', 'deal component product is unavailable');
                    validateRevision(component.product_revision, componentProduct, 'deal product');
                    validateRecipe(componentProduct, component.recipe_snapshot || [], component.recipe_revision);
                    const componentQuantity = component.quantity == null ? component.qty : component.quantity;
                    quantity(componentQuantity, 'deal component quantity');
                    if (!Array.isArray(component.recipe_snapshot) ||
                        !Array.isArray(component.direct_consumption_snapshot || [])) {
                        fail('invalid_order_snapshot', 'deal consumption snapshots are incomplete');
                    }
                    component.recipe_snapshot.forEach((part) =>
                        validateConsumption(part, line.quantity * componentQuantity, reserved));
                    (component.direct_consumption_snapshot || []).forEach((part) =>
                        validateConsumption(part, line.quantity * componentQuantity, reserved));
                });
            });
            if (!Number.isInteger(calculatedSubtotal) ||
                calculatedSubtotal !== (snapshot.totals.subtotal_cents == null
                    ? snapshot.totals.total_cents : snapshot.totals.subtotal_cents)) {
                fail('invalid_order_totals', 'order subtotal does not match its lines');
            }
            const expectedTotal = calculatedSubtotal - (snapshot.totals.discount_cents || 0) +
                (snapshot.totals.tax_inclusive === true ? 0 : (snapshot.totals.tax_cents || 0));
            if (expectedTotal !== snapshot.totals.total_cents) {
                fail('invalid_order_totals', 'order total does not match tax and discount');
            }
            Object.entries(reserved).forEach(([id, used]) => {
                if (stockQuantity(id) < used) fail('insufficient_stock', 'insufficient ingredient stock');
            });
            const tableId = snapshot.table_id == null ? null : entityId(snapshot.table_id);
            if (tableId) {
                const tableCatalog = catalogEntities('tables');
                const table = findEntity('tables', tableId);
                if (!tableCatalog.length || !table) fail('table_not_found', 'table is not in catalog');
                if (table.active === false || table.is_active === false || table.deleted_at) {
                    fail('table_unavailable', 'table is unavailable');
                }
                validateRevision(snapshot.table_revision, table, 'table');
                if (s.tables[tableId]) fail('already_claimed', 'table already has an order');
            }
            Object.entries(reserved).forEach(([id, used]) => setStockQuantity(id, stockQuantity(id) - used));
            const immutable = clone(snapshot);
            immutable.lines.forEach((line) => { line.consumed = true; });
            s.orders[c.aggregate_id] = Object.assign({}, immutable, {
                id: c.aggregate_id, order_id: c.aggregate_id, status: 'open',
                table_id: tableId, reserved_consumptions: Object.entries(reserved)
                    .map(([stock_id, reserved_quantity]) => ({ stock_id, quantity: reserved_quantity })),
                reservation_restored: false, held_at_ms: c.at_ms, claimed_by: null,
            });
            if (tableId) {
                s.tables[tableId] = { order_id: c.aggregate_id, claimed_by: c.scope.device_id,
                    claimed_at_ms: c.at_ms, table_revision: snapshot.table_revision == null ? null : snapshot.table_revision };
            }
            return s.orders[c.aggregate_id];
        }
        case 'order.claim': {
            const o = getOrder();
            if (o.claimed_by && o.claimed_by !== c.scope.device_id) fail('already_claimed', 'order was claimed by another device');
            o.claimed_by = c.scope.device_id; o.claimed_at_ms = o.claimed_at_ms || c.at_ms; return o;
        }
        case 'order.cancel': {
            const o = getOrder();
            if (s.cash_days[o.business_date] && s.cash_days[o.business_date].closed) fail('business_day_closed', 'business date is closed');
            if (o.reservation_restored) fail('already_cancelled', 'order reservation was already restored');
            (o.reserved_consumptions || []).forEach((part) =>
                setStockQuantity(part.stock_id, stockQuantity(part.stock_id) + part.quantity));
            o.reservation_restored = true;
            o.lines.forEach((line) => { line.consumed = false; });
            if (o.table_id && s.tables[o.table_id] && s.tables[o.table_id].order_id === o.id) delete s.tables[o.table_id];
            o.status = 'cancelled'; return o;
        }
        case 'order.settle': {
            const o = getOrder();
            if (s.cash_days[o.business_date] && s.cash_days[o.business_date].closed) fail('business_day_closed', 'business date is closed');
            const sale = p.sale_snapshot;
            if (!sale || typeof sale !== 'object' || Array.isArray(sale) ||
                sale.order_id !== o.id || sale.business_date !== o.business_date ||
                !Array.isArray(sale.items) || !sale.items.length ||
                !sale.totals || typeof sale.totals !== 'object' ||
                !sale.payment || typeof sale.payment !== 'object') {
                fail('invalid_sale_snapshot', 'immutable full sale snapshot is required');
            }
            cents(sale.totals.total_cents, 'total'); cents(sale.totals.tax_cents || 0, 'tax');
            sale.items.forEach((line) => {
                quantity(line.quantity, 'sale line quantity');
                cents(line.unit_price_cents, 'sale line price');
                if (!line.tax_snapshot || typeof line.tax_snapshot !== 'object' ||
                    !Array.isArray(line.recipe_snapshot)) fail('invalid_sale_snapshot', 'sale line snapshots are incomplete');
            });
            o.sale_snapshot = clone(sale);
            o.totals = clone(sale.totals);
            o.payment_snapshot = clone(sale.payment);
            // The sale projection and restaurant order transition are changes
            // to the same cloned state committed by the single order.settled
            // marker/event/outbox transaction.
            if (s.sales[o.id]) fail('already_settled', 'sale projection already exists');
            s.sales[o.id] = { order_id: o.id, business_date: o.business_date,
                settled_at_ms: c.at_ms, snapshot: clone(sale) };
            o.status = 'settled'; o.settled_at_ms = c.at_ms; return o;
        }
        case 'table.claim': {
            const existing = s.tables[c.aggregate_id];
            if (existing && existing.order_id !== p.order_id) fail('already_claimed', 'table already has a different order');
            s.tables[c.aggregate_id] = { order_id: p.order_id, claimed_by: c.scope.device_id, claimed_at_ms: c.at_ms };
            return s.tables[c.aggregate_id];
        }
        case 'table.release':
            if (s.tables[c.aggregate_id] && p.order_id && s.tables[c.aggregate_id].order_id !== p.order_id) fail('claim_conflict', 'table claim does not match');
            delete s.tables[c.aggregate_id]; return null;
        case 'table.shift': {
            if (c.aggregate_id !== p.order_id) fail('invalid_command', 'table shift aggregate must be the order');
            const source = s.tables[p.source_table_id];
            if (!source || source.order_id !== p.order_id) fail('claim_conflict', 'source table claim does not match');
            if (s.tables[p.target_table_id]) fail('target_occupied', 'target table is not free');
            const order = s.orders[p.order_id];
            if (!order || order.status !== 'open') fail('not_found', 'open order does not exist');
            s.tables[p.target_table_id] = Object.assign({}, source, { shifted_at_ms: c.at_ms });
            delete s.tables[p.source_table_id];
            order.table_id = p.target_table_id;
            return { order_id: p.order_id, source_table_id: p.source_table_id, target_table_id: p.target_table_id };
        }
        case 'stock.set':
            s.stock[c.aggregate_id] = Number(p.quantity); if (!Number.isFinite(s.stock[c.aggregate_id]) || s.stock[c.aggregate_id] < 0) fail('invalid_quantity', 'stock is invalid');
            return { quantity: s.stock[c.aggregate_id] };
        case 'stock.adjust': {
            if (!Number.isFinite(p.delta)) fail('invalid_quantity', 'stock delta is invalid');
            const next = (s.stock[c.aggregate_id] || 0) + p.delta;
            if (next < 0) fail('insufficient_stock', 'stock cannot become negative');
            s.stock[c.aggregate_id] = next; return { quantity: next };
        }
        case 'recipe.set':
            if (!Array.isArray(p.parts)) fail('invalid_recipe', 'recipe parts are required');
            p.parts.forEach((x) => quantity(x.quantity, 'recipe quantity'));
            s.recipes[c.aggregate_id] = clone(p.parts); return s.recipes[c.aggregate_id];
        case 'customer.upsert': {
            const old = s.customers[c.aggregate_id];
            s.customers[c.aggregate_id] = Object.assign({}, old || { balance_cents: 0, ledger: [] }, clone(p), {
                balance_cents: old ? old.balance_cents : 0, ledger: old ? old.ledger : [],
            });
            return s.customers[c.aggregate_id];
        }
        case 'khata.debit': {
            if (p.business_date && s.cash_days[p.business_date] && s.cash_days[p.business_date].closed) fail('business_day_closed', 'business date is closed');
            const customer = s.customers[c.aggregate_id]; if (!customer) fail('not_found', 'customer does not exist');
            const amount = cents(p.amount_cents, 'amount'); if (!amount) fail('invalid_money', 'amount must be positive');
            customer.ledger.push({ type: 'debit', amount_cents: amount, reference: p.reference, at_ms: c.at_ms });
            customer.balance_cents += amount; return { balance_cents: customer.balance_cents };
        }
        case 'wasooli.record': {
            if (p.business_date && s.cash_days[p.business_date] && s.cash_days[p.business_date].closed) fail('business_day_closed', 'business date is closed');
            const customer = s.customers[c.aggregate_id]; if (!customer) fail('not_found', 'customer does not exist');
            const amount = cents(p.amount_cents, 'amount');
            if (!amount || amount > customer.balance_cents) fail('exceeds_outstanding', 'wasooli exceeds outstanding balance');
            customer.ledger.push({ type: 'wasooli', amount_cents: amount, reference: p.reference, at_ms: c.at_ms });
            customer.balance_cents -= amount; return { balance_cents: customer.balance_cents };
        }
        case 'refund.record': {
            const order = s.orders[p.order_id]; if (!order || order.status !== 'settled') fail('invalid_refund', 'settled original order is required');
            if (s.cash_days[order.business_date] && s.cash_days[order.business_date].closed) fail('business_day_closed', 'original business date is closed');
            const amount = cents(p.amount_cents, 'amount');
            order.refunded_cents = (order.refunded_cents || 0) + amount;
            if (order.refunded_cents > order.totals.total_cents) fail('refund_exceeded', 'refund exceeds original total');
            if (p.method === 'khata') {
                if (!order.payment_snapshot || order.payment_snapshot.method !== 'credit') {
                    fail('invalid_refund', 'khata refund requires a credit-paid original');
                }
                const customer = s.customers[p.customer_id];
                if (!customer || customer.balance_cents < amount) fail('invalid_refund', 'customer khata cannot be refunded');
                customer.ledger.push({ type: 'refund', amount_cents: amount, reference: p.order_id, at_ms: c.at_ms });
                customer.balance_cents -= amount;
            }
            (p.line_ids || []).forEach((id) => {
                const line = order.lines.find((x) => x.id === id);
                if (line && line.consumed && !line.restored) {
                    line.recipe_snapshot.forEach((part) => { s.stock[part.stock_id] = (s.stock[part.stock_id] || 0) + part.quantity * line.quantity; });
                    line.restored = true;
                }
            });
            return { refunded_cents: order.refunded_cents };
        }
        case 'cash.open':
            if (s.cash_days[p.business_date]) fail('already_exists', 'cash day already exists');
            s.cash_days[p.business_date] = { opening_cents: cents(p.opening_cents, 'opening'), expenses: [], closed: false };
            return s.cash_days[p.business_date];
        case 'cash.expense': {
            const day = s.cash_days[p.business_date]; if (!day) fail('not_found', 'cash day does not exist');
            if (day.closed) fail('business_day_closed', 'business date is closed');
            day.expenses.push({ id: c.aggregate_id, amount_cents: cents(p.amount_cents, 'amount'), note: p.note || '', at_ms: c.at_ms });
            return day.expenses[day.expenses.length - 1];
        }
        case 'cash.close': {
            const day = s.cash_days[p.business_date]; if (!day) fail('not_found', 'cash day does not exist');
            if (day.closed) fail('business_day_closed', 'business date is already closed');
            if (Object.values(s.orders).some((o) => o.business_date === p.business_date && o.status === 'open')) fail('open_orders', 'business date has open orders');
            day.closed = true; day.closed_at_ms = c.at_ms; day.counted_cents = cents(p.counted_cents, 'counted');
            return day;
        }
        case 'staff.start':
            if (s.staff_sessions[c.aggregate_id] && !s.staff_sessions[c.aggregate_id].ended_at_ms) fail('already_exists', 'staff session is already active');
            s.staff_sessions[c.aggregate_id] = { id: c.aggregate_id, user_id: p.user_id,
                permissions: clone(p.permissions), started_at_ms: c.at_ms }; return s.staff_sessions[c.aggregate_id];
        case 'staff.end': {
            const session = s.staff_sessions[c.aggregate_id]; if (!session || session.ended_at_ms) fail('not_found', 'active staff session does not exist');
            session.ended_at_ms = c.at_ms; return session;
        }
        case 'print.enqueue':
            if (s.print_queue[c.aggregate_id]) fail('already_exists', 'print job already exists');
            s.print_queue[c.aggregate_id] = { status: 'queued', document: clone(p.document), attempts: 0 }; return s.print_queue[c.aggregate_id];
        case 'print.claim': {
            const job = s.print_queue[c.aggregate_id]; if (!job || job.status !== 'queued') fail('claim_conflict', 'print job is not claimable');
            job.status = 'claimed'; job.claimed_by = c.scope.device_id; job.claim_token = p.claim_token; job.attempts++; return job;
        }
        case 'print.complete': case 'print.fail': {
            const job = s.print_queue[c.aggregate_id];
            if (!job || job.status !== 'claimed' || job.claim_token !== p.claim_token || job.claimed_by !== c.scope.device_id) {
                fail('claim_conflict', 'print job claim does not match');
            }
            job.status = c.type === 'print.complete' ? 'completed' : 'queued';
            job.completed_at_ms = c.type === 'print.complete' ? c.at_ms : null;
            delete job.claim_token; delete job.claimed_by; return job;
        }
        default: fail('invalid_command_type', 'unsupported command');
        }
    }

    snapshot() { return clone(this.state); }
    events(afterSequence, limit) {
        const after = Number.isInteger(afterSequence) ? afterSequence : 0;
        return clone(this.state.events.filter((e) => e.sequence > after).slice(0, Number.isInteger(limit) ? limit : 100));
    }
    nextIdentity(namespace) {
        if (typeof namespace !== 'string' || !namespace) fail('invalid_identity', 'identity namespace is required');
        return this._locked(() => {
            const next = clone(this.state);
            next.identities[namespace] = (next.identities[namespace] || 0) + 1;
            this._persist(next); this.state = next; return next.identities[namespace];
        });
    }
    close() { this.key.fill(0); }
}

module.exports = { LocalCoreDomain, DomainEngine: LocalCoreDomain, DOMAIN_STATE_FILE: FILE };