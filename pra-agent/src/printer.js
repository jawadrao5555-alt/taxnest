// Silent printer routing ("Tareeqa 2") — the agent claims queued print jobs
// from the TaxNest server and prints them silently (no dialog) on the printer
// the shop admin picked on the Printer Settings page.
//
// Flow: report installed printers (on start + every 5 min) → poll /print-jobs
// (server-side claim, safe across restarts) → fetch rendered receipt/KOT HTML
// → write to a temp file and load into a hidden window (scripts stay dormant —
// templates only auto-print when ?auto_print=1 is in location.search, which a
// file:// URL never has) → webContents.print({ silent, deviceName }) → result.
//
// NEVER load receipt HTML via a base64 data: URL — Chromium rejects URLs over
// ~2 MB with ERR_INVALID_URL (-300), and receipts with embedded logos hit that
// (live failure: Pizza Master, Jul 2026). Temp file + loadFile has no size cap.
const { BrowserWindow } = require('electron');
const axios = require('axios');
const fs = require('fs');
const os = require('os');
const path = require('path');

let printersInterval = null;
let jobsInterval = null;
let printWindow = null;
let printing = false;
let cfg = null;

const printStatus = {
  printingEnabled: false,
  printersReported: 0,
  jobsPrinted: 0,
  jobsFailed: 0,
  lastPrintError: null,
};

function plog(...args) {
  console.log('[Printer]', new Date().toISOString(), ...args);
}

function getPrintStatus() {
  return { ...printStatus };
}

function getPrintWindow() {
  if (printWindow && !printWindow.isDestroyed()) return printWindow;
  printWindow = new BrowserWindow({
    show: false,
    width: 420,
    height: 800,
    webPreferences: {
      nodeIntegration: false,
      contextIsolation: true,
      sandbox: true,
    },
  });
  printWindow.on('closed', () => { printWindow = null; });
  return printWindow;
}

async function reportPrinters() {
  if (!cfg) return;
  try {
    const win = getPrintWindow();
    const list = await win.webContents.getPrintersAsync();
    const printers = (list || []).slice(0, 50).map(p => ({
      name: p.name,
      displayName: p.displayName || p.name,
      isDefault: !!p.isDefault,
    }));
    await axios.post(
      `${cfg.serverUrl}/printers`,
      { printers },
      { headers: { Authorization: `Bearer ${cfg.apiKey}` }, timeout: 10000 }
    );
    printStatus.printersReported = printers.length;
    plog(`Reported ${printers.length} printer(s) to server`);
  } catch (e) {
    plog('Printer report failed:', e.message);
  }
}

// Load HTML into the hidden window and print silently on `deviceName`.
// Resolves { success, error }. Never rejects.
function printHtml(html, deviceName) {
  return new Promise((resolve) => {
    let settled = false;
    let win;
    let tmpFile = null;
    const done = (success, error) => {
      if (settled) return;
      settled = true;
      // CRITICAL: drop any pending load listener — a stale listener firing on
      // the NEXT job's load would print the same content twice.
      try { if (win && !win.isDestroyed()) win.webContents.removeAllListeners('did-finish-load'); } catch (e) {}
      // Best-effort temp file cleanup — content is already rasterized by now.
      try { if (tmpFile) fs.unlinkSync(tmpFile); } catch (e) {}
      resolve({ success, error: error || null });
    };

    try {
      win = getPrintWindow();
    } catch (e) {
      return done(false, `window: ${e.message}`);
    }

    // Hard timeout — a wedged load/driver must never jam the queue.
    const timer = setTimeout(() => done(false, 'print timeout (30s)'), 30000);

    win.webContents.once('did-finish-load', () => {
      // Give layout/fonts/images a moment to settle before rasterizing.
      setTimeout(() => {
        try {
          win.webContents.print(
            {
              silent: true,
              deviceName: deviceName,
              printBackground: true,
              margins: { marginType: 'none' },
              // NO pageSize — thermal drivers reject explicit sizes; the
              // driver's own paper setting (80mm/58mm roll) must win.
            },
            (ok, failureReason) => {
              clearTimeout(timer);
              done(!!ok, ok ? null : (failureReason || 'print failed'));
            }
          );
        } catch (e) {
          clearTimeout(timer);
          done(false, `print: ${e.message}`);
        }
      }, 500);
    });

    // Temp file + loadFile — a data: URL over ~2 MB fails with ERR_INVALID_URL
    // (big embedded logos). file:// carries no query string, so the templates'
    // ?auto_print=1 guard keeps their own scripts dormant, same as before.
    try {
      tmpFile = path.join(os.tmpdir(), `taxnest-print-${Date.now()}-${Math.random().toString(36).slice(2)}.html`);
      fs.writeFileSync(tmpFile, html, 'utf8');
    } catch (e) {
      clearTimeout(timer);
      return done(false, `tmpfile: ${e.message}`);
    }
    win.loadFile(tmpFile).catch((e) => {
      clearTimeout(timer);
      done(false, `load: ${e.message}`);
    });
  });
}

