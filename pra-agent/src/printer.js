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
      {
        printers,
        // Per-counter routing (v1.9.0): this PC's own printer list is stored
        // on its device row so the admin can pick a printer PER counter.
        device_uid: cfg.deviceUid || null,
        hostname: cfg.hostname || null,
      },
      { headers: { Authorization: `Bearer ${cfg.apiKey}` }, timeout: 10000 }
    );
    printStatus.printersReported = printers.length;
    plog(`Reported ${printers.length} printer(s) to server`);
  } catch (e) {
    plog('Printer report failed:', e.message);
  }
}

// Print-window MUTEX (v1.6.0, architect-flagged): the queue poller and the
// POS-window bridge (pos-print-html IPC) share ONE hidden window — two
// concurrent printHtml calls would race loadFile/did-finish-load and can
// print the wrong content. Chain every call behind the previous one.
let printChain = Promise.resolve();
function printHtml(html, deviceName, jobType = 'bill') {
  const run = () => printHtmlUnlocked(html, deviceName, jobType);
  const p = printChain.then(run, run);
  // Keep the chain alive regardless of outcome (printHtmlUnlocked never
  // rejects, but belt-and-braces).
  printChain = p.catch(() => {});
  return p;
}

// Load HTML into the hidden window and print silently on `deviceName`.
// Resolves { success, error }. Never rejects.
function printHtmlUnlocked(html, deviceName, jobType = 'bill') {
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
      // Task 1287: Urdu tickets use the ~5.5 MB Jameel Noori Nastaleeq web
      // font — on a cold cache a fixed delay would rasterize the fallback
      // Naskh mid-download. Wait for document.fonts (bounded at 8s inside the
      // page; the 30s hard timer above still guards the whole job). KOTs have
      // no customer-logo layout and are time-critical for the kitchen, so once
      // the document and fonts are ready they use a short 100 ms settle. Bills
      // and proof prints retain the 500 ms driver-safety settle.
      // English/Roman tickets resolve instantly — document.fonts.ready is
      // already settled when no faces are pending.
      const settleMs = (jobType === 'kot' || jobType === 'kot_void') ? 100 : 500;
      const fontsWait = win.webContents
        .executeJavaScript(
          "(function(){try{if(document.fonts&&document.fonts.ready){return Promise.race([document.fonts.ready,new Promise(function(r){setTimeout(r,8000);})]).then(function(){return 'fonts-ok';});}}catch(e){}return 'no-fontface-api';})()",
          true
        )
        .catch(() => 'fonts-wait-error');
      fontsWait.then(() => setTimeout(() => {
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
      }, settleMs));
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
      {
        success,
        error: error ? String(error).slice(0, 500) : null,
        device_uid: cfg.deviceUid || null,
      },
      { headers: { Authorization: `Bearer ${cfg.apiKey}` }, timeout: 10000 }
    );
  } catch (e) {
    // Server-side stale-requeue (printing > 2 min) recovers the job if this
    // result report is lost — no local retry queue needed.
    plog(`Result report failed for job ${jobId}:`, e.message);
  }
}

// Long-poll (v1.6.2, ZFC "instant print" request Aug 2026): ?wait=8 asks the
// server to HOLD the request up to 8s and answer the moment a job is enqueued
// (checked server-side every 250ms) — jobs start printing ~quarter-second
// after the cashier hits Print instead of waiting out a fixed poll interval.
// Returns the suggested delay (ms) before the next poll:
//   - jobs were printed            → 0   (more may follow in a rush)
//   - server held the request      → 0   (server already did the waiting)
//   - instant empty answer         → 1500 (old server / no hold — never tight-loop)
//   - network/server error         → 3000
async function pollPrintJobs() {
  if (!cfg || printing) return 1500; // one batch at a time — receipts must not interleave
  printing = true;
  let nextDelay = 1500;
  try {
    // timeout must comfortably exceed the server's max hold (8s).
    // device_uid (v1.9.0): the server hands us our own counter's stamped jobs
    // plus unstamped company-wide jobs; another counter's jobs are never ours.
    const deviceParam = cfg.deviceUid ? `&device_uid=${encodeURIComponent(cfg.deviceUid)}` : '';
    const res = await axios.get(`${cfg.serverUrl}/print-jobs?wait=8${deviceParam}`, {
      headers: { Authorization: `Bearer ${cfg.apiKey}` },
      timeout: 15000,
    });
    const jobs = (res.data && res.data.jobs) || [];
    if (jobs.length > 0 || (res.data && res.data.held)) {
      nextDelay = 0;
    }
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
        const { success, error } = await printHtml(contentRes.data, job.target_printer, job.type);
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
    nextDelay = 3000;
  } finally {
    printing = false;
  }
  return nextDelay;
}

// Self-scheduling poll loop — replaces the old fixed setInterval so the next
// long-poll opens IMMEDIATELY after the previous one answers (no dead gap
// between polls where a fresh job would sit unnoticed).
let pollLoopStop = null;
function startPollLoop() {
  let stopped = false;
  let timer = null;
  pollLoopStop = () => { stopped = true; if (timer) { clearTimeout(timer); timer = null; } };
  const tick = async () => {
    if (stopped) return;
    let delay = 1500;
    try {
      delay = await pollPrintJobs();
    } catch (e) {
      delay = 3000; // pollPrintJobs never throws, but never let the loop die
    }
    if (stopped) return;
    if (typeof delay !== 'number' || !isFinite(delay) || delay < 0) delay = 1500;
    timer = setTimeout(tick, delay);
  };
  tick();
}

function startPrinting(config) {
  stopPrinting();
  cfg = config;
  printStatus.printingEnabled = true;
  plog('Silent printing started');

  reportPrinters();
  printersInterval = setInterval(reportPrinters, 5 * 60 * 1000);
  // v1.6.2: long-poll loop (was fixed 2s setInterval) — near-instant prints.
  startPollLoop();
}

function stopPrinting() {
  if (printersInterval) { clearInterval(printersInterval); printersInterval = null; }
  if (pollLoopStop) { pollLoopStop(); pollLoopStop = null; }
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

/**
 * Return the list of printers installed on this PC — used by the agent
 * setup screen's Receipt Printer dropdown so the shopkeeper can pick
 * their counter's printer without leaving the setup form.
 * Reuses the shared hidden print window (creates one if not yet open).
 */
async function getLocalPrinters() {
  try {
    const win = getPrintWindow();
    const list = await win.webContents.getPrintersAsync();
    return (list || []).map(p => ({
      name: p.name,
      displayName: p.displayName || p.name,
      isDefault: !!p.isDefault,
    }));
  } catch (e) {
    plog('getLocalPrinters failed:', e.message);
    return [];
  }
}

module.exports = { startPrinting, stopPrinting, getPrintStatus, printHtml, getLocalPrinters };
