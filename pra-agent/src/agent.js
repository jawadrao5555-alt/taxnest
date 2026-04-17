const axios = require('axios');

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
  serverInfo: null,
};

function getStatus() {
  return { ...status };
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
    log('Heartbeat OK');
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

    const { invoices, pra_endpoint, pra_token, count } = res.data;
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
      await submitToPra(inv, pra_endpoint, pra_token);
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

async function submitToPra(invoice, praEndpoint, praToken) {
  log(`Submitting txn ${invoice.transaction_id} to PRA`);

  try {
    const praRes = await axios.post(praEndpoint, invoice.payload, {
      headers: {
        Authorization: `Bearer ${praToken}`,
        'Content-Type': 'application/json',
      },
      timeout: 30000,
    });

    const data = praRes.data;
    const success = data && (data.Code === '100' || data.code === '100');
    const praInvoiceNumber = data?.InvoiceNumber || data?.invoiceNumber || data?.Response;

    if (success && praInvoiceNumber) {
      log(`✅ PRA accepted txn ${invoice.transaction_id}: ${praInvoiceNumber}`);
      await reportResult(invoice.transaction_id, true, praInvoiceNumber, data, null);
      status.submittedCount++;
    } else {
      const err = data?.Response || data?.message || JSON.stringify(data);
      log(`❌ PRA rejected txn ${invoice.transaction_id}: ${err}`);
      await reportResult(invoice.transaction_id, false, null, data, err);
      status.failedCount++;
    }
  } catch (e) {
    const errMsg = e.response?.data ? JSON.stringify(e.response.data) : e.message;
    log(`❌ PRA error txn ${invoice.transaction_id}: ${errMsg}`);
    await reportResult(invoice.transaction_id, false, null, e.response?.data, errMsg);
    status.failedCount++;
  }
}

async function reportResult(txnId, success, praInvoiceNumber, response, error) {
  try {
    await axios.post(
      `${currentConfig.serverUrl}/submit-result`,
      {
        transaction_id: txnId,
        success,
        pra_invoice_number: praInvoiceNumber,
        response,
        error,
      },
      {
        headers: { Authorization: `Bearer ${currentConfig.apiKey}` },
        timeout: 10000,
      }
    );
  } catch (e) {
    log(`Failed to report result for txn ${txnId}:`, e.message);
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

  heartbeatInterval = setInterval(heartbeat, 60000);
  pollInterval = setInterval(syncOnce, 30000);
}

function stopAgent() {
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
