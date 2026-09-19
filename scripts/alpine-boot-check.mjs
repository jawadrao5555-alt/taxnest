#!/usr/bin/env node
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const root = new URL('..', import.meta.url);
const partial = readFileSync(new URL('resources/views/partials/alpine-runtime-loader.blade.php', root), 'utf8');
const app = readFileSync(new URL('resources/js/app.js', root), 'utf8');
const pra = readFileSync(new URL('resources/views/layouts/pos-app.blade.php', root), 'utf8');
const fbr = readFileSync(new URL('resources/views/layouts/fbr-pos-app.blade.php', root), 'utf8');

const fail = message => {
  console.error('ALPINE BOOT FAIL: ' + message);
  process.exit(1);
};
const assert = (value, message) => { if (!value) fail(message); };

const script = partial.match(/<script data-tn-alpine-runtime>([\s\S]*?)<\/script>/)?.[1];
assert(script, 'shared runtime script was not found');

const makeRuntime = () => ({
  starts: 0,
  plugins: 0,
  plugin() { this.plugins += 1; },
  start() { this.starts += 1; },
});

function harness(loadFallback) {
  let dcl;
  let timerId = 0;
  const timers = new Map();
  const events = [];
  const document = {
    readyState: 'loading',
    addEventListener(name, handler) {
      if (name === 'DOMContentLoaded') dcl = handler;
    },
  };
  const window = {
    __tnLoadAlpineFallback: loadFallback,
    dispatchEvent(event) { events.push(event); },
  };
  const context = {
    window,
    document,
    CustomEvent: class CustomEvent {
      constructor(type, init = {}) { this.type = type; this.detail = init.detail; }
    },
    setTimeout(callback) {
      const id = ++timerId;
      timers.set(id, callback);
      return id;
    },
    clearTimeout(id) { timers.delete(id); },
    console,
  };
  vm.createContext(context);
  vm.runInContext(script, context, { filename: 'alpine-runtime-loader.blade.php' });
  assert(typeof window.__tnStartAlpine === 'function', 'coordinator was not installed');
  assert(typeof dcl === 'function', 'fallback was not deferred until DOMContentLoaded');
  return {
    window,
    events,
    fireDcl() { dcl(); },
    pendingTimers() { return timers.size; },
    runTimer() {
      const next = timers.entries().next().value;
      assert(next, 'expected fallback timer was not armed');
      timers.delete(next[0]);
      return Promise.resolve(next[1]());
    },
  };
}

function assertSingleStart(test, runtime, source) {
  assert(runtime.starts === 1, `${test}: Alpine did not start exactly once`);
  assert(runtime.plugins === 1, `${test}: plugin was not registered exactly once`);
  assert(source.window.__tnAlpineBoot.status === 'started', `${test}: boot did not reach started`);
  assert(source.window.__tnAlpineBoot.starts === 1, `${test}: boot counter is not one`);
  assert(source.window.__alpineStarted === true, `${test}: completion flag was not set`);
  assert(source.events.filter(event => event.type === 'tn:alpine-started').length === 1, `${test}: start event count is not one`);
}

// Vite arrives before DOMContentLoaded: fallback never even arms.
{
  const fallbackRuntime = makeRuntime();
  const vite = makeRuntime();
  const test = harness(async () => [{ default: fallbackRuntime }, { default: () => {} }]);
  test.window.__tnStartAlpine(vite, [() => {}], 'vite');
  test.fireDcl();
  assert(test.pendingTimers() === 0, 'vite-before-DCL: fallback timer was armed');
  assertSingleStart('vite-before-DCL', vite, test);
  assert(fallbackRuntime.starts === 0, 'vite-before-DCL: fallback started');
  assert(test.window.__tnAlpineBoot.source === 'vite', 'vite-before-DCL: wrong source');
}

// CDN imports are in flight when Vite arrives: Vite wins and the resolved
// fallback is a strict no-op.
{
  let resolveImport;
  const fallbackRuntime = makeRuntime();
  const vite = makeRuntime();
  const importPromise = new Promise(resolve => { resolveImport = resolve; });
  const test = harness(() => importPromise);
  test.fireDcl();
  const fallbackWork = test.runTimer();
  await Promise.resolve();
  assert(test.window.__tnAlpineBoot.status === 'fallback-loading', 'vite-during-import: fallback did not enter loading');
  test.window.__tnStartAlpine(vite, [() => {}], 'vite');
  resolveImport([{ default: fallbackRuntime }, { default: () => {} }]);
  await fallbackWork;
  assertSingleStart('vite-during-import', vite, test);
  assert(fallbackRuntime.starts === 0 && fallbackRuntime.plugins === 0, 'vite-during-import: fallback was not a no-op');
  assert(test.window.__tnAlpineBoot.source === 'vite', 'vite-during-import: fallback replaced source');
}

