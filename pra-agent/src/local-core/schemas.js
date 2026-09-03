'use strict';

const COMMAND_VERSION = 1;
const EVENT_SCHEMA_VERSION = 1;
const SCOPE_KEYS = Object.freeze(['company_id', 'branch_id', 'device_id', 'user_id']);
const COMMAND_TYPES = Object.freeze([
    'order.hold', 'order.claim', 'order.cancel', 'order.settle',
    'table.claim', 'table.release', 'table.shift',
    'stock.set', 'stock.adjust',
    'customer.upsert', 'khata.debit', 'wasooli.record', 'refund.record',
    'cash.open', 'cash.expense', 'cash.close',
    'staff.start', 'staff.end',
    'print.enqueue', 'print.claim', 'print.complete', 'print.fail',
]);
// This is deliberately the Laravel AgentCoreProjectorRegistry vocabulary. A
// command is not itself a wire event: command verbs may evolve independently,
// but every durable/uploaded event has one of these canonical names.
const EVENT_TYPES = Object.freeze([
    'sale.created', 'sale.voided',
    'order.created', 'order.held', 'order.updated', 'order.cancelled', 'order.settled',
    'kot.created', 'kot.updated', 'kot.completed',
    'stock.adjusted', 'stock.transferred',
    'customer.ledger.posted', 'customer.khata.posted', 'customer.wasooli.posted', 'customer.refund.posted',
    'cash.opened', 'cash.movement.posted', 'expense.created', 'day-close.created',
    'staff.attendance.recorded', 'staff.shift.recorded',
    'print.requested', 'print.completed',
]);
const EVENT_SCHEMAS = Object.freeze({
    'sale.created': 'local-core.sale.v1', 'sale.voided': 'local-core.sale.v1',
    'order.created': 'local-core.order.v1', 'order.held': 'local-core.order.v1',
    'order.updated': 'local-core.order.v1',
    'order.cancelled': 'local-core.order.v1', 'order.settled': 'local-core.order.v1',
    'kot.created': 'local-core.kot.v1', 'kot.updated': 'local-core.kot.v1', 'kot.completed': 'local-core.kot.v1',
    'stock.adjusted': 'local-core.stock.v1', 'stock.transferred': 'local-core.stock.v1',
    'customer.ledger.posted': 'local-core.customer-ledger.v1', 'customer.khata.posted': 'local-core.customer-ledger.v1',
    'customer.wasooli.posted': 'local-core.customer-ledger.v1', 'customer.refund.posted': 'local-core.customer-ledger.v1',
    'cash.opened': 'local-core.cash.v1', 'cash.movement.posted': 'local-core.cash.v1',
    'expense.created': 'local-core.expense.v1', 'day-close.created': 'local-core.day-close.v1',
    'staff.attendance.recorded': 'local-core.staff.v1', 'staff.shift.recorded': 'local-core.staff.v1',
    'print.requested': 'local-core.print.v1', 'print.completed': 'local-core.print.v1',
});

function error(code, message) {
    const value = new Error(message);
    value.code = code;
    return value;
}

function object(value) {
    return !!value && typeof value === 'object' && !Array.isArray(value) &&
        (Object.getPrototypeOf(value) === Object.prototype || Object.getPrototypeOf(value) === null);
}

function safeString(value, name, maximum) {
    if (typeof value !== 'string' || !value.trim() || value.length > maximum ||
        /[\u0000-\u001f\u007f]/.test(value)) throw error('invalid_command', name + ' is invalid');
}

function validateScope(scope) {
    if (!object(scope)) throw error('invalid_scope', 'scope is required');
    SCOPE_KEYS.forEach((key) => {
        try { safeString(scope[key], key, 128); } catch (e) { throw error('invalid_scope', e.message); }
    });
    return JSON.parse(JSON.stringify(scope));
}

function validateCommand(input) {
    if (!object(input)) throw error('invalid_command', 'command must be an object');
    if (input.v !== COMMAND_VERSION) throw error('unsupported_version', 'unsupported command version');
    if (COMMAND_TYPES.indexOf(input.type) === -1) throw error('invalid_command_type', 'unsupported command type');
    safeString(input.id, 'command id', 128);
    if (!/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/.test(input.id)) {
        throw error('invalid_command_id', 'command id must be 8-128 safe characters');
    }
    safeString(input.aggregate_id, 'aggregate id', 128);
    if (!Number.isInteger(input.expected_revision) || input.expected_revision < 0) {
        throw error('invalid_revision', 'expected_revision must be a non-negative integer');
    }
    if (!Number.isInteger(input.at_ms) || input.at_ms < 0) throw error('invalid_command_time', 'at_ms is invalid');
    validateScope(input.scope);
    if (!object(input.payload)) throw error('invalid_command_payload', 'payload must be an object');
    let encoded;
    try { encoded = JSON.stringify(input); } catch (e) { throw error('invalid_command', 'command is not JSON serializable'); }
    // The canonical EventStore envelope has a 16KiB payload ceiling. Keep
    // command data comfortably below it so marker recovery can always append
    // the exact event it has made durable.
    if (Buffer.byteLength(encoded, 'utf8') > 12 * 1024) throw error('command_too_large', 'command exceeds size limit');
    return JSON.parse(encoded);
}

function validateDomainEvent(input) {
    if (!object(input) || input.schema_v !== EVENT_SCHEMA_VERSION ||
        typeof input.type !== 'string' || !EVENT_TYPES.includes(input.type) ||
        typeof input.id !== 'string' || typeof input.command_id !== 'string' ||
        typeof input.aggregate_id !== 'string' || !Number.isInteger(input.revision) || input.revision < 1 ||
        !Number.isInteger(input.sequence) || input.sequence < 1 || !Number.isInteger(input.at_ms) ||
        !object(input.payload)) throw error('invalid_domain_event', 'domain event is invalid');
    validateScope(input.scope);
    return JSON.parse(JSON.stringify(input));
}

function capabilities() {
    return { command_versions: [COMMAND_VERSION], event_schema_versions: [EVENT_SCHEMA_VERSION],
        command_types: COMMAND_TYPES.slice(), event_types: EVENT_TYPES.slice(), event_schemas: cloneSchemas() };
}
function cloneSchemas() { return JSON.parse(JSON.stringify(EVENT_SCHEMAS)); }

module.exports = {
    COMMAND_VERSION, EVENT_SCHEMA_VERSION, COMMAND_TYPES, EVENT_TYPES, EVENT_SCHEMAS, SCOPE_KEYS,
    validateScope, validateCommand, validateDomainEvent, capabilities,
};