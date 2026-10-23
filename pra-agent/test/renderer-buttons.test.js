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
