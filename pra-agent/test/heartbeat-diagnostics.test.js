'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const { heartbeatDiagnostics } = require('../src/heartbeat-diagnostics');

test('reports bounded operational health without raw errors or sensitive detail', () => {
  const diagnostics = heartbeatDiagnostics({
    pendingCallbacks: 100001,
    lastSync: '2026-09-16T10:00:00.000Z',
    fiscalConnectivity: 'reachable',
    lastFiscalCheck: '2026-09-16T10:01:00.000Z',
    printer: { printingEnabled: true, printersReported: 2, lastPrintError: 'C:\\secret\\receipt.html' },
  });
  assert.deepEqual(diagnostics, {
    process_online: true,
    printer: { printing_enabled: true, printers_reported: 2, healthy: false },
    fiscal_connectivity: { state: 'reachable', checked_at: '2026-09-16T10:01:00.000Z' },
    queue: { pending_callbacks: 100000, last_sync_at: '2026-09-16T10:00:00.000Z' },
  });
  assert.ok(!JSON.stringify(diagnostics).includes('secret'));
});

test('invalid values fail closed to unknown and bounded zero values', () => {
  assert.deepEqual(heartbeatDiagnostics({
    pendingCallbacks: -2, lastSync: 'not-a-date', fiscalConnectivity: 'wrong',
    printer: { printersReported: 999, lastPrintError: null },
  }), {
    process_online: true,
    printer: { printing_enabled: false, printers_reported: 100, healthy: true },
    fiscal_connectivity: { state: 'unknown', checked_at: null },
    queue: { pending_callbacks: 0, last_sync_at: null },
  });
});