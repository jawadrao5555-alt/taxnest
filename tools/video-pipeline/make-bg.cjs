// Render the branded 1080x1920 background card for the 9:16 version via
// headless Chromium (drawtext font discovery is unreliable in this workspace).
// Usage: node tools/video-pipeline/make-bg.cjs <slug> "<subtitle>"
const path = require('path');
const { execSync } = require('child_process');
const { chromium } = require('playwright-core');
const slug = process.argv[2];
const subtitle = process.argv[3] || 'Tutorial';
const out = path.join(__dirname, 'out', slug, 'bg.png');
(async () => {
  const b = await chromium.launch({ executablePath: execSync('which chromium').toString().trim(), headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  const p = await b.newPage({ viewport: { width: 1080, height: 1920 } });
  await p.setContent(`<html><body style="margin:0;width:1080px;height:1920px;
    background:radial-gradient(120% 80% at 50% 30%, #0E5B6C 0%, #0A4D5C 55%, #073843 100%);
    font-family:'Segoe UI',Ubuntu,'DejaVu Sans',sans-serif;color:#fff">
    <div style="text-align:center;padding-top:250px">
      <div style="font-size:96px;font-weight:800;letter-spacing:-1px">Nest<span style="color:#E7BF3B">POS</span></div>
      <div style="width:120px;height:5px;background:#E7BF3B;margin:36px auto"></div>
      <div style="font-size:46px;font-weight:600;color:#EAF4F6">${subtitle}</div>
    </div>
    <div style="position:absolute;bottom:150px;width:100%;text-align:center;font-size:38px;color:#BFDCE3">NestPOS &mdash; Pakistan ka apna POS</div>
  </body></html>`);
  await p.screenshot({ path: out });
  await b.close();
  console.log('✓', out);
})();
