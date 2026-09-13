'use strict';

// A WebSocket is an acceleration signal, not proof that every wake reached this
// device. Keep a one-second HTTP recovery sweep so a half-open socket or a
// missed broadcast cannot delay a kitchen ticket. Realtime remains the fast
// path; polling is the fail-safe and never creates concurrent claims.
const REALTIME_RECOVERY_MS = 1000;

function nextPollDelay(suggestedDelay, realtimeHealthy, wakePending) {
  let delay = suggestedDelay;
  if (typeof delay !== 'number' || !Number.isFinite(delay) || delay < 0) delay = 1500;
  if (wakePending) return 0;
  if (realtimeHealthy) return Math.max(delay, REALTIME_RECOVERY_MS);
  return delay;
}

module.exports = {
  REALTIME_RECOVERY_MS,
  nextPollDelay,
};