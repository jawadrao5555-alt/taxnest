'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { nextHandoff, shouldStopHandoff } = require('../src/update-retry');

test('a failed install can restart once, then requires manual installation', () => {
  const first = nextHandoff(null, '1.13.14');
  assert.equal(shouldStopHandoff(first, '1.13.14'), false);
  const second = nextHandoff(first, '1.13.14');
  assert.equal(shouldStopHandoff(second, '1.13.14'), true);
  assert.deepEqual(nextHandoff(second, '1.13.15'), { target: '1.13.15', count: 1 });
});
