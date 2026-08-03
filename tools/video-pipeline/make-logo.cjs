// One-off: render the fictional Al-Noor demo logo PNG (Task 234).
const { execSync } = require('child_process');
const { chromium } = require('playwright-core');
(async () => {
  const b = await chromium.launch({ executablePath: execSync('which chromium').toString().trim(), headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  const p = await b.newPage({ viewport: { width: 400, height: 400 } });
  await p.setContent(`<html><body style="margin:0;width:400px;height:400px;display:flex;align-items:center;justify-content:center;background:#0A4D5C;font-family:Ubuntu,sans-serif"><div style="text-align:center;color:#fff"><div style="width:90px;height:90px;margin:0 auto;border:6px solid #E7BF3B;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:52px;font-weight:900;color:#E7BF3B">A</div><div style="font-size:44px;font-weight:800;margin-top:14px">Al-Noor</div><div style="font-size:20px;color:#E7BF3B;letter-spacing:4px;font-weight:700">GENERAL STORE</div></div></body></html>`);
  await p.screenshot({ path: __dirname + '/assets/alnoor-logo.png' });
  await b.close();
  console.log('logo done');
})();
