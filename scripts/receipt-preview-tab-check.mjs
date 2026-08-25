#!/usr/bin/env node
// Receipt Settings live-preview tab check (Task 1377).
//
// Owner voice note 21 Aug 2026: "Local tab par tick badalne se preview nahi
// badalta" — the preview seemed permanently stuck on the PRA bill, so the shop
// believed the Local settings were not being applied at all. (The real cause
// was a runtime-cached copy of the page; this check locks the contract so a
// future edit cannot make the complaint true.)
//
// It extracts the REAL window.rcptThemePicker source out of
// resources/views/pos/partials/receipt-theme-preview.blade.php and runs it
// against a stubbed form carrying the REAL field names from
// resources/views/pos/receipt-settings.blade.php, then asserts:
//   • on the PRA tab the preview reads rp_*, on the Local tab it reads lp_*;
//   • flipping a Local checkbox changes the preview after sync();
//   • the Menu-QR toggle gates ONLY the local bill's QR — the PRA fiscal
//     (Sahulat) QR always previews on;
//   • the two panels' checkbox names still exist in the settings blade, so the
//     stub can never silently drift away from the real page.
//
// Run: node scripts/receipt-preview-tab-check.mjs

import { readFileSync } from 'node:fs';

const partial = readFileSync(
  new URL('../resources/views/pos/partials/receipt-theme-preview.blade.php', import.meta.url), 'utf8');
const settings = readFileSync(
  new URL('../resources/views/pos/receipt-settings.blade.php', import.meta.url), 'utf8');

const fail = (msg) => { console.error('RECEIPT-PREVIEW FAIL: ' + msg); process.exit(1); };

// ── 1. Blade sync: the stub below must mirror the real page's field names ────
const PAIRED = ['show_address', 'show_ntn', 'show_email', 'show_mobile', 'show_cashier',
                'show_business_name', 'show_developed_by', 'show_footer', 'show_tax'];
for (const key of PAIRED) {
  for (const pre of ['rp_', 'lp_']) {
    if (!settings.includes(`name="${pre}${key}"`)) {
      fail(`receipt-settings.blade.php no longer has name="${pre}${key}" — preview stub is out of sync`);
    }
  }
}
if (!settings.includes(`x-data='rcptThemePicker(`)) fail('settings page no longer mounts rcptThemePicker');
// The tab buttons MUST share the preview's Alpine scope, or `tab` never reaches it.
const wrapper = settings.indexOf("x-data='rcptThemePicker(");
for (const marker of [`@click="tab = 'pra'"`, `@click="tab = 'local'"`, `receipt-theme-preview`]) {
  const at = settings.indexOf(marker, wrapper);
  if (at === -1) fail(`"${marker}" must live INSIDE the rcptThemePicker wrapper — otherwise tab changes never reach the preview`);
}

// ── 2. Extract the real component factory ───────────────────────────────────
const start = partial.indexOf('window.rcptThemePicker = function (cfg) {');
if (start === -1) fail('window.rcptThemePicker factory not found in the preview partial');
let depth = 0, i = partial.indexOf('{', partial.indexOf('function (cfg)', start));
let end = -1;
for (; i < partial.length; i++) {
  if (partial[i] === '{') depth++;
  else if (partial[i] === '}') { depth--; if (depth === 0) { end = i + 1; break; } }
}
if (end === -1) fail('unbalanced braces in the rcptThemePicker factory');
const factorySrc = partial.slice(partial.indexOf('function (cfg)', start), end);

