'use strict';

// Offline KOT (Local Core, Sep 2026).
//
// When the shop's internet is down, a waiter tablet or the counter holds the
// order on the shop PC's Local Core with an optional `kot_document`. The
// domain stores that document as a LOCAL-ONLY print_queue job (kind 'kot');
// this module renders it to a thermal-friendly HTML slip and drains the queue
// through the agent's own silent printer — no cloud round-trip involved.
//
// Rules:
//   * Printer routing comes from the cloud snapshot's settings.print block
//     (company KOT printer + optional dine-in counter copy). Station split is
//     a cloud-only feature; offline = ONE full slip on the company KOT printer.
//   * A failed print leaves the job queued with backoff (paper out / printer
//     off) — the kitchen must still get the slip once the printer answers.
//   * The cloud receives the same document inside the order.held event and
//     records a LOCAL HANDOFF for the order's kitchen lines (it does NOT stamp
//     them printed yet). Only the durable acknowledgement that the kitchen
//     slip really came out — a print.complete command on the job aggregate,
//     synced through the outbox — stamps the lines in the cloud.
//   * Handoff timeout: once the cloud has ACCEPTED the order.held event, the
//     shop PC gets LOCAL_KOT_HANDOFF_MS to print. After that it stops trying
//     and hands the slip back (print.fail{terminal}); the cloud prints it
//     through the normal KOT path (immediately on that event, or on its own
//     longer expiry when no acknowledgement ever arrives — agent dead/state
//     lost). Local < cloud window ⇒ never two kitchen slips.
//   * While the hold is still unsynced (internet down) there is no cloud
//     printer to hand back to, so the drain keeps retrying with backoff.

// Must stay well BELOW KotPrintService::LOCAL_HANDOFF_TIMEOUT_SECONDS (cloud
// side, 5 min): both windows start at the same real instant (cloud accepts
// the hold ⇔ outbox marks it sent), so the gap only has to cover clock skew
// between "accepted" and "marked sent" — seconds, not minutes.
const LOCAL_KOT_HANDOFF_MS = 3 * 60 * 1000;

function esc(value) {
  return String(value == null ? '' : value)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function pad2(n) { return String(n).padStart(2, '0'); }

function stamp(ms) {
  const d = new Date(Number.isFinite(Number(ms)) ? Number(ms) : Date.now());
  return `${pad2(d.getDate())}-${pad2(d.getMonth() + 1)}-${d.getFullYear()} ${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
}

function orderTypeLabel(type) {
  switch (String(type || '')) {
    case 'dine_in': return 'DINE-IN';
    case 'delivery': return 'DELIVERY';
    case 'takeaway': return 'TAKEAWAY';
    default: return String(type || '').toUpperCase();
  }
}

/**
 * Render a kitchen slip from a structured Local Core KOT document.
 * @param {object} doc  kot_document as stored in print_queue[..].document
 * @param {object} opts { copyLabel?, settings? (settings.print block), printedAtMs? }
 */
function renderKotHtml(doc, opts) {
  const o = opts || {};
  const settings = o.settings || {};
  const lines = Array.isArray(doc && doc.lines) ? doc.lines : [];
  const marginMm = Math.max(0, Math.min(30, Number(settings.kot_left_margin_mm) || 0));
  const alignCenter = settings.kot_align_center === true;
  const compact = settings.kot_compact === true;
  const header = [];
  const type = orderTypeLabel(doc && doc.order_type);
  if (doc && doc.table_label) header.push(`<div class="text-xl bold">TABLE ${esc(doc.table_label)}</div>`);
  if (doc && doc.token_label) header.push(`<div class="text-xl bold">${esc(doc.token_label)}</div>`);
  header.push(`<div class="text-lg bold">${esc(type)}${doc && doc.priority ? ' &mdash; URGENT' : ''}</div>`);
  const meta = [];
  meta.push(`<span>Order: <b>${esc(doc && (doc.order_label || doc.order_number || ''))}</b></span>`);
  meta.push(`<span>${esc(stamp(o.printedAtMs || (doc && doc.requested_at_ms)))}</span>`);
  const who = [];
  if (doc && doc.waiter_name) who.push(`Waiter: ${esc(doc.waiter_name)}`);
  if (doc && doc.customer_name) who.push(`Customer: ${esc(doc.customer_name)}`);
  const rows = lines.map((line) => {
    const qty = Number(line.quantity) || 0;
    const note = line.special_notes ? `<div class="note">&#8594; ${esc(line.special_notes)}</div>` : '';
    return `<tr><td class="name">${esc(line.name)}${note}</td><td class="qty">${esc(qty)}</td></tr>`;
  }).join('');
  const kitchenNotes = doc && doc.kitchen_notes
    ? `<div class="notes bold">NOTE: ${esc(doc.kitchen_notes)}</div>` : '';
  const copy = o.copyLabel ? `<div class="text-sm bold text-center">${esc(o.copyLabel)}</div>` : '';
  return `<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>KOT ${esc(doc && doc.order_label)}</title>
<style>
@page { size: 80mm auto; margin: 0; }
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: Arial, 'Helvetica Neue', Helvetica, 'Segoe UI', sans-serif; font-size: 13px; width: 72mm; max-width: 72mm;
  padding: ${compact ? 1 : 3}mm; background: #fff; color: #000; line-height: 1.3; }
@media print { body { margin: ${alignCenter ? '0 auto' : '0'}; margin-left: ${alignCenter ? 'auto' : marginMm + 'mm'}; } }
.bold { font-weight: bold; } .text-center { text-align: center; } .text-lg { font-size: 16px; } .text-xl { font-size: 22px; }
.text-sm { font-size: 11px; } .sep { border-top: 2px dashed #000; margin: 4px 0; }
.flex { display: flex; justify-content: space-between; align-items: center; font-size: 12px; }
table { width: 100%; border-collapse: collapse; margin: 4px 0; }
td { padding: 3px 2px; vertical-align: top; font-size: 14px; }
td.qty { width: 15%; font-weight: bold; font-size: 17px; text-align: right; padding-right: 4px; }
td.name { width: 85%; font-weight: 600; border-bottom: 1px dashed #000; }
tr.head td { border-top: 2px solid #000; border-bottom: 2px solid #000; font-weight: bold; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; }
.note { font-size: 15px; font-weight: 900; -webkit-text-stroke: 0.5px #000; padding-left: 10px; letter-spacing: 0.5px; }
.notes { margin-top: 6px; font-size: 14px; border: 2px solid #000; padding: 4px; }
.offline { margin-top: 6px; font-size: 10px; text-align: center; }
</style></head><body>
<div class="text-center bold text-lg">KITCHEN ORDER</div>
${copy}
<div class="text-center">${header.join('')}</div>
<div class="sep"></div>
<div class="flex">${meta.join('')}</div>
${who.length ? `<div class="text-sm">${who.join(' &middot; ')}</div>` : ''}
<table><tr class="head"><td class="name">Item</td><td class="qty">Qty</td></tr>${rows}</table>
${kitchenNotes}
<div class="sep"></div>
<div class="offline">Offline slip &mdash; shop PC (net down). Cloud copy syncs later.</div>
</body></html>`;
}