// Vite never arrives: fallback starts. A later Vite execution cannot start a
// second runtime or register its plugin.
{
  const fallbackRuntime = makeRuntime();
  const vite = makeRuntime();
  const test = harness(async () => [{ default: fallbackRuntime }, { default: () => {} }]);
  test.fireDcl();
  await test.runTimer();
  assertSingleStart('fallback-only', fallbackRuntime, test);
  assert(test.window.__tnAlpineBoot.source === 'cdn-esm', 'fallback-only: wrong source');
  test.window.__tnStartAlpine(vite, [() => {}], 'vite');
  assert(vite.starts === 0 && vite.plugins === 0, 'late-vite: second runtime started or registered a plugin');
  assert(test.window.__tnAlpineBoot.starts === 1, 'late-vite: boot counter changed');
  assert(test.window.__tnAlpineBoot.source === 'cdn-esm', 'late-vite: source changed after fallback start');
}

assert(app.includes("startAlpine(Alpine, [collapse], 'vite')"), 'Vite entry does not use coordinator');
assert(!app.includes('if (!window.__alpineStarted)'), 'Vite entry still uses the racy legacy guard');
for (const [name, layout] of [['PRA', pra], ['FBR', fbr]]) {
  assert(layout.includes("@include('partials.alpine-runtime-loader')"), `${name} layout does not use shared runtime`);
  assert(!layout.includes('__alpineFallbackLoading'), `${name} layout still contains legacy fallback state`);
  assert(!layout.includes('/dist/cdn.min.js'), `${name} layout still loads Alpine auto-start CDN scripts`);
}
assert(fbr.includes('x-data="tnSafeFbrPosHeader('), 'FBR header does not use its safe factory boundary');
assert(fbr.includes("typeof window.fbrPosHeader === 'function'"), 'FBR safe factory does not prefer the full component');

// Execute the degradation boundary rather than only checking its source.
const safeFactoryScript = fbr.match(/<script>\s*(\/\/ Header controls must remain usable[\s\S]*?)<\/script>/)?.[1];
assert(safeFactoryScript, 'FBR safe factory script was not found');
const toggles = [];
const safeWindow = {};
const safeDocument = {
  documentElement: {
    classList: { toggle(name, enabled) { toggles.push([name, enabled]); } },
    style: {},
  },
};
vm.runInNewContext(safeFactoryScript, { window: safeWindow, document: safeDocument }, {
  filename: 'fbr-safe-header.blade.php',
});
const safe = safeWindow.tnSafeFbrPosHeader('emerald', false);
assert(safe.currentTheme === 'emerald' && safe.darkMode === false, 'safe FBR factory lost initial state');
safe.init();
safe.profileOpen = !safe.profileOpen;
safe.mobileMenuOpen = !safe.mobileMenuOpen;
safe.sidebarOpen = !safe.sidebarOpen;
safe.themeOpen = !safe.themeOpen;
safe.openLocal();
safe.openFailed();
safe.toggleDarkMode();
assert(safe.profileOpen && safe.mobileMenuOpen && safe.sidebarOpen && safe.themeOpen, 'safe FBR header controls did not toggle');
assert(safe.localOpen && safe.failedOpen, 'safe FBR local/failed actions did not open');
assert(safe.darkMode && safeDocument.documentElement.style.colorScheme === 'dark', 'safe FBR dark mode did not apply');
assert(toggles.length === 1 && toggles[0][0] === 'dark' && toggles[0][1] === true, 'safe FBR dark class was not toggled');
const full = { full: true };
safeWindow.fbrPosHeader = () => full;
assert(safeWindow.tnSafeFbrPosHeader('blue', false) === full, 'safe FBR boundary did not prefer full factory');

console.log('ALPINE BOOT OK: Vite-before-DCL, Vite-during-import, fallback-only, late-Vite, and missing-FBR-factory boundaries passed.');