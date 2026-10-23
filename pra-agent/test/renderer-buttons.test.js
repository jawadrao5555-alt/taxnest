'use strict';

// Guard against a renderer parse failure that leaves the entire settings UI
// visible but makes Start / Test Connection / Save & Connect inert.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');

test('settings renderer inline JavaScript parses without a syntax error', () => {
  const scripts = [...html.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g)];
  assert.ok(scripts.length, 'expected inline renderer script');
  for (const script of scripts) {
    if (script[1].trim()) assert.doesNotThrow(() => new vm.Script(script[1]));
  }
});

test('all three critical buttons and their click handlers are present', () => {
  for (const id of ['toggleBtn', 'testBtn', 'saveBtn']) {
    assert.match(html, new RegExp('id="' + id + '"'));
    assert.ok(html.includes("$('" + id + "').addEventListener('click'"), id + ' missing handler');
  }
});

test('renderer has visible bridge failure diagnostic', () => {
  assert.match(html, /typeof window\.agentAPI\.testConnection/);
  assert.match(html, /Agent interface failed to load/);
});

test('sandboxed production preload exposes operational buttons without loading CommonJS files', async () => {
  let bridge;
  const calls = [];
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', 'preload.js'), 'utf8'), {
    require(name) {
      if (name !== 'electron') throw Error('Sandbox cannot load ' + name);
      return {
        contextBridge: { exposeInMainWorld(name, api) { assert.equal(name, 'agentAPI'); bridge = api; } },
        ipcRenderer: { invoke: async (channel, ...args) => { calls.push({channel, args}); return {ok:true}; }, on() {} },
      };
    },
  });
  assert.ok(bridge);
  await bridge.testConnection({companyId:1}); await bridge.saveConfig({companyId:1});
  await bridge.toggleAgent(true); await bridge.openPosWindow();
  assert.deepEqual(calls.map(c => c.channel), ['test-connection','save-config','toggle-agent','open-pos-window']);
});