/**
 * Decide the physical prints for one local KOT job.
 * Returns [] when the shop has no silent KOT printer configured.
 */
function planKotPrints(job, printSettings) {
  const s = printSettings || {};
  const doc = (job && job.document) || {};
  const plan = [];
  if (s.silent_print_enabled === false) return plan;
  if (s.kot_printer) plan.push({ printer: String(s.kot_printer), copyLabel: null });
  if (s.counter_kot_enabled === true && s.counter_kot_printer && String(doc.order_type) === 'dine_in') {
    plan.push({ printer: String(s.counter_kot_printer), copyLabel: 'COUNTER COPY' });
  }
  return plan;
}

/**
 * Drain the domain's local-only KOT queue once.
 * @param {object} domain  LocalCoreDomain (needs localPrintJobs/claimLocalPrint/finishLocalPrint)
 * @param {object} deps    { printHtml(html, printer, type) → {success,error}, deviceId, log, now }
 * @returns {Promise<{printed:number, failed:number, skipped:number}>}
 */
/** True once the cloud has known this order longer than the local handoff window. */
function handoffExpired(job, nowMs, handoffMs) {
  const synced = Number(job && job.hold_synced_at_ms);
  if (!Number.isFinite(synced) || synced <= 0) return false;
  return nowMs - synced >= (Number.isFinite(handoffMs) ? handoffMs : LOCAL_KOT_HANDOFF_MS);
}

// Durable acknowledgement to the cloud. `ok` → print.complete (cloud stamps
// the lines); otherwise print.fail{terminal:true} (cloud prints the slip).
// Returns false when the domain refused (no scope / permission) so the
// caller can fall back to the local finish and keep the job honest.
function ackLocalPrint(domain, job, ok, error, deps, nowMs) {
  const scope = deps && deps.scope;
  if (!scope || typeof domain.execute !== 'function' || typeof domain.localPrintAckInput !== 'function') return false;
  const ack = domain.localPrintAckInput(job.id);
  if (!ack) return false;
  const payload = { kind: 'kot', order_id: ack.order_id, claim_token: ack.claim_token,
    printed_at_ms: ok ? nowMs : undefined, terminal: ok ? undefined : true,
    error: ok ? undefined : String(error || 'print_failed').slice(0, 240) };
  Object.keys(payload).forEach((key) => { if (payload[key] === undefined) delete payload[key]; });
  domain.execute({
    v: 1, id: 'kot-ack:' + job.id + ':' + (ok ? 'ok' : 'fail') + ':' + job.attempts,
    type: ok ? 'print.complete' : 'print.fail', aggregate_id: job.id,
    expected_revision: ack.expected_revision, at_ms: nowMs, scope, payload,
  });
  return true;
}

