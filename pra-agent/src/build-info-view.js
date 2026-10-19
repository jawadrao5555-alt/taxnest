(function (root, factory) {
  const display = factory();
  if (typeof module === 'object' && module.exports) module.exports = display;
  else display.mount(root.document, root.agentAPI);
})(globalThis, function () {
  function render(document, info) {
    const version = document.getElementById('agentVersion');
    const build = document.getElementById('buildVer');
    version.textContent = info?.version ? 'v' + info.version : 'Unavailable';
    const date = info?.builtAt ? new Date(info.builtAt) : null;
    if (date && Number.isFinite(date.getTime())) {
      build.textContent = date.toLocaleString(undefined, { year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit', timeZoneName: 'short' });
      build.dateTime = date.toISOString();
      build.title = 'Build time (UTC): ' + date.toISOString();
    } else {
      build.textContent = 'Not available';
      build.removeAttribute('datetime');
      build.removeAttribute('title');
    }
  }
  async function mount(document, bridge) {
    try { render(document, await bridge.getVersion()); }
    catch { render(document, null); }
  }
  return { render, mount };
});
