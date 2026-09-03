'use strict';

// A response can only affect the exact agent run/configuration that issued it.
// Kept pure so delayed-response switch/stop safety is testable without Electron.
function isCurrentHeartbeatRequest(requestGen, requestConfigRef, state) {
    return !!state && state.runGen === requestGen && state.currentConfig === requestConfigRef && !!state.running;
}

module.exports = { isCurrentHeartbeatRequest };