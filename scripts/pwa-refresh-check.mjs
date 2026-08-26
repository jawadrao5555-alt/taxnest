#!/usr/bin/env node
// PWA refresh-button regression check (Task 706 review): extracts the REAL
// inline script from resources/views/components/pwa-refresh-btn.blade.php and
// executes it against a stubbed DOM/ServiceWorker environment to assert the
// click contract:
//   A. slow SW install (finishes well after the old fixed-1s race window) →
//      the button KEEPS WAITING and applies the update (no false "no update").
//   B. no SW update → one full reload with the sessionStorage latest-flag set;
//      after "reload" the green "you're on the latest version" toast shows.
//   C. offline → error toast, NO reload, busy released (a later click works).
//   D. install slower than the 20s bound → NO reload (old worker would serve
//      the old version), amber "it will apply itself" toast, spinner KEEPS
//      running; when the install finally completes it applies WITHOUT a second
//      click (owner, 26 Aug 2026: one press must finish the whole update).
//   E. the press survives the reload it caused: an update discovered on the NEXT
//      page load applies itself — still one press in total.
//   F. armed but nothing actually waiting (stale badge) → no reload, no apply:
//      the auto-apply path can never start a reload loop.
//   G. once the armed window has expired, a waiting update only raises the
//      badge — no surprise reload while the counter is working.
// All script timers are scaled 20x faster (20s bound → 1s) so the whole suite
// runs in a couple of seconds; relative ordering is preserved because the
// simulated events use the same scaled clock.
import { readFileSync } from 'node:fs';

const blade = readFileSync(new URL('../resources/views/components/pwa-refresh-btn.blade.php', import.meta.url), 'utf8');

const fail = (msg) => { console.error('PWA-REFRESH FAIL: ' + msg); process.exit(1); };
const ok = (msg) => console.log('  ok: ' + msg);

