'use strict';

const crypto = require('crypto');

function canonicalValue(value) {
    if (Array.isArray(value)) return value.map(canonicalValue);
    if (value && typeof value === 'object') {
        const out = {};
        Object.keys(value).sort().forEach((key) => { out[key] = canonicalValue(value[key]); });
        return out;
    }
    return value;
}

// Laravel's json_encode defaults escape Unicode and slashes. JSON.stringify
// otherwise has the same compact JSON representation for our validated data.
function laravelJson(value) {
    return JSON.stringify(canonicalValue(value)).replace(/[\u007f-\uffff/]/g, (character) => {
        if (character === '/') return '\\/';
        return '\\u' + character.charCodeAt(0).toString(16).padStart(4, '0');
    });
}

function validateAuthority(input, scope) {
    const value = input || {};
    let secretBytes = null;
    if (typeof value.signing_secret === 'string' && /^[A-Za-z0-9_-]{43}$/.test(value.signing_secret)) {
        secretBytes = Buffer.from(value.signing_secret.replace(/-/g, '+').replace(/_/g, '/') + '=', 'base64');
    }
    if (!Number.isInteger(value.lease_id) || value.lease_id < 1 ||
        typeof value.signing_secret !== 'string' || !/^[A-Za-z0-9_-]{43}$/.test(value.signing_secret) ||
        !secretBytes || secretBytes.length !== 32 ||
        !Number.isInteger(value.next_sequence) || value.next_sequence < 1 ||
        typeof value.prev_hash !== 'string' || !/^[a-f0-9]{64}$/.test(value.prev_hash) ||
        !Array.isArray(value.allowed_actions) || !value.allowed_actions.every((x) => typeof x === 'string') ||
        !value.scope || ['company_id', 'branch_id', 'device_id', 'user_id'].some((key) =>
            String(value.scope[key] || '') !== String(scope[key] || ''))) {
        const error = new Error('trusted lease-chain authority is invalid');
        error.code = 'scope_lease_invalid';
        throw error;
    }
    return JSON.parse(JSON.stringify(value));
}

function signWireEvent(wire, authority) {
    const sequence = authority.next_sequence;
    const unsigned = {
        event_id: wire.event_id, event_type: wire.event_type, occurred_at: wire.occurred_at,
        idempotency_key: wire.idempotency_key, scope: wire.scope, payload: wire.payload,
        lease_id: authority.lease_id, sequence, prev_hash: authority.prev_hash,
    };
    const canonical_json = laravelJson(unsigned);
    // The server intentionally feeds the issued base64url text directly to
    // hash_hmac (it does not decode it first).
    const signature = crypto.createHmac('sha256', Buffer.from(authority.signing_secret, 'utf8'))
        .update(canonical_json, 'utf8').digest('hex');
    const chain_hash = crypto.createHash('sha256').update(canonical_json + ':' + signature, 'utf8').digest('hex');
    return { canonical_json, signature, chain_hash, sequence, prev_hash: authority.prev_hash };
}

module.exports = { canonicalValue, laravelJson, validateAuthority, signWireEvent };