// DI Promo — title card renderer.
// Generates 6 PNG cards (hook, brand, compliance, features, cta, end) at 1280×720.
//
// Usage (from repo root):
//   node scripts/video/di-promo/di-cards.js
//
// Output: .local/video-studio/di-promo/cards/*.png  (gitignored)
//
// Requires: playwright-core installed under .local/video-studio/node_modules
//           Chromium at the path defined by CHROMIUM env var or the Nix-store default.
//           Network access for Google Fonts (Noto Nastaliq Urdu).
//
// Design: dark teal #052730 bg, gold #E7BF3B accents, Playfair/Georgia Latin,
//         Noto Nastaliq Urdu for Arabic-script headings.
// Font note: Nastaliq glyphs must be ALL Arabic-script in one heading (no Latin mixing
//   on the same bidi run) — isolated Urdu words next to Latin appear tiny due to
//   Nastaliq's large descender ratio vs Latin em-box.

const { chromium } = require('playwright-core');
const path = require('path');
const fs   = require('fs');

const CHROME = process.env.CHROMIUM
  || '/nix/store/qa9cnw4v5xkxyip6mb9kxqfq1z4x2dx1-chromium-138.0.7204.100/bin/chromium';

const OUT_BASE = process.env.DI_OUT_DIR
  || path.join(__dirname, '../../../.local/video-studio');
const OUT_DIR  = path.join(OUT_BASE, 'di-promo', 'cards');

fs.mkdirSync(OUT_DIR, { recursive: true });

// Shared <head>: Noto Nastaliq Urdu for Arabic-script glyphs;
// Georgia/Playfair for Latin runs (browser per-character fallback).
const head = `
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;700&display=swap" rel="stylesheet">
  <style>
    * { margin:0; box-sizing:border-box; }
    body { width:1280px; height:720px; background:#052730; overflow:hidden;
           font-family:'Noto Nastaliq Urdu',Georgia,'Playfair Display',serif;
           display:flex; align-items:center; justify-content:center; position:relative; }
    body::before { content:''; position:absolute; inset:0;
           background:radial-gradient(ellipse at 25% 15%, rgba(231,191,59,.10), transparent 55%); }
    .strip { position:absolute; left:0; right:0; bottom:0; height:6px; background:#E7BF3B; }
    .wrap { text-align:center; padding:0 70px; position:relative; z-index:1; }
    .bar  { width:100px; height:5px; background:#E7BF3B; border-radius:3px; margin:28px auto; }
    h1 { color:#fff; font-weight:700; line-height:1.4;
         font-family:'Noto Nastaliq Urdu', Georgia, 'Playfair Display', serif; }
    .h1-latin { color:#fff; font-weight:800; letter-spacing:-0.5px; line-height:1.15;
                font-family:Georgia,'Playfair Display',serif; }
    .sub  { color:#b8d4de; font-weight:400; line-height:1.6;
            font-family:'Noto Nastaliq Urdu', system-ui, 'DejaVu Sans', sans-serif; }
    .gold { color:#E7BF3B; }
    .faint{ color:#5d8090; font-family:system-ui,'DejaVu Sans',sans-serif; }
    .wm   { font-weight:800; letter-spacing:1px; color:#fff;
            font-family:Georgia,'Playfair Display',serif; }
    .tick { color:#4ade80; }
    .row  { display:flex; gap:40px; justify-content:center; align-items:center; }
    .chip { background:rgba(231,191,59,.12); border:1px solid rgba(231,191,59,.35);
            border-radius:8px; padding:14px 28px; color:#E7BF3B;
            font-family:system-ui,'DejaVu Sans',sans-serif; font-weight:600;
            font-size:22px; white-space:nowrap; }
  </style>`;

const cards = {
  hook: `<div class="wrap">
    <h1 style="font-size:68px" dir="rtl">کیا آپ کا کاروبار<br>
      <span class="gold">FBR registered</span> ہے؟</h1>
    <div class="bar"></div>
    <div class="sub" style="font-size:36px" dir="rtl">ہر invoice قانونی ہونا ضروری ہے۔</div>
  </div>`,

  brand: `<div class="wrap">
    <div class="wm" style="font-size:52px;letter-spacing:2px;color:#b8d4de">DIGITAL</div>
    <div class="wm" style="font-size:96px;margin-top:-8px">INVOICE</div>
    <div class="faint" style="font-size:22px;letter-spacing:5px;margin-top:4px">BY TAXNEST</div>
    <div class="bar"></div>
    <div class="sub" style="font-size:34px" dir="rtl">آسان FBR invoicing &mdash; ہر کاروبار کے لیے</div>
  </div>`,

  // "ہر بل" (every invoice) — both words fully Urdu so Nastaliq renders at full size.
  // "ہر" alone next to Latin text renders tiny (Nastaliq descender ratio vs Latin em-box).
  compliance: `<div class="wrap">
    <h1 style="font-size:72px;padding-bottom:36px" dir="rtl">ہر <span class="gold">بل</span></h1>
    <div class="h1-latin" style="font-size:64px;margin-top:0">
      FBR&ensp;<span class="gold">compliant</span>&ensp;<span class="tick">&#10003;</span>
    </div>
    <div class="bar"></div>
    <div class="row" style="margin-top:8px">
      <div class="chip">Risk Score</div>
      <div class="chip">Smart Mode</div>
      <div class="chip">Integrity Check</div>
    </div>
  </div>`,

  features: `<div class="wrap">
    <div class="h1-latin" style="font-size:52px">Invoice &bull; FBR Reporting &bull; PDF</div>
    <div class="h1-latin" style="font-size:52px;margin-top:14px">Email &bull; <span class="gold">QR Code</span> &bull; AI Reader</div>
    <div class="bar"></div>
    <div class="sub" style="font-size:38px" dir="rtl">سب ایک platform پر</div>
  </div>`,

  cta: `<div class="wrap">
    <h1 style="font-size:68px" dir="rtl">آج ہی <span class="gold">MUFT trial</span><br>شروع کریں</h1>
    <div class="bar"></div>
    <div class="gold" style="font-size:48px;font-weight:800;
         font-family:system-ui,'DejaVu Sans',sans-serif;letter-spacing:1px">taxnest.com.pk</div>
  </div>`,

  end: `<div class="wrap">
    <div class="wm" style="font-size:52px;letter-spacing:2px;color:#b8d4de">DIGITAL</div>
    <div class="wm" style="font-size:96px;margin-top:-8px">INVOICE</div>
    <div class="faint" style="font-size:22px;letter-spacing:5px;margin-top:4px">BY TAXNEST</div>
    <div class="bar"></div>
    <div class="faint" style="font-size:30px;margin-top:12px">taxnest.com.pk</div>
  </div>`,
};

(async () => {
  const browser = await chromium.launch({
    executablePath: CHROME,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });
  const page = await browser.newPage({ viewport: { width: 1280, height: 720 } });

  for (const [name, body] of Object.entries(cards)) {
    await page.setContent(
      `<!doctype html><html><head>${head}</head><body>${body}<div class="strip"></div></body></html>`
    );
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(600);
    const out = path.join(OUT_DIR, `${name}.png`);
    await page.screenshot({ path: out });
    console.log('CARD', name, '→', out);
  }

  await browser.close();
  console.log('CARDS OK — 6 cards written to', OUT_DIR);
  process.exit(0);
})().catch(e => {
  console.error('CARDS FAIL', e.message);
  process.exit(1);
});
