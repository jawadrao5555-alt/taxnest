'use strict';

// Heartbeat identity is authenticated by the server. A configured company ID
// alone cannot tell the renderer which regulator or submission mode is active.
function imsMode(status) {
  if (!status || !status.running || !status.connected || !status.serverInfo) return null;
  const company = status.serverInfo;
  if (company.product_type === 'fbrpos') {
    return company.fbr_connection_mode === 'fiscal_device' ? 'fbr' : null;
  }
  if (company.product_type === 'pos') {
    return company.pra_connection_mode === 'fiscal_device' ? 'pra' : null;
  }
  return null; // unknown / legacy responses fail closed for this optional UI
}

module.exports = { imsMode };
