'use strict';
const { companyPairing } = require('./company-pairing');

// Same production coordinator, with explicit storage/lifecycle ports so disposable
// native-window acceptance never starts an Agent or touches a user's settings.
function createCompanyConfigurator({ getConfig, saveConfig, showMessageBox, onConfigured }) {
  let companyPairingBusy = false;
  return async function autoConfigureAgent(ses, origin, panel = 'pos', isCurrent = () => true) {
  if (companyPairingBusy) return false;
  companyPairingBusy = true;
  try {
    const before = getConfig() || {};
    const paired = await companyPairing({ session: ses, origin, panel,
      existing: before, isCurrent,
      confirm: async ({ companyId, companyName, switching }) => {
        const result = await showMessageBox({ type: 'question',
          title: 'Connect TaxNest Agent', buttons: ['Cancel', 'Connect'], defaultId: 0, cancelId: 0,
          message: 'Connect this Agent to ' + companyName + '?',
          detail: 'Company ID: ' + companyId + (switching
            ? '\nThis replaces the company currently connected on this PC.'
            : '\nConfirm this is the company you want to connect. No company credentials are included in the installer.'),
        });
        return result.response === 1;
      },
    });
    if (!paired.ok) return false;
    if (!paired.config) return true; // unchanged or explicitly cancelled
    if (!isCurrent()) return false;
    const current = getConfig() || {};
    if (['companyId', 'apiKey', 'serverUrl'].some(key => current[key] !== before[key])) return false;
    const nextConfig = { ...current, companyId: paired.config.companyId,
      apiKey: paired.config.apiKey, serverUrl: paired.config.serverUrl };
    saveConfig(nextConfig);
    onConfigured(nextConfig);
    return true;
  } catch (_) {
    // Never log a fetch exception that could contain request headers or nonce.
    console.log('[company-pairing] Connection was not completed; saved configuration retained.');
    return false;
  } finally { companyPairingBusy = false; }
  };
}
module.exports = { createCompanyConfigurator };
