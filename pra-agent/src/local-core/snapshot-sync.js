'use strict';

const crypto = require('crypto');

function canonicalJson(value) {
    if (Array.isArray(value)) return '[' + value.map(canonicalJson).join(',') + ']';
    if (value && typeof value === 'object') {
        return '{' + Object.keys(value).sort().map((key) => JSON.stringify(key) + ':' + canonicalJson(value[key])).join(',') + '}';
    }
    return JSON.stringify(value);
}

// Transport boundary only: Local Core's established authority schema calls
// this field device_id, while the agent/server wire contract calls it
// device_uid. Never silently choose between two different identities.
function deviceUidFromScope(scope) {
    const uid = String(scope && scope.device_uid || '');
    const legacy = String(scope && scope.device_id || '');
    if (uid && legacy && uid !== legacy) {
        const error = new Error('scope device identity is inconsistent');
        error.code = 'scope_mismatch';
        throw error;
    }
    return uid || legacy;
}

class SnapshotSyncClient {
    constructor(options) {
        const opts = options || {};
        if (!opts.domain || typeof opts.domain.importSnapshot !== 'function') throw new Error('domain is required');
        if (typeof opts.request !== 'function') throw new Error('request is required');
        this.domain = opts.domain;
        this.request = opts.request;
        this.scopeProvider = opts.scopeProvider;
        this.leaseProvider = opts.leaseProvider;
        this.heartbeatProvider = opts.heartbeatProvider;
        this.deviceUid = String(opts.deviceUid || '');
        this.inFlight = null;
    }

    sync() {
        if (this.inFlight) return this.inFlight;
        this.inFlight = this._sync().finally(() => { this.inFlight = null; });
        return this.inFlight;
    }

    async _sync() {
        const heartbeat = this.heartbeatProvider && this.heartbeatProvider();
        const scope = this.scopeProvider && this.scopeProvider();
        const lease = this.leaseProvider && this.leaseProvider();
        if (!heartbeat || heartbeat.positive !== true || !Number.isFinite(heartbeat.at_ms) ||
            Date.now() - heartbeat.at_ms > 2 * 60 * 1000) {
            return { ok: false, error: 'positive_heartbeat_required' };
        }
        if (!scope || !lease || !lease.lease_id || !lease.token) return { ok: false, error: 'trusted_scope_lease_required' };
        const scopedDeviceUid = deviceUidFromScope(scope);
        const leaseDeviceUid = deviceUidFromScope(lease.scope || lease);
        const deviceUid = this.deviceUid || scopedDeviceUid;
        if (!deviceUid || scopedDeviceUid !== deviceUid || (leaseDeviceUid && leaseDeviceUid !== deviceUid)) {
            const reason = !deviceUid ? 'missing configured device' :
                (scopedDeviceUid !== deviceUid ? 'domain scope differs from configured device' :
                    'lease scope differs from configured device');
            const error = new Error(reason);
            error.code = 'scope_mismatch';
            throw error;
        }
        const response = await this.request({
            device_uid: deviceUid, branch_id: scope.branch_id,
            lease_id: lease.lease_id, lease_token: lease.token,
        });
        const signed = {
            schema: response && response.schema,
            revision: response && response.revision,
            scope: response && response.scope,
            payload: response && response.payload,
        };
        if (!signed.scope || ['company_id', 'branch_id', 'device_id', 'user_id']
            .some((key) => String(signed.scope[key] || '') !== String(scope[key] || ''))) {
            const error = new Error('snapshot scope does not match trusted lease');
            error.code = 'scope_mismatch';
            throw error;
        }
        if (!Number.isInteger(signed.revision) || signed.revision < 1 ||
            response.hash_algorithm !== 'sha256' || !/^[a-f0-9]{64}$/.test(String(response.hash || ''))) {
            const error = new Error('snapshot envelope is invalid'); error.code = 'snapshot_invalid'; throw error;
        }
        const actual = crypto.createHash('sha256').update(canonicalJson(signed)).digest('hex');
        if (!crypto.timingSafeEqual(Buffer.from(actual, 'hex'), Buffer.from(response.hash, 'hex'))) {
            const error = new Error('snapshot hash verification failed'); error.code = 'snapshot_hash_mismatch'; throw error;
        }
        return Object.assign({ ok: true }, this.domain.importSnapshot(Object.assign(signed, { hash: response.hash })));
    }
}

module.exports = { SnapshotSyncClient, canonicalJson, deviceUidFromScope };