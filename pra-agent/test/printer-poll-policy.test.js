'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const {
  REALTIME_RECOVERY_MS,
  nextPollDelay,
} = require('../src/printer-poll-policy');

test('healthy realtime keeps a bounded HTTP recovery sweep for missed wakes', () => {
  assert.equal(REALTIME_RECOVERY_MS, 5000);
  assert.equal(nextPollDelay(1500, true, false), 5000);
  assert.equal(nextPollDelay(0, true, false), 5000);
});

test('a realtime wake triggers an immediate poll without waiting for recovery', () => {
  assert.equal(nextPollDelay(1500, true, true), 0);
  assert.equal(nextPollDelay(3000, false, true), 0);
});

test('disconnected polling preserves server suggestions and never busy-loops on invalid input', () => {
  assert.equal(nextPollDelay(0, false, false), 0);
  assert.equal(nextPollDelay(1500, false, false), 1500);
  assert.equal(nextPollDelay(Number.NaN, false, false), 1500);
  assert.equal(nextPollDelay(-1, true, false), 5000);
});