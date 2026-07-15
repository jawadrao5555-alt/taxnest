const axios = require('axios');
const fs = require('fs');
const path = require('path');
const os = require('os');
const { startPrinting, stopPrinting, getPrintStatus } = require('./printer');

let pollInterval = null;
let heartbeatInterval = null;
let currentConfig = null;
let statusCallback = null;

const status = {
  running: false,
  connected: false,
  lastSync: null,
  lastError: null,
  pendingCount: 0,
  submittedCount: 0,
  failedCount: 0,
  pendingCallbacks: 0,
  serverInfo: null,
};

const failedTxnIds = new Set();
const submittedTxnIds = new Set();

// Phase 4 — persistent callback retry queue.
// When `submit-result` fails (network/server blip), we save the payload to disk and replay it on every heartbeat.
const QUEUE_DIR = path.join(os.homedir(), '.taxnest-pra-agent');
const QUEUE_FILE = path.join(QUEUE_DIR, 'pending-callbacks.json');

function ensureQueueDir() {
  try { if (!fs.existsSync(QUEUE_DIR)) fs.mkdirSync(QUEUE_DIR, { recursive: true }); } catch (e) {}
}
function loadQueue() {
  try {
    ensureQueueDir();
    if (!fs.existsSync(QUEUE_FILE)) return [];
    return JSON.parse(fs.readFileSync(QUEUE_FILE, 'utf8') || '[]');
  } catch (e) { return []; }
}
function saveQueue(queue) {
  try {
    ensureQueueDir();
    fs.writeFileSync(QUEUE_FILE, JSON.stringify(queue, null, 2));
  } catch (e) { log('Queue save failed:', e.message); }
}
function enqueueCallback(payload) {
  const q = loadQueue();
  // Dedupe by transaction_id — keep latest payload only
  const filtered = q.filter(p => p.transaction_id !== payload.transaction_id);
  filtered.push({ ...payload, _enqueuedAt: new Date().toISOString(), _attempts: 0 });
  saveQueue(filtered);
  status.pendingCallbacks = filtered.length;
}
async function flushCallbackQueue() {
  if (!currentConfig) return;
  let q = loadQueue();
  if (q.length === 0) {
    status.pendingCallbacks = 0;
    return;
  }
  log(`Flushing ${q.length} pending callback(s)…`);
  const remaining = [];
  for (const item of q) {
    try {
      await axios.post(
        `${currentConfig.serverUrl}/submit-result`,
        {
          transaction_id: item.transaction_id,
          success: item.success,
          pra_invoice_number: item.pra_invoice_number,
          response: item.response,
          error: item.error,
        },
        { headers: { Authorization: `Bearer ${currentConfig.apiKey}` }, timeout: 10000 }
      );
      log(`✅ Replayed callback for txn ${item.transaction_id}`);
    } catch (e) {
      const attempts = (item._attempts || 0) + 1;
      // Drop after 50 attempts to avoid unbounded growth
      if (attempts < 50) {
        remaining.push({ ...item, _attempts: attempts });
      } else {
        log(`⚠️ Dropping callback for txn ${item.transaction_id} after 50 failed attempts`);
      }
    }
  }
  saveQueue(remaining);
  status.pendingCallbacks = remaining.length;
}

function getStatus() {
  return { ...status, printer: getPrintStatus() };
}

function notify() {
  if (statusCallback) statusCallback(getStatus());
}

function log(...args) {
  console.log('[Agent]', new Date().toISOString(), ...args);
}

async function heartbeat() {
  if (!currentConfig) return;
  try {
    const res = await axios.post(
      `${currentConfig.serverUrl}/heartbeat`,
      { version: '1.0.0', company_id: currentConfig.companyId },
      {
        headers: { Authorization: `Bearer ${currentConfig.apiKey}` },
        timeout: 10000,
      }
    );
    status.connected = true;
    status.serverInfo = res.data.company;
    status.lastError = null;

    const healed = res.data.healed || 0;
    const repromoted = res.data.repromoted || 0;
    const stuck = (res.data.stuck_transaction_ids || []).length;
    log(`Heartbeat OK · healed=${healed} repromoted=${repromoted} stuck=${stuck}`);

    // Phase 4 — replay any callbacks that previously failed
    await flushCallbackQueue();

    // Phase 5 — if server reports stuck rows, trigger an immediate sync sweep
    if (stuck > 0 || repromoted > 0) {
      syncOnce().catch(() => {});
    }
  } catch (e) {
    status.connected = false;
    status.lastError = `Heartbeat failed: ${e.message}`;
    log('Heartbeat failed:', e.message);
  }
  notify();
}

async function syncOnce() {
  if (!currentConfig) return;

  try {
    const res = await axios.get(`${currentConfig.serverUrl}/pending-invoices`, {
      headers: { Authorization: `Bearer ${currentConfig.apiKey}` },
      timeout: 15000,
    });

    const { invoices, pra_endpoint, pra_token, pra_mode, count } = res.data;
    status.pendingCount = count;
    status.connected = true;
    notify();

    if (count === 0) {
      status.lastSync = new Date().toISOString();
      log('No pending invoices');
      notify();
      return;
    }

    log(`Found ${count} pending invoices`);

    for (const inv of invoices) {
      await submitToPra(inv, pra_endpoint, pra_token, pra_mode);
    }

    status.lastSync = new Date().toISOString();
    notify();
  } catch (e) {
    status.connected = false;
    status.lastError = `Sync failed: ${e.message}`;
    log('Sync failed:', e.message);
    notify();
  }
}

