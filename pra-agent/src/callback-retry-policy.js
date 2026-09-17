'use strict';

const MAX_CALLBACK_BACKOFF_MS = 6 * 60 * 60 * 1000;

// A callback that reports a fiscal result is evidence, not disposable cache.
// Retain it until the server acknowledges it; cap only the retry cadence so a
// malformed/poisoned response cannot create a request storm.
function nextCallbackRetryAt(attempts, now = Date.now()) {
  const safeAttempts = Math.max(1, Math.min(30, Number(attempts) || 1));
  const delay = Math.min(MAX_CALLBACK_BACKOFF_MS, 30000 * (2 ** Math.min(10, safeAttempts - 1)));
  return new Date(now + delay).toISOString();
}

function canRetryCallback(item, now = Date.now()) {
  if (!item || !item.next_callback_retry_at) return true;
  const at = Date.parse(item.next_callback_retry_at);
  return Number.isNaN(at) || at <= now;
}

async function replayCallbacks(queue, { canContinue, post, log = () => {}, now = Date.now }) {
  const remaining = [];
  for (let i = 0; i < queue.length; i += 1) {
    const item = queue[i];
    if (!canContinue()) {
      remaining.push(...queue.slice(i));
      break;
    }
    if (!canRetryCallback(item, now())) {
      remaining.push(item);
      continue;
    }
    try {
      // This lane delivers a completed fiscal callback only. It never invokes
      // print execution or invoice submission, so retained/replayed evidence
      // cannot repeat a physical print.
      await post(item);
      log(`✅ Replayed callback for txn ${item.transaction_id}`);
    } catch (e) {
      const attempts = (item._attempts || 0) + 1;
      const errorCode = e && e.response && e.response.status
        ? `http_${e.response.status}` : (e && e.code ? String(e.code).slice(0, 40) : 'callback_delivery_failed');
      remaining.push({
        ...item,
        _attempts: attempts,
        callback_state: 'retrying',
        callback_error_code: errorCode,
        next_callback_retry_at: nextCallbackRetryAt(attempts, now()),
      });
      log(`⚠️ Callback for txn ${item.transaction_id} retained (attempt ${attempts}); retry scheduled`);
    }
  }
  return remaining;
}

module.exports = { canRetryCallback, nextCallbackRetryAt, replayCallbacks };