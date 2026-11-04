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
    // Preparation is independent of dispatch permission. Owners must be able
    // to find/install IMS before enabling the alongside reporting profile.
    if (company.fbr_alongside_pra === true) return 'both';
    return company.pra_connection_mode === 'fiscal_device' ? 'both' : 'fbr';
  }
  return null; // unknown / legacy responses fail closed for this optional UI
}

if (typeof module === 'object' && module.exports) module.exports = { imsMode };
else window.TaxNestImsVisibility = Object.freeze({ imsMode });
