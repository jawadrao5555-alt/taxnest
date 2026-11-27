(function (root) {
  'use strict';
  function bindPosLaunch(button, browserButton, status, api, secondButton) {
    const launchButtons = [button, browserButton, secondButton].filter(Boolean);
    let busy = false;
    async function run(action, message) {
      if (busy) return;
      busy = true;
      for (const control of launchButtons) control.disabled = true;
      status.textContent = 'POS khol rahe hain…';
      try {
        const result = await action();
        if (!result || result.ok !== true) throw new Error('launch refused');
        status.textContent = message;
      } catch (_) {
        status.textContent = 'POS nahi khul saki. Dobara koshish karein, ya Browser mein POS kholein. Agent ki settings nahi badli gain.';
      } finally {
        busy = false;
        for (const control of launchButtons) control.disabled = false;
      }
    }
    button.addEventListener('click', () => run(() => api.openPosWindow(1), 'POS window kholne ki darkhwast bhej di gayi. Window nazar na aaye to Browser mein POS kholein.'));
    secondButton?.addEventListener('click', () => run(() => api.openPosWindow(2), 'POS 2 kholne ki darkhwast bhej di gayi.'));
    browserButton.addEventListener('click', () => run(() => api.openPosInBrowser(), 'Browser mein POS kholne ki darkhwast bhej di gayi.'));
  }
  if (typeof module === 'object' && module.exports) module.exports = { bindPosLaunch };
  else root.TnPosLaunch = { bindPosLaunch };
})(typeof window !== 'undefined' ? window : globalThis);
