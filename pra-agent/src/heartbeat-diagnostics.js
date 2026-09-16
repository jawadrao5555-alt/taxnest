'use strict';

function boundedInteger(value, max) {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? Math.max(0, Math.min(max, Math.floor(parsed))) : 0;
}

function safeIso(value) {
  return typeof value === 'string' && !Number.isNaN(Date.parse(value)) ? value : null;
}

// Operational metadata only. Never put printer names, URLs, fiscal payloads,
// agent keys, filesystem paths, or raw errors into heartbeat diagnostics.
function heartbeatDiagnostics(status) {
  const value = status && typeof status === 'object' ? status : {};
  const printer = value.printer && typeof value.printer === 'object' ? value.printer : {};
  const state = ['unknown', 'reachable', 'unreachable'].includes(value.fiscalConnectivity)
    ? value.fiscalConnectivity : 'unknown';

  return {
    process_online: true,
    printer: {
      printing_enabled: !!printer.printingEnabled,
      printers_reported: boundedInteger(printer.printersReported, 100),
      healthy: !printer.lastPrintError,
    },
    fiscal_connectivity: {
      state,
      checked_at: safeIso(value.lastFiscalCheck),
    },
    queue: {
      pending_callbacks: boundedInteger(value.pendingCallbacks, 100000),
      last_sync_at: safeIso(value.lastSync),
    },
  };
}

module.exports = { heartbeatDiagnostics };