async function drainLocalKotQueue(domain, deps) {
  const log = (deps && deps.log) || (() => {});
  const now = (deps && deps.now) || Date.now;
  const handoffMs = deps && Number.isFinite(deps.handoffMs) ? deps.handoffMs : LOCAL_KOT_HANDOFF_MS;
  const out = { printed: 0, failed: 0, skipped: 0, handed_back: 0, acked: 0 };
  if (!domain || typeof domain.localPrintJobs !== 'function') return out;
  let jobs;
  try { jobs = domain.localPrintJobs(now()); } catch (e) { return out; }
  if (!jobs.length) return out;
  let printSettings = {};
  try { printSettings = ((domain.snapshot() || {}).settings || {}).print || {}; } catch (e) {}
  for (const job of jobs) {
    if (job.kind !== 'kot') { out.skipped += 1; continue; }
    const plan = planKotPrints(job, printSettings);
    let claimed;
    try { claimed = domain.claimLocalPrint(job.id, deps && deps.deviceId); }
    catch (e) { out.skipped += 1; continue; } // another drain tick already owns it
    // Handoff window over (cloud accepted the hold ≥ LOCAL_KOT_HANDOFF_MS ago):
    // do NOT print — the cloud is about to (or already may) own this slip.
    // Give it back explicitly so the cloud prints right away instead of
    // waiting out its own expiry. Checked BEFORE printing, also on restart.
    if (handoffExpired(job, now(), handoffMs)) {
      let acked = false;
      try { acked = ackLocalPrint(domain, job, false, 'local_kot_handoff_timeout', deps, now()); }
      catch (e) { log(`[local-kot] ${job.id} hand-back failed: ${e && e.message}`); }
      if (!acked) { try { domain.finishLocalPrint(job.id, claimed.claim_token, false, 'local_kot_handoff_timeout'); } catch (e) {} }
      log(`[local-kot] ${job.id} handed back to cloud (handoff window over${acked ? '' : ', ack unavailable'})`);
      out.handed_back += 1;
      continue;
    }
    if (!plan.length) {
      // No silent KOT printer known for this shop yet (settings arrive with the
      // first cloud snapshot). Keep the slip queued with backoff — never drop it.
      try { domain.finishLocalPrint(job.id, claimed.claim_token, false, 'kot_printer_not_configured'); } catch (e) {}
      out.failed += 1;
      continue;
    }
    let kitchenOk = false; let lastError = null;
    for (let i = 0; i < plan.length; i++) {
      const target = plan[i];
      let result;
      try {
        const html = renderKotHtml(job.document, { copyLabel: target.copyLabel, settings: printSettings, printedAtMs: now() });
        result = await deps.printHtml(html, target.printer, 'kot');
      } catch (e) { result = { success: false, error: (e && e.message) || 'print_failed' }; }
      if (i === 0) kitchenOk = !!(result && result.success);
      if (!(result && result.success)) lastError = (result && result.error) || 'print_failed';
      log(`[local-kot] ${job.id} → "${target.printer}"${target.copyLabel ? ' (' + target.copyLabel + ')' : ''}: ${result && result.success ? 'ok' : 'FAILED ' + lastError}`);
      // Kitchen slip failed → stop here. The retry prints the whole plan again,
      // so printing the counter copy now would duplicate it later.
      if (i === 0 && !kitchenOk) break;
    }
    // The kitchen slip decides the job. A failed counter copy is logged but
    // never re-prints the kitchen's ticket.
    if (kitchenOk) {
      // Durable ack: the cloud stamps these lines printed only from this
      // event. If the ack cannot be issued (no trusted scope yet) the job is
      // finished locally and the cloud's own expiry re-prints — one extra
      // slip is the honest failure mode, a silent stamp is not.
      let acked = false;
      try { acked = ackLocalPrint(domain, job, true, null, deps, now()); }
      catch (e) { log(`[local-kot] ${job.id} ack failed: ${e && e.message}`); }
      if (!acked) { try { domain.finishLocalPrint(job.id, claimed.claim_token, true, null); } catch (e) {} }
      else out.acked += 1;
      out.printed += 1;
    } else {
      try { domain.finishLocalPrint(job.id, claimed.claim_token, false, lastError); } catch (e) {}
      out.failed += 1;
    }
  }
  return out;
}

module.exports = { renderKotHtml, planKotPrints, drainLocalKotQueue, handoffExpired, LOCAL_KOT_HANDOFF_MS };
