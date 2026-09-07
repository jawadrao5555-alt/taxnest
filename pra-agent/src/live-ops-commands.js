'use strict';

/**
 * Live Ops pending-command handler (allow-listed types only).
 * Never executes arbitrary shell/SQL from the server.
 */

const ALLOWED = new Set([
  'STATUS_REFRESH',
  'RESYNC',
  'UPLOAD_REDACTED_LOGS',
  'TEST_PRINT',
  'PRINTER_REFRESH',
  'SAFE_AGENT_RESTART',
]);

function redactLogLine(line) {
  if (typeof line !== 'string') return '';
  let s = line.slice(0, 400);
  s = s.replace(/Bearer\s+[A-Za-z0-9\-._~+/=]+/gi, 'Bearer [REDACTED]');
  s = s.replace(/(api[_-]?key|token|password)\s*[:=]\s*\S+/gi, '$1=[REDACTED]');
  return s;
}

/**
 * @param {object} opts
 * @param {object} opts.config - agent config (serverUrl, apiKey, ...)
 * @param {Array} opts.commands
 * @param {Function} opts.axios
 * @param {Function} [opts.log]
 * @param {Function} [opts.syncOnce]
 * @param {Function} [opts.refreshPrinters]
 * @param {Function} [opts.getStatus]
 * @param {Function} [opts.getRecentLogs]
 * @param {Function} [opts.requestRestart]
 * @param {Function} [opts.enqueueTestPrint] - optional local hook
 */
async function processPendingCommands(opts) {
  const commands = Array.isArray(opts.commands) ? opts.commands : [];
  if (!commands.length || !opts.config || !opts.axios) return;

  const log = typeof opts.log === 'function' ? opts.log : () => {};
  const url = `${opts.config.serverUrl}/command-result`;
  const headers = { Authorization: `Bearer ${opts.config.apiKey}` };

  for (const cmd of commands) {
    const type = String(cmd.type || cmd.command_type || '').toUpperCase();
    const commandId = cmd.command_id;
    if (!commandId || !ALLOWED.has(type)) {
      log(`LiveOps: rejecting non-allow-listed or incomplete command ${type || '?'}`);
      continue;
    }

    try {
      await opts.axios.post(url, { command_id: commandId, acked: true }, { headers, timeout: 10000 });
    } catch (e) {
      log(`LiveOps: ACK failed for ${commandId}: ${e.message}`);
      continue;
    }

    let ok = true;
    let result = { type };
    try {
      switch (type) {
        case 'STATUS_REFRESH':
          result.status = typeof opts.getStatus === 'function' ? opts.getStatus() : { ok: true };
          break;
        case 'RESYNC':
          if (typeof opts.syncOnce === 'function') await opts.syncOnce();
          result.synced = true;
          break;
        case 'PRINTER_REFRESH':
          if (typeof opts.refreshPrinters === 'function') await opts.refreshPrinters();
          result.printers_refreshed = true;
          break;
        case 'UPLOAD_REDACTED_LOGS': {
          const lines = typeof opts.getRecentLogs === 'function' ? (opts.getRecentLogs(40) || []) : [];
          result.logs = (Array.isArray(lines) ? lines : []).map(redactLogLine).slice(0, 40);
          break;
        }
        case 'TEST_PRINT':
          if (typeof opts.enqueueTestPrint === 'function') {
            result.test_print = await opts.enqueueTestPrint(cmd.payload || {});
          } else {
            result.note = 'TEST_PRINT acknowledged; server-side ENQUEUE_TEST_PRINT preferred';
          }
          break;
        case 'SAFE_AGENT_RESTART':
          if (typeof opts.requestRestart !== 'function') {
            ok = false;
            result.error = 'safe_restart_handler_unavailable';
            break;
          }
          result.restart_scheduled = true;
          // Defer restart so the command-result POST below can complete first.
          setTimeout(() => {
            try { opts.requestRestart('live_ops_safe_restart'); } catch (e) {}
          }, 1500);
          break;
        default:
          ok = false;
          result.error = 'unsupported';
      }
    } catch (e) {
      ok = false;
      result.error = String(e.message || e).slice(0, 400);
    }

    try {
      await opts.axios.post(
        url,
        { command_id: commandId, ok, result },
        { headers, timeout: 15000 }
      );
    } catch (e) {
      log(`LiveOps: result post failed for ${commandId}: ${e.message}`);
    }
  }
}

module.exports = {
  ALLOWED,
  redactLogLine,
  processPendingCommands,
};
