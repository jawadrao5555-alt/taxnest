// NestPOS Desktop — Offline Mode (Beta, v1.5.0).
//
// Goal: billing must keep working with NO internet. The sale screen already
// has an in-page IndexedDB offline bill queue + auto-sync (server dedupes via
// offline_uuid). The only hard gap is COLD START: when the server is
// unreachable the page itself cannot load. This module fixes exactly that:
//
//   1. While ONLINE, every successful sale-screen load triggers a throttled
//      snapshot: we re-fetch the rendered page (with the persist:pos session
//      cookies, so it is the logged-in cashier's own screen with the full
//      product catalog embedded) plus its same-origin static assets, and save
//      everything to disk (userData/pos-offline-snapshot/).
//   2. An https protocol handler on the persist:pos partition passes EVERY
//      request straight through to the network (documented Electron
//      passthrough pattern). ONLY when the network fetch itself fails do we
//      serve the disk snapshot — same origin, so the page's IndexedDB queue
//      and sync engine keep working seamlessly.
//
// Safety rules (architect plan):
// - Passthrough-first: while online, behavior must be byte-identical.
// - Same-origin ONLY. Never file:// or a custom scheme — IndexedDB is
//   origin-scoped and a different origin would orphan the offline bill queue.
// - Snapshots are never captured from an offline-served page (the capture
//   fetch itself fails offline, so this is self-guarding).
// - POST/PUT etc. are never answered from the snapshot — a failed submit must
//   look like a network error so the page queues the bill (existing engine).
// - Everything is wrapped in try/catch: any failure here degrades to the old
//   behavior (offline.html), never breaks the agent or online POS.
const { app, net } = require('electron');
const path = require('path');
const fs = require('fs');
const fsp = fs.promises;
const crypto = require('crypto');

const SALE_PATH = '/pos/invoice/create';
const CAPTURE_THROTTLE_MS = 10 * 60 * 1000; // at most one snapshot per 10 min
const CAPTURE_DELAY_MS = 8000;              // let the page settle first
const MAX_ASSETS = 200;
const MAX_ASSET_BYTES = 5 * 1024 * 1024;    // skip any single asset > 5 MB
const MAX_TOTAL_BYTES = 60 * 1024 * 1024;   // stop capturing past 60 MB

const ASSET_EXT = new Set([
  '.css', '.js', '.mjs', '.png', '.jpg', '.jpeg', '.svg', '.webp', '.gif',
  '.ico', '.woff', '.woff2', '.ttf', '.otf', '.json',
]);

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.mjs': 'application/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.svg': 'image/svg+xml',
  '.webp': 'image/webp',
  '.gif': 'image/gif',
  '.ico': 'image/x-icon',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.ttf': 'font/ttf',
  '.otf': 'font/otf',
};

let manifestCache = null;   // { savedAt, pageUrl, assets: { [pathname]: file } }
let lastCaptureAt = 0;
let captureInFlight = false;
let captureTimer = null;
const registeredSessions = new WeakSet();

function log(msg) {
  try { console.log('[offline-snapshot] ' + msg); } catch (e) {}
}

function snapshotDir() {
  return path.join(app.getPath('userData'), 'pos-offline-snapshot');
}

function manifestPath() { return path.join(snapshotDir(), 'manifest.json'); }
function pagePath() { return path.join(snapshotDir(), 'page.html'); }
function assetsDir() { return path.join(snapshotDir(), 'assets'); }

function extOf(pathname) {
  try { return path.extname(new URL('https://x' + pathname, 'https://x').pathname).toLowerCase(); }
  catch (e) { return path.extname(pathname).toLowerCase(); }
}

function loadManifest() {
  if (manifestCache) return manifestCache;
  try {
    const raw = fs.readFileSync(manifestPath(), 'utf8');
    const m = JSON.parse(raw);
    if (m && m.assets && m.savedAt) manifestCache = m;
  } catch (e) { /* no snapshot yet */ }
  return manifestCache;
}

