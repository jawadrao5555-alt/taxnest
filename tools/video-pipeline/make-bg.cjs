// Render the branded background card for the framed 9:16 / 1:1 versions via
// headless Chromium (drawtext font discovery is unreliable in this workspace).
// Usage: node tools/video-pipeline/make-bg.cjs <slug> "<subtitle>" [9x16|1x1]
const path = require('path');
const { execSync } = require('child_process');
const { chromium } = require('playwright-core');
const slug = process.argv[2];
const subtitle = process.argv[3] || 'Tutorial';
const aspect = process.argv[4] || '9x16';
const DIMS = { '9x16': { w: 1080, h: 1920 }, '1x1': { w: 1080, h: 1080 } };
if (!DIMS[aspect]) { console.error('aspect must be 9x16 or 1x1'); process.exit(1); }
const { w, h } = DIMS[aspect];
// 1:1 has far less vertical room: tighter header, smaller type, footer closer in.
const L = aspect === '1x1'
  ? { padTop: 90, brand: 72, barMargin: 22, sub: 34, footBottom: 40, foot: 28 }
  : { padTop: 250, brand: 96, barMargin: 36, sub: 46, footBottom: 150, foot: 38 };
const out = path.join(__dirname, 'out', slug, aspect === '1x1' ? 'bg-1x1.png' : 'bg.png');
(async () => {
  const b = await chromium.launch({ executablePath: execSync('which chromium').toString().trim(), headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  const p = await b.newPage({ viewport: { width: w, height: h } });
  await p.setContent(`<html><body style="margin:0;width:${w}px;height:${h}px;
    background:radial-gradient(120% 80% at 50% 30%, #0E5B6C 0%, #0A4D5C 55%, #073843 100%);
    font-family:'Segoe UI',Ubuntu,'DejaVu Sans',sans-serif;color:#fff">
    <div style="text-align:center;padding-top:${L.padTop}px">
      <div style="font-size:${L.brand}px;font-weight:800;letter-spacing:-1px">Nest<span style="color:#E7BF3B">POS</span></div>
      <div style="width:120px;height:5px;background:#E7BF3B;margin:${L.barMargin}px auto"></div>
      <div style="font-size:${L.sub}px;font-weight:600;color:#EAF4F6">${subtitle}</div>
    </div>
    <div style="position:absolute;bottom:${L.footBottom}px;width:100%;text-align:center;font-size:${L.foot}px;color:#BFDCE3">NestPOS &mdash; Pakistan ka apna POS</div>
  </body></html>`);
  await p.screenshot({ path: out });
  await b.close();
  console.log('✓', out);
})();