async function submitToPra(invoice, praEndpoint, praToken, praMode) {
  const isFiscalDevice = praMode === 'fiscal_device';
  log(`Submitting txn ${invoice.transaction_id} to PRA${isFiscalDevice ? ' (Fiscal Device — localhost:8524)' : ''}`);

  try {
    const praRes = await axios.post(praEndpoint, invoice.payload, {
      headers: {
        Authorization: `Bearer ${praToken}`,
        'Content-Type': 'application/json',
      },
      timeout: 30000,
    });

    const data = praRes.data;
    // Tolerant comparison — the local IMS Fiscal Device service may return Code as a NUMBER (100),
    // while the cloud API returns a string ('100'). A strict === would misreport success as failure.
    const success = data != null && String(data.Code ?? data.code ?? '') === '100';
    const praInvoiceNumber = data?.InvoiceNumber || data?.invoiceNumber || data?.Response;

    if (success && praInvoiceNumber) {
      log(`✅ PRA accepted txn ${invoice.transaction_id}: ${praInvoiceNumber}`);
      await reportResult(invoice.transaction_id, true, praInvoiceNumber, data, null);
      if (failedTxnIds.has(invoice.transaction_id)) {
        failedTxnIds.delete(invoice.transaction_id);
        status.failedCount = failedTxnIds.size;
      }
      if (!submittedTxnIds.has(invoice.transaction_id)) {
        submittedTxnIds.add(invoice.transaction_id);
        status.submittedCount = submittedTxnIds.size;
      }
    } else {
      const err = data?.Response || data?.message || JSON.stringify(data);
      log(`❌ PRA rejected txn ${invoice.transaction_id}: ${err}`);
      // Pass any invoice number we DID receive — the server has a regex rescue
      // (fiscal-number pattern) that flips the row to 'submitted' if PRA actually issued one,
      // preventing a duplicate re-submission of an already-fiscalized bill.
      await reportResult(invoice.transaction_id, false, praInvoiceNumber || null, data, err);
      failedTxnIds.add(invoice.transaction_id);
      status.failedCount = failedTxnIds.size;
    }
  } catch (e) {
    let errMsg = e.response?.data ? JSON.stringify(e.response.data) : e.message;
    if (isFiscalDevice && /ECONNREFUSED|ENOTFOUND|ETIMEDOUT/i.test(e.message || '')) {
      errMsg = `IMS Fiscal Device service is NOT running on this PC (localhost:8524 unreachable). Install/start PRAL's IMS Fiscal Device software, then bills will sync automatically. (${e.message})`;
    }
    log(`❌ PRA error txn ${invoice.transaction_id}: ${errMsg}`);
    await reportResult(invoice.transaction_id, false, null, e.response?.data, errMsg);
    failedTxnIds.add(invoice.transaction_id);
    status.failedCount = failedTxnIds.size;
  }
}

async function reportResult(txnId, success, praInvoiceNumber, response, error) {
  const payload = {
    transaction_id: txnId,
    success,
    pra_invoice_number: praInvoiceNumber,
    response,
    error,
  };
  try {
    await axios.post(
      `${currentConfig.serverUrl}/submit-result`,
      payload,
      {
        headers: { Authorization: `Bearer ${currentConfig.apiKey}` },
        timeout: 10000,
      }
    );
  } catch (e) {
    // Phase 4 — never lose a successful PRA result.
    // Persist the callback so we can replay it on the next heartbeat.
    log(`⚠️ Callback to server failed for txn ${txnId} (${e.message}) — queued for retry`);
    enqueueCallback(payload);
    notify();
  }
}

function startAgent(config, onStatusChange) {
  if (pollInterval || heartbeatInterval) {
    stopAgent();
  }

  currentConfig = config;
  statusCallback = onStatusChange;
  status.running = true;
  notify();

  log('Starting agent', { server: config.serverUrl, company: config.companyId });

  heartbeat();
  syncOnce();

  heartbeatInterval = setInterval(heartbeat, 30000);
  pollInterval = setInterval(syncOnce, 5000);

  // Silent printer routing — report printers + poll/print queued jobs.
  // Runs alongside PRA sync; harmless when the shop hasn't enabled it
  // (server just returns zero jobs).
  startPrinting(config);
}

function stopAgent() {
  stopPrinting();
  if (pollInterval) {
    clearInterval(pollInterval);
    pollInterval = null;
  }
  if (heartbeatInterval) {
    clearInterval(heartbeatInterval);
    heartbeatInterval = null;
  }
  status.running = false;
  status.connected = false;
  notify();
  log('Agent stopped');
}

module.exports = { startAgent, stopAgent, getStatus };
