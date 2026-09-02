'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const {
  groupJobsByPrinter,
  groupJobsIntoOrderSequences,
  printerLaneKey,
  processPrinterLanes,
  processPrintSchedule,
  processPrintWaves,
} = require('../src/printer-queue');

test('groups different printers into parallel lanes and preserves lane order', () => {
  const jobs = [
    { id: 3129, target_printer: 'p1' },
    { id: 3130, target_printer: '92' },
    { id: 3131, target_printer: 'p1' },
    { id: 3132, target_printer: '92' },
  ];

  assert.deepEqual(
    groupJobsByPrinter(jobs).map((lane) => lane.map((job) => job.id)),
    [[3129, 3131], [3130, 3132]]
  );
});

test('blank printer names share the default ordered lane', () => {
  assert.equal(printerLaneKey(null), '__default__');
  assert.equal(printerLaneKey('  '), '__default__');
  assert.deepEqual(
    groupJobsByPrinter([
      { id: 1, target_printer: null },
      { id: 2, target_printer: '' },
    ]).map((lane) => lane.map((job) => job.id)),
    [[1, 2]]
  );
});

test('empty or invalid job collections produce no lanes', () => {
  assert.deepEqual(groupJobsByPrinter(), []);
  assert.deepEqual(groupJobsByPrinter(null), []);
});

test('processes different printers concurrently but keeps each printer ordered', async () => {
  const jobs = [
    { id: 1, target_printer: 'p1' },
    { id: 2, target_printer: 'p1' },
    { id: 3, target_printer: '92' },
    { id: 4, target_printer: '92' },
  ];
  const events = [];
  let releaseFirst;
  const firstBlocked = new Promise((resolve) => { releaseFirst = resolve; });

  const running = processPrinterLanes(jobs, async (job) => {
    events.push(`start:${job.id}`);
    if (job.id === 1) await firstBlocked;
    events.push(`end:${job.id}`);
  });

  await new Promise((resolve) => setImmediate(resolve));
  assert.ok(events.includes('start:1'));
  assert.ok(events.includes('start:3'));
  assert.ok(events.includes('end:3'));
  assert.ok(events.includes('start:4'));
  assert.ok(!events.includes('start:2'));

  releaseFirst();
  await running;
  assert.ok(events.indexOf('end:1') < events.indexOf('start:2'));
  assert.ok(events.indexOf('end:3') < events.indexOf('start:4'));
});

test('requires an explicit job processor', async () => {
  await assert.rejects(() => processPrinterLanes([], null), /processJob must be a function/);
});

test('scheduled copies run together but the next KOT wave waits for every prior copy', async () => {
  const jobs = [
    { id: 10, type: 'kot', restaurant_order_id: 77, target_printer: 'p1', created_at: '2026-09-02 20:49:08' },
    { id: 11, type: 'kot', restaurant_order_id: 77, target_printer: '92', created_at: '2026-09-02 20:49:08' },
    { id: 12, type: 'kot_void', restaurant_order_id: 77, target_printer: 'p1', created_at: '2026-09-02 20:49:09' },
    { id: 13, type: 'kot_void', restaurant_order_id: 77, target_printer: '92', created_at: '2026-09-02 20:49:09' },
    { id: 14, type: 'kot', restaurant_order_id: 88, target_printer: 'p4', created_at: '2026-09-02 20:49:08' },
  ];
  const events = [];
  const releases = new Map();
  const gates = new Map([10, 11, 14].map((id) => [
    id,
    new Promise((resolve) => releases.set(id, resolve)),
  ]));

  const running = processPrintSchedule(jobs, async (job) => {
    events.push(`start:${job.id}`);
    if (gates.has(job.id)) await gates.get(job.id);
    events.push(`end:${job.id}`);
  });

  await new Promise((resolve) => setImmediate(resolve));
  assert.ok(events.includes('start:10'), 'kitchen copy should start');
  assert.ok(events.includes('start:11'), 'counter copy should start in parallel');
  assert.ok(events.includes('start:14'), 'another order should remain independent');
  assert.ok(!events.includes('start:12'), 'next wave must wait for all prior copies');
  assert.ok(!events.includes('start:13'), 'next wave must wait for all prior copies');

  releases.get(10)();
  await new Promise((resolve) => setImmediate(resolve));
  assert.ok(!events.includes('start:12'), 'one unfinished copy still holds the next wave');

  releases.get(11)();
  await new Promise((resolve) => setImmediate(resolve));
  assert.ok(events.includes('start:12'));
  assert.ok(events.includes('start:13'));

  releases.get(14)();
  await running;
});

test('builds ordered waves per restaurant order', () => {
  const sequences = groupJobsIntoOrderSequences([
    { id: 1, type: 'kot', restaurant_order_id: 5, created_at: 'a' },
    { id: 2, type: 'kot', restaurant_order_id: 5, created_at: 'a' },
    { id: 3, type: 'kot', restaurant_order_id: 5, created_at: 'b' },
    { id: 4, type: 'bill', restaurant_order_id: 5, created_at: 'b' },
  ]);
  assert.deepEqual(
    sequences.map((waves) => waves.map((wave) => wave.map((job) => job.id))),
    [[[1, 2], [3]], [[4]]]
  );
});

test('keeps distinct same-second delta snapshots in separate waves', () => {
  const sequences = groupJobsIntoOrderSequences([
    {
      id: 1, type: 'kot', restaurant_order_id: 5, created_at: 'same',
      printed_item_ids: '[10,11]', target_printer: 'p1',
    },
    {
      id: 2, type: 'kot', restaurant_order_id: 5, created_at: 'same',
      printed_item_ids: [11, 10], target_printer: '92',
    },
    {
      id: 3, type: 'kot', restaurant_order_id: 5, created_at: 'same',
      printed_item_ids: [12], target_printer: 'p1',
    },
  ]);
  assert.deepEqual(
    sequences.map((waves) => waves.map((wave) => wave.map((job) => job.id))),
    [[[1, 2], [3]]]
  );
});

test('wave scheduler requires an explicit job processor', async () => {
  await assert.rejects(() => processPrintWaves([], null), /processJob must be a function/);
});

test('schedule preserves claimed order before content work starts on one printer', async () => {
  const jobs = [
    { id: 1, type: 'kot', restaurant_order_id: 10, target_printer: 'p1', created_at: 'a' },
    { id: 2, type: 'bill', restaurant_order_id: null, target_printer: 'p1', created_at: 'a' },
    { id: 3, type: 'kot', restaurant_order_id: 20, target_printer: '92', created_at: 'a' },
  ];
  const events = [];
  let releaseFirst;
  const firstGate = new Promise((resolve) => { releaseFirst = resolve; });

  const running = processPrintSchedule(jobs, async (job) => {
    events.push(`start:${job.id}`);
    if (job.id === 1) await firstGate;
    events.push(`end:${job.id}`);
  });

  await new Promise((resolve) => setImmediate(resolve));
  assert.ok(events.includes('start:1'));
  assert.ok(events.includes('start:3'), 'different printer should overlap');
  assert.ok(!events.includes('start:2'), 'same-printer content work must keep claim order');

  releaseFirst();
  await running;
  assert.ok(events.indexOf('end:1') < events.indexOf('start:2'));
});

test('combined scheduler requires an explicit job processor', async () => {
  await assert.rejects(() => processPrintSchedule([], null), /processJob must be a function/);
});