// ── 3. Minimal DOM stub with the real field names ───────────────────────────
function makeForm(state) {
  const nodes = {};
  const chk = (name, checked) => { nodes[name] = { type: 'checkbox', checked: !!checked, value: '1' }; };
  for (const key of PAIRED) {
    chk('rp_' + key, state.rp[key]);
    chk('lp_' + key, state.lp[key]);
  }
  chk('rp_show_logo', true);
  chk('rp_logo_finals_only', false);
  chk('rp_show_menu_qr', state.menuQr);
  chk('rp_show_verify_line', true);
  nodes['rp_footer_text'] = { type: 'text', value: state.rp.footer_text || '' };
  nodes['lp_footer_text'] = { type: 'text', value: state.lp.footer_text || '' };
  nodes['rp_printer_size'] = { type: 'text', value: '80mm' };

  return {
    addEventListener() {},
    querySelector(sel) {
      const m = sel.match(/name="([^"]+)"/);
      if (!m) return null;
      const el = nodes[m[1]];
      if (!el) return null;
      if (sel.includes('type="checkbox"') && el.type !== 'checkbox') return null;
      if (sel.endsWith(':checked') && !el.checked) return null;
      return el;
    },
    querySelectorAll() { return []; },
    _nodes: nodes,
  };
}

function build(state) {
  const form = makeForm(state);
  globalThis.document = { getElementById: (id) => (id === 'rcptSettingsForm' ? form : null), querySelectorAll: () => [] };
  // eslint-disable-next-line no-new-func
  const factory = new Function('return (' + factorySrc + ');')();
  const c = factory({
    theme: 'classic',
    themes: { classic: { bold: true, logo: 'center' } },
    mode: 'pra',
    live: true,
    formId: 'rcptSettingsForm',
    paper: '80mm',
    prefs: { tax: true, menuQr: true, logo: true, logoFinalsOnly: false, orderMatch: 'off' },
  });
  c.$watch = () => {};        // Alpine's watcher — driven manually below
  c.$root = null;
  c.init();
  return { c, form };
}

const assert = (cond, msg) => { if (!cond) fail(msg); };

// ── 4. The owner's exact scenario ───────────────────────────────────────────
// PRA panel: tax ON, address ON.   Local panel: tax OFF, address OFF.  QR OFF.
const { c, form } = build({
  menuQr: false,
  rp: { show_tax: true, show_address: true, show_ntn: true },
  lp: { show_tax: false, show_address: false, show_ntn: false },
});

c.tab = 'pra'; c.sync();
assert(c.p.tax === true, 'PRA tab preview must read rp_show_tax (got tax=' + c.p.tax + ')');
assert(c.p.address === true, 'PRA tab preview must read rp_show_address');
assert(c.serialNow() === 'P-817', 'PRA tab must preview a PRA (short P-) serial');
assert(c.qrNow() === true, 'PRA fiscal (Sahulat) QR must ALWAYS preview on, Menu-QR switch or not');

c.tab = 'local'; c.sync();
assert(c.p.tax === false, 'Local tab preview must read lp_show_tax, not rp_show_tax');
assert(c.p.address === false, 'Local tab preview must read lp_show_address');
assert(c.serialNow() === 'L-0817', 'Local tab must preview an L-series serial');
assert(c.qrNow() === false, 'Menu QR off must remove the QR from the LOCAL bill preview');

// Ticking Local "show tax" must change the preview on the very next sync.
form._nodes['lp_show_tax'].checked = true;
c.sync();
assert(c.p.tax === true, 'Ticking the Local tax box must update the preview immediately');
assert(c.p.address === false, 'Ticking one Local box must not drag in the PRA values');

// ...and back on the PRA tab the PRA values are still the ones shown.
c.tab = 'pra'; c.sync();
assert(c.p.tax === true && c.p.address === true, 'PRA tab must still show the PRA set after editing Local');

// Menu QR back on → the local preview shows a QR again.
form._nodes['rp_show_menu_qr'].checked = true;
c.tab = 'local'; c.sync();
assert(c.qrNow() === true, 'Menu QR on must bring the local bill preview QR back');

console.log('receipt-preview-tab-check OK — preview follows the open tab (PRA=rp_, Local=lp_), Menu-QR gates the local QR only');
