'use strict';

// POS 2 uses its own cookie partition. Only the paired device journal and its
// sync worker are shared. The renderer never receives the server's lease.
function secondarySaleSession(win, origin, getRuntime) {
  const contents = win.webContents;
  let generation = 0;
  let grant = null;
  function clear() { generation++; grant = null; }
  function inPos(url) {
    try {
      const page = new URL(url);
      return page.origin === origin && page.pathname.startsWith('/pos/') &&
        !/^\/pos\/(login|logout)(?:\/|$)/.test(page.pathname);
    } catch (_) { return false; }
  }

  async function refresh() {
    const runtime = getRuntime();
    if (!runtime || win.isDestroyed() || !inPos(contents.getURL())) return;
    const request = ++generation;
    try {
      const response = await contents.session.fetch(origin + '/pos/desktop/local-core-scope', {
        headers: { Accept: 'application/json', 'X-NestPOS-Device-Uid': runtime.deviceUid },
        cache: 'no-store', redirect: 'error',
      });
      const data = response.ok ? await response.json() : null;
      if (request !== generation || runtime !== getRuntime() || win.isDestroyed()) return;
      grant = null;
      const lease = data?.lease;
      const scope = lease?.scope;
      const expires = Date.parse(lease?.expires_at);
      if (!inPos(contents.getURL()) || data?.success !== true || data.immediate_sale_allowed !== true ||
          !lease?.lease_id || !lease.token || !(expires > Date.now()) ||
          String(data.company_id) !== runtime.companyId || scope?.company_id !== runtime.companyId ||
          scope.device_id !== runtime.deviceUid || !/^[1-9]\d*$/.test(data.branch_id) ||
          !/^[1-9]\d*$/.test(data.user_id) || scope.branch_id !== data.branch_id ||
          scope.user_id !== data.user_id) return;
      grant = { runtime, expires, scope };
    } catch (_) { /* A disconnected cashier can use the existing unexpired grant. */ }
  }
  contents.on('did-start-navigation', (_event, url, _inPlace, isMainFrame) => {
    if (isMainFrame && !inPos(url)) clear();
  });
  contents.on('did-finish-load', refresh);
  win.on('closed', clear);
  return {
    refresh,
    scope() {
      return grant && grant.runtime === getRuntime() && grant.expires > Date.now() &&
        !win.isDestroyed() && inPos(contents.getURL()) ? grant.scope : null;
    },
  };
}
module.exports = { secondarySaleSession };
