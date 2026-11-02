'use strict';

// PC-local, exact queue names only. No inference from misleading driver names.
function normalizePaperProfiles(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return {};
  return Object.fromEntries(Object.entries(value).filter(([name, width]) =>
    name.length > 0 && name.length <= 512 && (width === 58 || width === 80)));
}

function measuredPageSize(width, heightPx) {
  if (![58, 80].includes(width) || !Number.isFinite(heightPx) || heightPx <= 0) {
    throw new Error('Receipt size could not be measured; no print was sent');
  }
  // Round upward and leave 3mm below the footer, never truncate long orders.
  const heightMm = Math.max(20, Math.ceil(heightPx * 25.4 / 96) + 3);
  if (heightMm > 2000) throw new Error('Receipt exceeds 2000mm; use Windows paper setting');
  return { width: width * 1000, height: heightMm * 1000 };
}

// Serialized into an isolated, sandboxed receipt window (no Node privileges).
async function measureReceipt(width) {
  const style = document.createElement('style');
  style.textContent = `@media print {
    html { width:${width}mm!important; height:auto!important; min-height:0!important; }
    body { width:${width === 58 ? 48 : 72}mm!important; max-width:${width === 58 ? 48 : 72}mm!important;
      box-sizing:border-box!important; height:auto!important; min-height:0!important; }
  }`;
  document.head.appendChild(style);
  await Promise.race([
    Promise.all([document.fonts.ready, ...Array.from(document.images, img => img.decode().catch(() => {}))]),
    new Promise(resolve => setTimeout(resolve, 8000)),
  ]);
  await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
  const body = document.body;
  if (!body || !body.innerText.trim()) throw new Error('Receipt has no visible text');
  const bounds = body.getBoundingClientRect();
  const paperPx = width * 96 / 25.4;
  let bottom = bounds.bottom;
  let left = bounds.left;
  let right = Math.max(bounds.right, bounds.left + body.scrollWidth);
  for (const el of body.querySelectorAll('*')) {
    if (!el.getClientRects().length) continue;
    const rect = el.getBoundingClientRect();
    if (rect.width && rect.height) {
      bottom = Math.max(bottom, rect.bottom);
      left = Math.min(left, rect.left);
      right = Math.max(right, rect.right);
    }
  }
  if (left < -1 || right > paperPx + 1) {
    throw new Error('Receipt is wider than this roll; check receipt width and left margin before printing');
  }
  // body.scrollHeight catches overflow, including floating/positioned content;
  // never use documentElement.scrollHeight (at least the 800px window height).
  return Math.max(bottom, bounds.top + body.scrollHeight);
}

async function prepareThermalPaper(contents, width) {
  if (![58, 80].includes(width)) throw new Error('Unsupported thermal paper width');
  // Only the dedicated print window is attached; never a user's POS tab.
  contents.debugger.attach('1.3');
  await contents.debugger.sendCommand('Emulation.setEmulatedMedia', { media: 'print' });
  await contents.debugger.sendCommand('Emulation.setDeviceMetricsOverride', {
    width: Math.ceil(width * 96 / 25.4), height: 1, deviceScaleFactor: 1, mobile: false,
  });
  const height = await contents.executeJavaScript(`(${measureReceipt.toString()})(${width})`);
  const pageSize = measuredPageSize(width, height);
  // Explicit CSS and Electron dimensions must agree. Ignore invalid "80mm auto"
  // and saved multi-metre driver form lengths in this opt-in mode only.
  await contents.insertCSS(`@page { size:${width}mm ${pageSize.height / 1000}mm!important; margin:0!important; }`);
  return pageSize;
}

module.exports = { normalizePaperProfiles, measuredPageSize, prepareThermalPaper };
