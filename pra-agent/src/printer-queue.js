'use strict';

function printerLaneKey(deviceName) {
  const name = String(deviceName || '').trim();
  return name || '__default__';
}

/**
 * Preserve server order within one Windows printer queue while separating
 * independent printers into lanes that the Agent can process concurrently.
 */
function groupJobsByPrinter(jobs) {
  const lanes = new Map();
  for (const job of Array.isArray(jobs) ? jobs : []) {
    const key = printerLaneKey(job && job.target_printer);
    if (!lanes.has(key)) lanes.set(key, []);
    lanes.get(key).push(job);
  }
  return Array.from(lanes.values());
}

async function processPrinterLanes(jobs, processJob) {
  if (typeof processJob !== 'function') {
    throw new TypeError('processJob must be a function');
  }
  const lanes = groupJobsByPrinter(jobs);
  await Promise.all(lanes.map(async (laneJobs) => {
    for (const job of laneJobs) await processJob(job);
  }));
}

function isOrderKot(job) {
  return !!(
    job
    && job.restaurant_order_id
    && ['kot', 'kot_void', 'fbr_kot'].includes(String(job.type || ''))
  );
}

function printedItemsKey(value) {
  let items = value;
  if (typeof items === 'string') {
    try { items = JSON.parse(items); } catch (e) { return items; }
  }
  if (!Array.isArray(items)) return '';
  return JSON.stringify(items.map((id) => String(id)).sort());
}

function printWaveKey(job) {
  if (!isOrderKot(job)) return `job:${job && job.id}`;
  // Jobs created by one enqueue action share order + type + created_at.
  // Kitchen/station/counter copies intentionally ignore printer/render_query:
  // they are one logical KOT wave and may render/print in parallel. A later
  // delta or void action becomes the next wave and waits for result stamping.
  return [
    String(job.restaurant_order_id),
    String(job.type || ''),
    String(job.created_at || ''),
    printedItemsKey(job.printed_item_ids),
  ].join('|');
}

function groupJobsIntoOrderSequences(jobs) {
  const sequences = new Map();
  let independent = 0;

  for (const job of Array.isArray(jobs) ? jobs : []) {
    const sequenceKey = isOrderKot(job)
      ? `order:${job.restaurant_order_id}`
      : `independent:${independent++}`;
    if (!sequences.has(sequenceKey)) {
      sequences.set(sequenceKey, { waves: [], byKey: new Map() });
    }
    const sequence = sequences.get(sequenceKey);
    const waveKey = printWaveKey(job);
    if (!sequence.byKey.has(waveKey)) {
      const wave = [];
      sequence.byKey.set(waveKey, wave);
      sequence.waves.push(wave);
    }
    sequence.byKey.get(waveKey).push(job);
  }

  return Array.from(sequences.values(), (sequence) => sequence.waves);
}

async function processPrintWaves(jobs, processJob) {
  if (typeof processJob !== 'function') {
    throw new TypeError('processJob must be a function');
  }
  const sequences = groupJobsIntoOrderSequences(jobs);
  await Promise.all(sequences.map(async (waves) => {
    for (const wave of waves) {
      await Promise.all(wave.map((job) => processJob(job)));
    }
  }));
}

/**
 * Compose both ordering constraints before processJob starts (and therefore
 * before printable HTML is fetched):
 *  - claimed order is preserved for every physical printer queue;
 *  - a later KOT wave for one restaurant order waits for every copy in the
 *    previous wave to report its result.
 *
 * Jobs with neither dependency, such as unrelated orders on different
 * printers, start concurrently.
 */
async function processPrintSchedule(jobs, processJob) {
  if (typeof processJob !== 'function') {
    throw new TypeError('processJob must be a function');
  }
  const orderedJobs = Array.isArray(jobs) ? jobs : [];
  const priorWaveJobs = new Map();
  const orderStates = new Map();

  // First pass: record the complete prior-wave barrier for every order job.
  for (const job of orderedJobs) {
    if (!isOrderKot(job)) continue;
    const orderKey = String(job.restaurant_order_id);
    const waveKey = printWaveKey(job);
    let state = orderStates.get(orderKey);
    if (!state) {
      state = { waveKey, currentJobs: [], priorJobs: [] };
      orderStates.set(orderKey, state);
    } else if (state.waveKey !== waveKey) {
      state.priorJobs = state.currentJobs.slice();
      state.currentJobs = [];
      state.waveKey = waveKey;
    }
    priorWaveJobs.set(job, state.priorJobs.slice());
    state.currentJobs.push(job);
  }

  // Second pass: build an acyclic promise graph in server-claimed order.
  const printerTails = new Map();
  const jobPromises = new Map();
  const all = [];
  for (const job of orderedJobs) {
    const dependencies = [];
    const printerKey = printerLaneKey(job && job.target_printer);
    const printerTail = printerTails.get(printerKey);
    if (printerTail) dependencies.push(printerTail);
    for (const priorJob of priorWaveJobs.get(job) || []) {
      const priorPromise = jobPromises.get(priorJob);
      if (priorPromise) dependencies.push(priorPromise);
    }

    const promise = Promise.all(dependencies).then(() => processJob(job));
    printerTails.set(printerKey, promise);
    jobPromises.set(job, promise);
    all.push(promise);
  }
  await Promise.all(all);
}

module.exports = {
  groupJobsByPrinter,
  groupJobsIntoOrderSequences,
  printerLaneKey,
  printWaveKey,
  processPrinterLanes,
  processPrintSchedule,
  processPrintWaves,
};