const m = blade.match(/<script>([\s\S]*)<\/script>/);
if (!m) fail('no <script> block found in pwa-refresh-btn.blade.php');
// Replace @json(__('pos.key')) with the KEY name as a JS string so assertions
// can match messages by lang key (real values come from the lang catalogs).
const src = m[1].replace(/@json\(__\('pos\.([a-z_]+)'\)\)/g, (_, k) => JSON.stringify(k));
if (/@json|__\(/.test(src)) fail('unreplaced Blade expression left in script — update the harness pattern');

const SCALE = 1 / 20; // 20s → 1s

function makeEnv({ onLine = true, store = new Map(), reg = null } = {}) {
  const sSetTimeout = (fn, ms) => setTimeout(fn, Math.max(1, (ms || 0) * SCALE));
  const sClearTimeout = (id) => clearTimeout(id);

  const makeClassList = (el) => ({
    add: (c) => el.classes.add(c),
    remove: (c) => el.classes.delete(c),
    contains: (c) => el.classes.has(c),
  });
  const makeEl = (id) => {
    const el = {
      id, classes: new Set(), style: {}, title: '', textContent: '', attrs: {},
      listeners: {},
      addEventListener(t, f) { (this.listeners[t] ||= []).push(f); },
      setAttribute(k, v) { this.attrs[k] = v; },
      remove() { el.removed = true; },
    };
    el.classList = makeClassList(el);
    return el;
  };

  const btn = makeEl('tnPwaRefreshBtn');
  const badge = makeEl('tnPwaRefreshBadge');
  const toasts = [];
  const docListeners = {};
  const document = {
    getElementById: (id) => (id === 'tnPwaRefreshBtn' ? btn : id === 'tnPwaRefreshBadge' ? badge : null),
    addEventListener(t, f) { (docListeners[t] ||= []).push(f); },
    dispatchEvent(ev) { (docListeners[ev.type] || []).forEach((f) => f(ev)); },
    createElement: () => { const el = makeEl(null); el.style = { cssText: '' }; return el; },
    body: { appendChild: (el) => toasts.push(el) },
    hidden: false,
  };
  const env = {
    window: {},
    document,
    navigator: { onLine, serviceWorker: { controller: {}, getRegistration: async () => reg } },
    sessionStorage: {
      getItem: (k) => (store.has(k) ? store.get(k) : null),
      setItem: (k, v) => store.set(k, String(v)),
      removeItem: (k) => store.delete(k),
    },
    location: { reloads: 0, reload() { this.reloads++; } },
    requestAnimationFrame: (fn) => sSetTimeout(fn, 0),
    setTimeout: sSetTimeout,
    clearTimeout: sClearTimeout,
    CustomEvent: class CustomEvent { constructor(type, opts) { this.type = type; Object.assign(this, opts || {}); } },
    btn, badge, toasts, store,
    applyCalls: 0,
  };
  env.navigator.onLineSet = (v) => { env.navigator.onLine = v; };
  env.window.tnPwaApplyWaitingUpdate = () => { env.applyCalls++; };
  const run = new Function(
    'window', 'document', 'navigator', 'sessionStorage', 'location', 'requestAnimationFrame', 'setTimeout', 'clearTimeout', 'CustomEvent',
    src
  );
  run(env.window, env.document, env.navigator, env.sessionStorage, env.location, env.requestAnimationFrame, env.setTimeout, env.clearTimeout, env.CustomEvent);
  env.click = () => btn.listeners['click'].forEach((f) => f({ preventDefault() {} }));
  return env;
}

function makeReg() {
  return {
    waiting: null, installing: null, listeners: {},
    addEventListener(t, f) { (this.listeners[t] ||= []).push(f); },
    fire(t) { (this.listeners[t] || []).forEach((f) => f()); },
    updateImpl: async () => {},
    update() { return this.updateImpl(); },
  };
}
function makeWorker(state) {
  return {
    state, listeners: {},
    addEventListener(t, f) { (this.listeners[t] ||= []).push(f); },
    setState(s) { this.state = s; (this.listeners['statechange'] || []).forEach((f) => f()); },
  };
}
const sleepSim = (ms) => new Promise((r) => setTimeout(r, Math.max(1, ms * SCALE)));

// ---------- Scenario A: slow install (3s — past the old fixed 1s race) ----------
{
  const reg = makeReg();
  const env = makeEnv({ reg });
  reg.updateImpl = async () => {
    const w = makeWorker('installing');
    reg.installing = w;
    reg.fire('updatefound');
    setTimeout(() => { reg.waiting = w; reg.installing = null; w.setState('installed'); }, 3000 * SCALE);
  };
  await sleepSim(100); // let init settle
  env.click();
  await sleepSim(1500); // OLD code decided at 1000ms — assert we are still waiting
  if (env.location.reloads > 0 || env.applyCalls > 0) fail('A: decided before install finished (old 1s race back?)');
  if (!env.btn.classes.has('tn-spinning')) fail('A: spinner dropped while still installing');
  if (env.toasts.length) fail('A: toast shown while still installing');
  await sleepSim(2500);
  if (env.applyCalls !== 1) fail('A: waiting SW installed but tnPwaApplyWaitingUpdate not called (applyCalls=' + env.applyCalls + ')');
  if (env.location.reloads !== 0) fail('A: raw reload instead of apply helper');
  ok('A: slow 3s install → kept spinning past old 1s race, applied on installed');
}

// ---------- Scenario B: no update → reload once + post-reload latest toast ----------
{
  const store = new Map();
  const reg = makeReg(); // update() resolves, no new worker
  const env = makeEnv({ reg, store });
  await sleepSim(100);
  env.click();
  await sleepSim(1200); // grace 800 + margin
  if (env.applyCalls !== 0) fail('B: no update but apply called');
  if (env.location.reloads !== 1) fail('B: no-update click must reload exactly once (got ' + env.location.reloads + ')');
  if (store.get('tnPwaLatestToast') !== '1') fail('B: latest-flag not set before reload');
  // simulate the post-reload boot with the same sessionStorage
  const env2 = makeEnv({ reg: makeReg(), store });
  await sleepSim(100);
  const latest = env2.toasts.find((t) => t.textContent === 'pwa_on_latest');
  if (!latest) fail('B: post-reload green "on latest" toast missing');
  if ((latest.attrs['data-tn-pwa-toast'] || 'ok') !== 'ok') fail('B: latest toast is not the ok/green kind');
  if (store.has('tnPwaLatestToast')) fail('B: latest-flag not consumed after showing toast');
  ok('B: no update → one reload with flag; post-reload shows green on-latest toast and consumes flag');
}

// ---------- Scenario C: offline → error toast, no reload, busy released ----------
{
  const reg = makeReg();
  const env = makeEnv({ reg, onLine: false });
  await sleepSim(100);
  env.click();
  await sleepSim(300);
  if (env.location.reloads !== 0) fail('C: offline click must never reload');
  const t = env.toasts.find((x) => x.textContent === 'pwa_offline_no_check');
  if (!t) fail('C: offline error toast missing');
  if (t.attrs['data-tn-pwa-toast'] !== 'err') fail('C: offline toast is not the err kind');
  if (env.btn.classes.has('tn-spinning')) fail('C: spinner stuck after offline click');
  // back online → a later click must still work (busy released)
  env.navigator.onLine = true;
  env.click();
  await sleepSim(1200);
  if (env.location.reloads !== 1) fail('C: click after going back online did not reload (busy flag stuck?)');
  ok('C: offline → err toast, no reload; busy released (next online click reloads)');
}

// ---------- Scenario D: install slower than the 20s bound → keeps going, applies itself ----------
{
  const reg = makeReg();
  const env = makeEnv({ reg });
  let worker;
  reg.updateImpl = async () => {
    worker = makeWorker('installing');
    reg.installing = worker;
    reg.fire('updatefound');
    // never finishes within the bound
  };
  await sleepSim(100);
  env.click();
  await sleepSim(21000); // past the 20s hard bound
  if (env.location.reloads !== 0) fail('D: timeout must NOT reload (old worker would serve the old version)');
  if (env.applyCalls !== 0) fail('D: timeout must not apply');
  const t = env.toasts.find((x) => x.textContent === 'pwa_will_auto_apply');
  if (!t) fail('D: "it will apply itself" toast missing on timeout');
  if (t.attrs['data-tn-pwa-toast'] !== 'info') fail('D: timeout toast is not the info kind');
  if (!env.btn.classes.has('tn-spinning')) fail('D: spinner dropped while the download is still running');
  // download finally completes → applies ITSELF, no second click
  reg.waiting = worker; reg.installing = null; worker.setState('installed');
  await sleepSim(300);
  if (env.applyCalls !== 1) fail('D: late install did not apply itself (owner had to click again) applyCalls=' + env.applyCalls);
  if (env.location.reloads !== 0) fail('D: raw reload instead of apply helper');
  ok('D: >20s install → no reload, info toast, spinner keeps running; late install applies itself (one press)');
}

// ---------- Scenario E: the press survives the reload it caused ----------
{
  const store = new Map();
  const env = makeEnv({ reg: makeReg(), store });   // click finds no update → reload
  await sleepSim(100);
  env.click();
  await sleepSim(1200);
  if (env.location.reloads !== 1) fail('E: no-update click must reload once');
  // …the deploy's worker lands on the NEXT page load: it must apply itself.
  const reg2 = makeReg();
  reg2.waiting = makeWorker('installed');
  const env2 = makeEnv({ reg: reg2, store });
  await sleepSim(300);
  if (env2.applyCalls !== 1) fail('E: update found after the reload did not apply itself (second click needed) applyCalls=' + env2.applyCalls);
  if (store.has('tnPwaAutoApply')) fail('E: armed intent not cleared after it was used');
  ok('E: update appearing after the reload applies itself — still one press');
}

// ---------- Scenario F: armed + stale badge → never a reload loop ----------
{
  const store = new Map([['tnPwaAutoApply', String(Date.now() + 60000)]]);
  const reg = makeReg(); // nothing waiting — the badge is stale
  const env = makeEnv({ reg, store });
  await sleepSim(100);
  env.document.dispatchEvent(new env.CustomEvent('tn-pwa-update-available'));
  await sleepSim(400);
  if (env.location.reloads !== 0) fail('F: stale badge while armed caused a reload (loop risk)');
  if (env.applyCalls !== 0) fail('F: stale badge while armed applied a non-existent update');
  if (env.badge.style.display !== 'inline-block') fail('F: badge should still be shown for the user to press');
  ok('F: armed + nothing waiting → badge only, no reload, no apply');
}

// ---------- Scenario G: expired arm → badge only, no surprise reload ----------
{
  const store = new Map([['tnPwaAutoApply', String(Date.now() - 1000)]]);
  const reg = makeReg();
  reg.waiting = makeWorker('installed');
  const env = makeEnv({ reg, store });
  await sleepSim(300);
  if (env.applyCalls !== 0) fail('G: expired arm still auto-applied (counter would be reloaded mid-sale)');
  if (env.badge.style.display !== 'inline-block') fail('G: waiting update must still raise the badge');
  ok('G: expired arm → badge only, no automatic reload');
}

console.log('PWA-REFRESH CHECK PASSED (slow-install wait, no-update reload+toast, offline guard, one-press auto-apply across reload, loop guards)');
process.exit(0);
