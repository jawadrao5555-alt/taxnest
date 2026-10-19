import {readdirSync} from 'node:fs';
import {launchLocalBrowser, assertLocalOnlyBaseUrl, saveEvidenceScreenshot} from './lib/local-browser.mjs';
const base = assertLocalOnlyBaseUrl(process.env.BASE_URL);
const {browser} = await launchLocalBrowser();
try {
  for (const width of [1366, 390]) {
    const context = await browser.newContext({viewport:{width,height:900}});
    await context.route('**/*', route => ['127.0.0.1','localhost','[::1]'].includes(new URL(route.request().url()).hostname) ? route.continue() : route.abort('blockedbyclient'));
    const page = await context.newPage(); const errors=[];
    page.on('pageerror', e => errors.push(e.message));
    for (const file of readdirSync(new URL('./public-website-checks/', import.meta.url)).filter(f=>f.endsWith('.mjs')).sort()) {
      const {default:check}=await import('./public-website-checks/'+file);
      await check(page,base);
      if(errors.length)throw new Error(errors.join('\n'));
      await saveEvidenceScreenshot(page, 'public-'+file+'-'+width);
      console.log('PASS: public website '+file+' '+width+'px');
    }
    await context.close();
  }
} finally {await browser.close();}
