'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const { canRetryCallback, nextCallbackRetryAt, replayCallbacks } = require('../src/callback-retry-policy');

test('callback evidence is retained with a bounded retry cadence', () => {
  const now = Date.parse('2026-09-16T12:00:00.000Z');
  assert.equal(nextCallbackRetryAt(1, now), '2026-09-16T12:00:30.000Z');
  assert.equal(nextCallbackRetryAt(99, now), '2026-09-16T18:00:00.000Z');
  assert.equal(canRetryCallback({ next_callback_retry_at: '2026-09-16T12:00:29.999Z' }, now), false);
  assert.equal(canRetryCallback({ next_callback_retry_at: '2026-09-16T12:00:00.000Z' }, now), true);
});

test('actual callback replay retains completed evidence and never invokes print execution', async () => {
  const completed = {
    transaction_id: 42, success: true, pra_invoice_number: 'PRA-42',
    response: { code: '00' }, _attempts: 50,
  };
  const calls = [];
  const remaining = await replayCallbacks([completed], {
    canContinue: () => true,
    post: async (item) => {
      calls.push(item);
      const error = new Error('offline');
      error.code = 'ECONNRESET';
      throw error;
    },
    now: () => Date.parse('2026-09-16T12:00:00.000Z'),
  });

  assert.equal(calls.length, 1);
  assert.equal(remaining.length, 1, 'a 51st failed callback is retained, not dropped');
  assert.equal(remaining[0].pra_invoice_number, 'PRA-42');
  assert.equal(remaining[0].callback_state, 'retrying');
  assert.equal(remaining[0].callback_error_code, 'ECONNRESET');
  assert.equal(remaining[0].next_callback_retry_at, '2026-09-16T18:00:00.000Z');
  assert.equal(Object.hasOwn(remaining[0], 'print'), false);
});