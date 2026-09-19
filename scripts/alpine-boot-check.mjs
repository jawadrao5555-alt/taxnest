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

let dcl;
const events = [];
const document = {
  readyState: 'loading',
  addEventListener(name, handler) {
    if (name === 'DOMContentLoaded') dcl = handler;
  },
};
const window = {
  dispatchEvent(event) { events.push(event); },
};
const context = {
  window,
  document,
  CustomEvent: class CustomEvent {
    constructor(type, init = {}) { this.type = type; this.detail = init.detail; }
  },
  setTimeout,
  clearTimeout,
  console,
};
vm.createContext(context);
vm.runInContext(script, context, { filename: 'alpine-runtime-loader.blade.php' });

assert(typeof window.__tnStartAlpine === 'function', 'coordinator was not installed');
assert(typeof dcl === 'function', 'fallback was not deferred until DOMContentLoaded');

const runtime = {
  starts: 0,
  plugins: 0,
  plugin() { this.plugins += 1; },
  start() { this.starts += 1; },
};
const plugin = () => {};
window.__tnStartAlpine(runtime, [plugin], 'vite');
window.__tnStartAlpine(runtime, [plugin], 'late-vite');

assert(runtime.starts === 1, 'Alpine started more than once');
assert(runtime.plugins === 1, 'plugins were registered more than once');
assert(window.__tnAlpineBoot.status === 'started', 'boot did not reach started state');
assert(window.__tnAlpineBoot.starts === 1, 'boot start counter is not exactly one');
assert(window.__tnAlpineBoot.source === 'vite', 'late caller replaced authoritative source');
assert(window.__alpineStarted === true, 'legacy completion flag was not set');
assert(events.filter(event => event.type === 'tn:alpine-started').length === 1, 'start event fired more than once');

assert(app.includes("startAlpine(Alpine, [collapse], 'vite')"), 'Vite entry does not use coordinator');
assert(!app.includes('if (!window.__alpineStarted)'), 'Vite entry still uses the racy legacy guard');
for (const [name, layout] of [['PRA', pra], ['FBR', fbr]]) {
  assert(layout.includes("@include('partials.alpine-runtime-loader')"), `${name} layout does not use shared runtime`);
  assert(!layout.includes('__alpineFallbackLoading'), `${name} layout still contains legacy fallback state`);
  assert(!layout.includes('/dist/cdn.min.js'), `${name} layout still loads Alpine auto-start CDN scripts`);
}
assert(fbr.includes('x-data="tnSafeFbrPosHeader('), 'FBR header does not use its safe factory boundary');
assert(fbr.includes("typeof window.fbrPosHeader === 'function'"), 'FBR safe factory does not prefer the full component');

console.log('ALPINE BOOT OK: one shared coordinator, one start, one plugin registration, deferred non-auto-start fallback.');