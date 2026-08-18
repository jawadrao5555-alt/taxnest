const axios = require('axios');
const fs = require('fs');
const path = require('path');
const os = require('os');
const { startPrinting, stopPrinting, getPrintStatus } = require('./printer');

let pollInterval = null;
let heartbeatInterval = null;
let currentConfig = null;
let statusCallback = null;
let updateCallback = null;

// ─── Resilience state (Task 1062, Aug 2026) ─────────────────────────────────
// Offline "flapping" root cause: a single failed heartbeat/sync just waited
// for the next timer tick (30s/5s), the heartbeat awaited the whole callback-
// queue flush inside itself (up to 50 sequential 10s POSTs = beats starved),
// and nothing woke the agent after PC sleep / Wi-Fi drop. These guards keep
// ticks from overlapping, retry a failed beat quickly with jitter, and expose
// wakeAgent() so main.js can fire an immediate beat+sync on resume/reconnect.
let heartbeatInFlight = false;
let syncInFlight = false;
let flushInFlight = false;
let heartbeatRetryTimer = null;
let heartbeatRetryCount = 0;
// Generation counter: bumped on every start/stop so retry timers scheduled by
// a previous run can never fire into a stopped/restarted agent.
let runGen = 0;
// Optional provider of extra heartbeat fields (set by main.js — e.g. NestPOS
// Desktop Offline Mode telemetry). Must return a plain object; failures are
// swallowed so telemetry can never break the heartbeat.
let heartbeatExtraProvider = null;

function setHeartbeatExtraProvider(fn) {
  heartbeatExtraProvider = typeof fn === 'function' ? fn : null;
}

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
  // Single flusher at a time — the queue is replayed sequentially (ordered);
  // an overlapping second flush would double-POST the same callbacks.
  if (flushInFlight) return;
  flushInFlight = true;
  try {
    await flushCallbackQueueInner();
  } finally {
    flushInFlight = false;
  }
}

