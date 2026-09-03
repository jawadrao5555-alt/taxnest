'use strict';

// Local TaxNest Core wire contract. Keep this dependency-free: devices can
// validate an event before it is ever allowed onto the durable local journal.
const PROTOCOL_VERSION = 1;
const EVENT_TYPES = Object.freeze([
    'sale.created', 'sale.voided', 'caller.ring', 'print.requested',
    'print.completed', 'sync.acked', 'sync.rejected',
]);
const MAX_PAYLOAD_BYTES = 16 * 1024;
const SCOPE_KEYS = Object.freeze(['company_id', 'branch_id', 'device_id', 'user_id']);

function plainObject(value) {
    if (!value || typeof value !== 'object' || Array.isArray(value)) return false;
    return Object.getPrototypeOf(value) === Object.prototype || Object.getPrototypeOf(value) === null;
}

function validationError(code, message) {
    const error = new Error(message);
    error.code = code;
    return error;
}

function validateEvent(input) {
    if (!plainObject(input)) throw validationError('invalid_event', 'event must be an object');
    if (input.v !== PROTOCOL_VERSION) throw validationError('unsupported_version', 'unsupported protocol version');
    if (typeof input.id !== 'string' || !/^[A-Za-z0-9][A-Za-z0-9._:-]{7,63}$/.test(input.id)) {
        throw validationError('invalid_event_id', 'event id must be 8-64 safe characters');
    }
    if (typeof input.idempotency_key !== 'string' ||
        !/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/.test(input.idempotency_key)) {
        throw validationError('invalid_idempotency_key', 'idempotency key must be 8-128 safe characters');
    }
    if (!plainObject(input.scope)) throw validationError('invalid_event_scope', 'event scope must be an object');
    for (const key of SCOPE_KEYS) {
        if (typeof input.scope[key] !== 'string' || !input.scope[key].trim() ||
            input.scope[key].length > 128 || /[\u0000-\u001f\u007f]/.test(input.scope[key])) {
            throw validationError('invalid_event_scope', 'event scope requires valid company, branch, device and user ids');
        }
    }
    if (EVENT_TYPES.indexOf(input.type) === -1) throw validationError('invalid_event_type', 'unsupported event type');
    if (!Number.isInteger(input.at_ms) || input.at_ms < 0) {
        throw validationError('invalid_event_time', 'event at_ms must be a non-negative integer');
    }
    if (!plainObject(input.payload)) throw validationError('invalid_event_payload', 'event payload must be an object');
    let payloadEncoded;
    let eventEncoded;
    try {
        payloadEncoded = JSON.stringify(input.payload);
        eventEncoded = JSON.stringify(input);
    } catch (e) { throw validationError('invalid_event', 'event is not JSON serializable'); }
    if (Buffer.byteLength(payloadEncoded, 'utf8') > MAX_PAYLOAD_BYTES) {
        throw validationError('payload_too_large', 'event payload exceeds protocol size limit');
    }
    // Return a JSON round-trip so callers never retain mutable user input.
    return JSON.parse(eventEncoded);
}

function capabilities() {
    return { protocol_versions: [PROTOCOL_VERSION], event_types: EVENT_TYPES.slice(), max_payload_bytes: MAX_PAYLOAD_BYTES };
}

module.exports = { PROTOCOL_VERSION, EVENT_TYPES, MAX_PAYLOAD_BYTES, SCOPE_KEYS, validateEvent, capabilities };