async function reportJobResult(jobId, success, error) {
  try {
    await axios.post(
      `${cfg.serverUrl}/print-jobs/${jobId}/result`,
      { success, error: error ? String(error).slice(0, 500) : null },
      { headers: { Authorization: `Bearer ${cfg.apiKey}` }, timeout: 10000 }
    );
  } catch (e) {
    // Server-side stale-requeue (printing > 2 min) recovers the job if this
    // result report is lost — no local retry queue needed.
    plog(`Result report failed for job ${jobId}:`, e.message);
  }
}

async function pollPrintJobs() {
  if (!cfg || printing) return; // one batch at a time — receipts must not interleave
  printing = true;
  try {
    const res = await axios.get(`${cfg.serverUrl}/print-jobs`, {
      headers: { Authorization: `Bearer ${cfg.apiKey}` },
      timeout: 10000,
    });
    const jobs = (res.data && res.data.jobs) || [];
    for (const job of jobs) {
      try {
        const contentRes = await axios.get(
          `${cfg.serverUrl}/print-jobs/${job.id}/content`,
          { headers: { Authorization: `Bearer ${cfg.apiKey}` }, timeout: 15000, responseType: 'text' }
        );
        // 204 = nothing left to print (e.g. delta items already covered by an
        // earlier ticket) — mark done WITHOUT feeding a blank page to the printer.
        if (contentRes.status === 204 || !contentRes.data) {
          plog(`Job ${job.id}: nothing to print (already covered) — marking done`);
          await reportJobResult(job.id, true, null);
          continue;
        }
        const { success, error } = await printHtml(contentRes.data, job.target_printer);
        if (success) {
          printStatus.jobsPrinted += 1;
          printStatus.lastPrintError = null;
          plog(`✅ Printed job ${job.id} (${job.type}) on "${job.target_printer}"`);
        } else {
          printStatus.jobsFailed += 1;
          printStatus.lastPrintError = error;
          plog(`❌ Job ${job.id} failed: ${error}`);
        }
        await reportJobResult(job.id, success, error);
      } catch (e) {
        printStatus.jobsFailed += 1;
        printStatus.lastPrintError = e.message;
        plog(`❌ Job ${job.id} error:`, e.message);
        await reportJobResult(job.id, false, e.message);
      }
    }
  } catch (e) {
    // Poll failure is quiet — heartbeat already tracks connectivity.
  } finally {
    printing = false;
  }
}

function startPrinting(config) {
  stopPrinting();
  cfg = config;
  printStatus.printingEnabled = true;
  plog('Silent printing started');

  reportPrinters();
  printersInterval = setInterval(reportPrinters, 5 * 60 * 1000);
  jobsInterval = setInterval(pollPrintJobs, 5000);
}

function stopPrinting() {
  if (printersInterval) { clearInterval(printersInterval); printersInterval = null; }
  if (jobsInterval) { clearInterval(jobsInterval); jobsInterval = null; }
  if (printWindow && !printWindow.isDestroyed()) {
    try { printWindow.destroy(); } catch (e) {}
  }
  printWindow = null;
  cfg = null;
  if (printStatus.printingEnabled) plog('Silent printing stopped');
  printStatus.printingEnabled = false;
}

// printHtml is also used by the NestPOS Desktop POS window bridge
// (pos-print-html IPC) for in-app silent receipt printing.
module.exports = { startPrinting, stopPrinting, getPrintStatus, printHtml };