async function flushCallbackQueueInner() {
  let q = loadQueue();
  if (q.length === 0) {
    status.pendingCallbacks = 0;
    return;
  }
  log(`Flushing ${q.length} pending callback(s)…`);
  const remaining = [];
  for (let i = 0; i < q.length; i++) {
    const item = q[i];
    if (!currentConfig) {
      // Agent stopped mid-flush — keep every unprocessed item as-is so a
      // restart replays them (already-replayed ones are NOT re-queued).
      remaining.push(...q.slice(i));
      break;
    }
    try {
      await axios.post(
        `${currentConfig.serverUrl}/submit-result`,
        {
          transaction_id: item.transaction_id,
          success: item.success,
          pra_invoice_number: item.pra_invoice_number,
          response: item.response,
          error: item.error,
          offline: item.offline || false,
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

function clearHeartbeatRetry() {
  if (heartbeatRetryTimer) {
    clearTimeout(heartbeatRetryTimer);
    heartbeatRetryTimer = null;
  }
}

// After a FAILED beat, retry quickly (with jitter) instead of sitting red for
// the rest of the 30s tick — brief blips (router hiccup, slow server) recover
// in seconds. Capped at 2 quick retries; the regular interval remains the
// long-term driver, so a genuinely-down network never causes a retry storm.
function scheduleHeartbeatRetry() {
  if (!currentConfig) return;
  if (heartbeatRetryTimer) return;
  if (heartbeatRetryCount >= 2) return;
  heartbeatRetryCount += 1;
  const base = heartbeatRetryCount === 1 ? 3000 : 8000;
  const delay = base + Math.floor(Math.random() * 3000); // jitter
  const gen = runGen;
  heartbeatRetryTimer = setTimeout(() => {
    heartbeatRetryTimer = null;
    if (gen !== runGen || !currentConfig) return;
    log(`Heartbeat quick-retry #${heartbeatRetryCount}…`);
    heartbeat();
  }, delay);
}

async function heartbeat() {
  if (!currentConfig) return;
  // Overlap guard: a slow beat (10s timeout) must never stack with the next
  // tick or a wake/retry-triggered beat.
  if (heartbeatInFlight) return;
  heartbeatInFlight = true;
  try {
    let extra = {};
    if (heartbeatExtraProvider) {
      try { extra = heartbeatExtraProvider() || {}; } catch (e) { extra = {}; }
    }
    const res = await axios.post(
      `${currentConfig.serverUrl}/heartbeat`,
      {
        version: currentConfig.appVersion || '1.0.0',
        build: currentConfig.appBuild || null,
        company_id: currentConfig.companyId,
        // Per-counter routing (v1.9.0): persistent device identity so the
        // server can tell multi-counter installs (same key) apart.
        device_uid: currentConfig.deviceUid || null,
        hostname: currentConfig.hostname || null,
        ...extra,
      },
      {
        headers: { Authorization: `Bearer ${currentConfig.apiKey}` },
        timeout: 10000,
      }
    );
    status.connected = true;
    status.serverInfo = res.data.company;
    status.lastError = null;
    heartbeatRetryCount = 0;
    clearHeartbeatRetry();

    // Self-update: the server piggybacks the latest release info on every
    // heartbeat; main.js decides whether it is actually newer.
    if (updateCallback && res.data.agent_update) {
      try { updateCallback(res.data.agent_update); } catch (e) {}
    }

    const healed = res.data.healed || 0;
    const repromoted = res.data.repromoted || 0;
    const stuck = (res.data.stuck_transaction_ids || []).length;
    log(`Heartbeat OK · healed=${healed} repromoted=${repromoted} stuck=${stuck}`);

    // Phase 4 — replay any callbacks that previously failed. Runs in the
    // BACKGROUND (still ordered — single flusher): up to 50 sequential 10s
    // POSTs must never sit inside the heartbeat's critical path, or one bad
    // patch of connectivity starves the beats and the badge flaps offline.
    flushCallbackQueue().catch(() => {});

    // Phase 5 — if server reports stuck rows, trigger an immediate sync sweep
    if (stuck > 0 || repromoted > 0) {
      syncOnce().catch(() => {});
    }
  } catch (e) {
    status.connected = false;
    status.lastError = `Heartbeat failed: ${e.message}`;
    log('Heartbeat failed:', e.message);
    scheduleHeartbeatRetry();
  } finally {
    heartbeatInFlight = false;
  }
  notify();
}

async function syncOnce() {
  if (!currentConfig) return;
  // Overlap guard: a shop with a slow PRA endpoint (30s/invoice) must never
  // stack 5s-tick sweeps — that caused duplicate submits of the same pending
  // rows to race each other.
  if (syncInFlight) return;
  syncInFlight = true;
  try {
    await syncOnceInner();
  } finally {
    syncInFlight = false;
  }
}

async function syncOnceInner() {
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
    // Transport-level failure = IMS service down / no internet / timeout — NOT a PRA rejection.
    // These bills stay QUEUED (server marks them 'offline') and auto-sync when the service is back.
    const transportError = !e.response
      || /ECONNREFUSED|ENOTFOUND|ETIMEDOUT|ECONNABORTED|EHOSTUNREACH|ENETUNREACH|ECONNRESET|socket hang up|Network Error|timeout of/i
        .test(String(e.code || '') + ' ' + (e.message || ''));
    if (isFiscalDevice && /ECONNREFUSED|ENOTFOUND|ETIMEDOUT/i.test(e.message || '')) {
      errMsg = `IMS Fiscal Device service is NOT running on this PC (localhost:8524 unreachable). Install/start PRAL's IMS Fiscal Device software, then bills will sync automatically. (${e.message})`;
    }
    if (transportError) {
      log(`📡 Offline/unreachable for txn ${invoice.transaction_id} — queued, will retry automatically: ${errMsg}`);
      await reportResult(invoice.transaction_id, false, null, e.response?.data, errMsg, true);
      // Not counted as "failed" — this is a connectivity wait, not a rejection.
      if (failedTxnIds.has(invoice.transaction_id)) {
        failedTxnIds.delete(invoice.transaction_id);
        status.failedCount = failedTxnIds.size;
      }
    } else {
      log(`❌ PRA error txn ${invoice.transaction_id}: ${errMsg}`);
      await reportResult(invoice.transaction_id, false, null, e.response?.data, errMsg);
      failedTxnIds.add(invoice.transaction_id);
      status.failedCount = failedTxnIds.size;
    }
  }
}

async function reportResult(txnId, success, praInvoiceNumber, response, error, offline = false) {
  const payload = {
    transaction_id: txnId,
    success,
    pra_invoice_number: praInvoiceNumber,
    response,
    error,
    offline,
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

// Immediate beat + sync sweep — called by main.js on power resume and network
// reconnect so the badge recovers within seconds instead of waiting for the
// next 30s tick after PC sleep / Wi-Fi drop. In-flight guards make this safe
// to call at any time; a stopped agent ignores it.
function wakeAgent(reason) {
  if (!currentConfig || !status.running) return;
  log(`Wake trigger (${reason || 'unknown'}) — immediate heartbeat + sync`);
  heartbeatRetryCount = 0;
  clearHeartbeatRetry();
  heartbeat().catch(() => {});
  syncOnce().catch(() => {});
}

function startAgent(config, onStatusChange, onAgentUpdate) {
  if (pollInterval || heartbeatInterval) {
    stopAgent();
  }

  runGen += 1;
  currentConfig = config;
  statusCallback = onStatusChange;
  updateCallback = onAgentUpdate || null;
  status.running = true;
  heartbeatRetryCount = 0;
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
  runGen += 1; // invalidate any pending quick-retry timers
  clearHeartbeatRetry();
  heartbeatRetryCount = 0;
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

module.exports = { startAgent, stopAgent, getStatus, setHeartbeatExtraProvider, wakeAgent };