function hasSnapshot() {
  const m = loadManifest();
  return !!(m && fs.existsSync(pagePath()));
}

function snapshotInfo() {
  const m = loadManifest();
  return m ? { savedAt: m.savedAt, assetCount: Object.keys(m.assets || {}).length } : null;
}

// ─── Capture ─────────────────────────────────────────────────────────────────

function sesFetch(ses, input, init) {
  // session.fetch keeps the persist:pos cookies; net.fetch is the fallback.
  try {
    if (ses && typeof ses.fetch === 'function') return ses.fetch(input, init);
  } catch (e) {}
  return net.fetch(input, init);
}

function extractAssetPaths(html, origin) {
  const found = new Set();
  const re = /(?:href|src)\s*=\s*["']([^"']+)["']/gi;
  let m;
  while ((m = re.exec(html)) !== null) {
    let raw = m[1];
    if (!raw) continue;
    let pathname;
    try {
      if (raw.startsWith('//')) raw = 'https:' + raw;
      if (/^https?:/i.test(raw)) {
        const u = new URL(raw);
        if (u.origin !== origin) continue; // same-origin only
        pathname = u.pathname;
      } else if (raw.startsWith('/')) {
        pathname = new URL(origin + raw).pathname;
      } else {
        continue; // relative/data:/mailto: etc. — skip
      }
    } catch (e) { continue; }
    if (pathname === '/sw.js') continue; // never snapshot the service worker
    if (!ASSET_EXT.has(extOf(pathname))) continue;
    found.add(pathname);
  }
  return Array.from(found);
}

