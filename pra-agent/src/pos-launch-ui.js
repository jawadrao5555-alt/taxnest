(function (root) {
  'use strict';
  function bindPosLaunch(button, browserButton, status, api) {
    let busy = false;
    async function run(action, message) {
      if (busy) return;
      busy = true;button.disabled = true;browserButton.disabled = true;
      status.textContent = 'POS khol rahe hain…';
      try {
        const result = await action();
        if (!result || result.ok !== true) throw new Error('launch refused');
        status.textContent = message;
      } catch (_) {
        status.textContent = 'POS nahi khul saki. Dobara koshish karein, ya Browser mein POS kholein. Agent ki settings nahi badli gain.';
      } finally { busy = false;button.disabled = false;browserButton.disabled = false; }
    }
    button.addEventListener('click', () => run(() => api.openPosWindow(), 'POS window kholne ki darkhwast bhej di gayi. Window nazar na aaye to Browser mein POS kholein.'));
    browserButton.addEventListener('click', () => run(() => api.openPosInBrowser(), 'Browser mein POS kholne ki darkhwast bhej di gayi.'));
  }
  if (typeof module === 'object' && module.exports) module.exports = { bindPosLaunch };
  else root.TnPosLaunch = { bindPosLaunch };
})(typeof window !== 'undefined' ? window : globalThis);
