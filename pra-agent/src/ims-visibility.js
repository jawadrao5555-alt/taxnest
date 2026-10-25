'use strict';

// Heartbeat identity is authenticated by the server. A configured company ID
// alone cannot tell the renderer which regulator or submission mode is active.
function imsMode(status) {
  if (!status || !status.running || !status.connected || !status.serverInfo) return null;
  const company = status.serverInfo;
  if (company.product_type === 'fbrpos') {
    // Discovery/install is explicit UI, not permission to change fiscal routing.
    // Every authenticated FBR company may need to prepare its local IMS.
    return 'fbr';
  }
  if (company.product_type === 'pos') {
    return company.pra_connection_mode === 'fiscal_device' ? 'pra' : null;
  }
  return null; // unknown / legacy responses fail closed for this optional UI
}

if (typeof module === 'object' && module.exports) module.exports = { imsMode };
else window.TaxNestImsVisibility = Object.freeze({ imsMode });