// Product images: the sale screen embeds the product list as JSON, so image
// URLs appear as escaped strings (https:\/\/...\/storage\/products\/x.png) —
// never in href/src attributes. Unescape a copy and scan for /storage/ image
// paths so offline mode shows product pictures too (caps still apply).
function extractEmbeddedImagePaths(html, origin) {
  const found = new Set();
  const un = String(html || '').replace(/\\\//g, '/');
  const re = /(?:https?:\/\/[^"'\s\\]+?)?\/storage\/[^"'\s?#\\]+?\.(?:png|jpe?g|webp|gif|svg)/gi;
  let m;
  while ((m = re.exec(un)) !== null) {
    const raw = m[0];
    try {
      const u = /^https?:/i.test(raw) ? new URL(raw) : new URL(origin + raw);
      if (u.origin !== origin) continue;
      if (!ASSET_EXT.has(extOf(u.pathname))) continue;
      found.add(u.pathname);
    } catch (e) {}
  }
  return Array.from(found);
}

function extractCssUrls(cssText, origin, cssPathname) {
  const found = new Set();
  const re = /url\(\s*['"]?([^'")]+)['"]?\s*\)/gi;
  let m;
  while ((m = re.exec(cssText)) !== null) {
    let raw = m[1];
    if (!raw || raw.startsWith('data:')) continue;
    try {
      const base = origin + cssPathname;
      const u = new URL(raw, base);
      if (u.origin !== origin) continue;
      if (!ASSET_EXT.has(extOf(u.pathname))) continue;
      found.add(u.pathname);
    } catch (e) {}
  }
  return Array.from(found);
}

function assetFileName(pathname) {
  const hash = crypto.createHash('sha1').update(pathname).digest('hex').slice(0, 16);
  return hash + extOf(pathname);
}

async function captureSnapshot(ses, origin, pageUrl) {
  if (captureInFlight) return false;
  captureInFlight = true;
  try {
    // bypassCustomProtocolHandlers is MANDATORY here: the capture fetch must
    // hit the real network, never our own interception handler — otherwise an
    // offline cold start would re-save the snapshot FROM the snapshot (stale
    // savedAt shown as fresh + stacked banners).
    const res = await sesFetch(ses, pageUrl, {
      credentials: 'include',
      bypassCustomProtocolHandlers: true,
    });
    if (!res || !res.ok) { log('capture skipped: page fetch not ok'); return false; }
    // If the session expired the server redirects to the login page — never
    // save that as the "sale screen". The sale screen is unmistakable:
    const html = await res.text();
    const finalPath = (() => { try { return new URL(res.url || pageUrl).pathname; } catch (e) { return ''; } })();
    const looksLikeSaleScreen =
      finalPath.startsWith(SALE_PATH) &&
      html.length > 50000 &&
      !html.includes('tn-offline-banner') && // never re-capture a snapshot-served page
      (html.includes('restaurantPos(') || html.includes('Current Order') || html.includes('pos/invoice'));
    if (!looksLikeSaleScreen) { log('capture skipped: not the sale screen (login redirect?)'); return false; }

    // Page assets FIRST (css/js keep the screen usable), product images after —
    // if the caps bite, pictures are the right thing to lose.
    const wanted = extractAssetPaths(html, origin)
      .concat(extractEmbeddedImagePaths(html, origin))
      .filter((p, i, arr) => arr.indexOf(p) === i)
      .slice(0, MAX_ASSETS);
    const dir = snapshotDir();
    const aDir = assetsDir();
    await fsp.mkdir(aDir, { recursive: true });

    const assets = {};
    let totalBytes = 0;
    const cssQueue = [];

    async function grab(pathname) {
      if (assets[pathname]) return;
      if (Object.keys(assets).length >= MAX_ASSETS) return;
      if (totalBytes > MAX_TOTAL_BYTES) return;
      try {
        const r = await sesFetch(ses, origin + pathname, {
          credentials: 'include',
          bypassCustomProtocolHandlers: true,
        });
        if (!r || !r.ok) return;
        const buf = Buffer.from(await r.arrayBuffer());
        if (buf.length > MAX_ASSET_BYTES) return;
        totalBytes += buf.length;
        const file = assetFileName(pathname);
        await fsp.writeFile(path.join(aDir, file), buf);
        assets[pathname] = file;
        if (extOf(pathname) === '.css') cssQueue.push({ pathname, text: buf.toString('utf8') });
      } catch (e) { /* individual asset failure is fine */ }
    }

    for (const p of wanted) await grab(p);
    // One level of CSS url(...) references (fonts, background images).
    for (const css of cssQueue.splice(0)) {
      for (const p of extractCssUrls(css.text, origin, css.pathname)) await grab(p);
    }

    await fsp.writeFile(pagePath(), html, 'utf8');
    const manifest = {
      savedAt: new Date().toISOString(),
      pageUrl,
      origin,
      assets,
    };
    const tmp = manifestPath() + '.tmp';
    await fsp.writeFile(tmp, JSON.stringify(manifest), 'utf8');
    await fsp.rename(tmp, manifestPath());
    manifestCache = manifest;
    lastCaptureAt = Date.now();
    log('snapshot saved: ' + Object.keys(assets).length + ' assets, ' + Math.round(totalBytes / 1024) + ' KB');
    return true;
  } catch (e) {
    log('capture failed: ' + (e && e.message));
    return false;
  } finally {
    captureInFlight = false;
  }
}

// Called on every successful sale-screen load; throttled + delayed.
function scheduleCapture(ses, origin, pageUrl) {
  try {
    if (Date.now() - lastCaptureAt < CAPTURE_THROTTLE_MS) return;
    if (captureTimer) return;
    captureTimer = setTimeout(() => {
      captureTimer = null;
      captureSnapshot(ses, origin, pageUrl).catch(() => {});
    }, CAPTURE_DELAY_MS);
  } catch (e) {}
}

// ─── Offline serving ─────────────────────────────────────────────────────────

function formatSavedAt(iso) {
  try {
    return new Date(iso).toLocaleString('en-GB', {
      timeZone: 'Asia/Karachi',
      day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit',
    });
  } catch (e) { return iso; }
}

function injectOfflineBanner(html, savedAtIso) {
  const when = formatSavedAt(savedAtIso);
  const banner =
    '<div id="tn-offline-banner" style="position:fixed;top:44px;left:50%;transform:translateX(-50%);' +
    'z-index:2147483647;background:#B45309;color:#fff;font:600 13px/1.4 system-ui,sans-serif;' +
    'padding:7px 14px;border-radius:9999px;box-shadow:none;display:flex;gap:10px;align-items:center;">' +
    '<span>&#9888;&#65039; Offline mode &mdash; bills mehfooz ho rahe hain, net aate hi khud sync honge. ' +
    'Screen ki aakhri update: ' + when + '</span>' +
    '<button onclick="location.reload()" style="background:#0A4D5C;color:#fff;border:0;border-radius:9999px;' +
    'padding:4px 12px;font:600 12px system-ui,sans-serif;cursor:pointer;">Dobara Try</button>' +
    '</div>' +
    '<script>window.__tnOfflineSnapshot={savedAt:' + JSON.stringify(savedAtIso) + '};' +
    'window.addEventListener("online",function(){setTimeout(function(){try{location.reload()}catch(e){}},1500)});' +
    '<\/script>';
  const idx = html.toLowerCase().lastIndexOf('</body>');
  if (idx === -1) return html + banner;
  return html.slice(0, idx) + banner + html.slice(idx);
}

async function serveOffline(request, origin) {
  try {
    if (!request || request.method !== 'GET') return null;
    const u = new URL(request.url);
    if (u.origin !== origin) return null;
    const m = loadManifest();
    if (!m) return null;

    // The sale screen itself.
    if (u.pathname === SALE_PATH || u.pathname.startsWith(SALE_PATH)) {
      const html = await fsp.readFile(pagePath(), 'utf8');
      return new Response(injectOfflineBanner(html, m.savedAt), {
        status: 200,
        headers: { 'content-type': MIME['.html'], 'cache-control': 'no-store' },
      });
    }

    // A captured static asset.
    const file = m.assets && m.assets[u.pathname];
    if (file) {
      const buf = await fsp.readFile(path.join(assetsDir(), file));
      const mime = MIME[extOf(u.pathname)] || 'application/octet-stream';
      return new Response(buf, {
        status: 200,
        headers: { 'content-type': mime, 'cache-control': 'no-store' },
      });
    }

    // Any other page navigation while offline → send it to the sale screen
    // (which we CAN serve) instead of a dead white page.
    const accept = String(request.headers.get('accept') || '');
    if (accept.includes('text/html')) {
      return Response.redirect(origin + SALE_PATH, 302);
    }
    return null; // XHR/fetch for uncaptured data → genuine network error
  } catch (e) {
    return null;
  }
}

// ─── Interception ────────────────────────────────────────────────────────────

// Registers the https passthrough on the given session (persist:pos).
// isEnabled() is re-checked on every failure so turning the setting OFF stops
// snapshot serving immediately (passthrough itself is behavior-neutral).
function registerOfflineInterception(ses, origin, isEnabled) {
  try {
    if (!ses || registeredSessions.has(ses)) return false;
    ses.protocol.handle('https', async (request) => {
      try {
        return await sesFetch(ses, request, { bypassCustomProtocolHandlers: true });
      } catch (err) {
        if (typeof isEnabled === 'function' && !isEnabled()) throw err;
        const offline = await serveOffline(request, origin);
        if (offline) return offline;
        throw err;
      }
    });
    registeredSessions.add(ses);
    log('https interception registered for ' + origin);
    return true;
  } catch (e) {
    log('interception registration failed: ' + (e && e.message));
    return false;
  }
}

module.exports = {
  registerOfflineInterception,
  scheduleCapture,
  captureSnapshot,
  hasSnapshot,
  snapshotInfo,
  // exported for tests
  extractAssetPaths,
  extractEmbeddedImagePaths,
  extractCssUrls,
  injectOfflineBanner,
  serveOffline,
};
