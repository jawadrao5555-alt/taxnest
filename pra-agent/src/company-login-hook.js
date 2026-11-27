'use strict';

// One confirmation per successful login. A logout/navigation race invalidates
// the in-flight operation before it can write another company's local config.
function wireCompanyLogin(win, origin, panel, onLoggedIn) {
  if (typeof onLoggedIn !== 'function') return;
  const prefix = panel === 'fbrpos' ? '/fbr-pos/' : '/pos/';
  let done = false, inFlight = false, generation = 0;
  const current = () => !win.isDestroyed() && win.webContents.getURL().startsWith(origin + prefix) &&
    !new RegExp('^' + prefix + '(login|logout)(?:[/?#]|$)').test(new URL(win.webContents.getURL()).pathname);
  win.webContents.on('did-navigate', (_event, target) => {
    try {
      const u = new URL(target);
      if (u.origin !== origin || !u.pathname.startsWith(prefix) ||
          new RegExp('^' + prefix + '(login|logout)(?:[/?#]|$)').test(u.pathname)) {
        generation++; done = false;
      }
    } catch (_) { generation++; done = false; }
  });
  function confirmCurrentLogin() {
    if (done || inFlight || !current()) return;
    const gen = generation;
    inFlight = true;
    Promise.resolve(onLoggedIn(win.webContents.session, origin, () => gen === generation && current()))
      .then(ok => { if (gen === generation) done = !!ok; })
      .catch(() => {})
      .finally(() => {
        inFlight = false;
        // A new login can finish loading while the old confirmation is open.
        if (gen !== generation) confirmCurrentLogin();
      });
  }
  win.webContents.on('did-finish-load', confirmCurrentLogin);
}
module.exports = { wireCompanyLogin };
