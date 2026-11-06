// Synthetic loopback component reproduction using the actual sale-screen methods.
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {execFileSync} from 'node:child_process';
import {createRequire} from 'node:module';
import assert from 'node:assert/strict';
import {assertLocalOnlyBaseUrl,launchLocalBrowser,saveEvidenceScreenshot} from '../lib/local-browser.mjs';
const require=createRequire(import.meta.url),root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const original=execFileSync('git',['show',(process.argv[2]||'HEAD^')+':resources/views/pos/universal.blade.php'],{cwd:root,encoding:'utf8',maxBuffer:4*1024*1024});
const updated=fs.readFileSync(path.join(root,'resources/views/pos/universal.blade.php'),'utf8');
function fixture(source){
 const method=name=>{const start=source.indexOf('        '+name);return source.slice(start,source.indexOf('\n        },',start)+11);};
 const condition=source.match(/<div[^>]*x-show="([^"]*callerDialFallback[^"]*)"[^>]*:style="callerOverlayStyle\(330\)"/)[1];
 return `<!doctype html><style>[x-cloak]{display:none!important}body{font:16px Arial;background:#f5f7f8;margin:0}header{height:64px;background:#073c45;color:white}button{margin:8px;padding:12px}#dial{position:fixed;right:16px;border:1px solid #aaa;background:white}#dial h2{background:#f5a400;color:white;padding:12px;margin:0}</style>
 <header data-tn-topnav="pra">Synthetic POS header — component QA only</header>
 <script>window.fixture=()=>({callerDialFallback:null,callerOverlayEpoch:0,${method('callerOverlayStyle(')}${method('callerNumberOf(ev) {')}});</script>
 <script defer src="/alpine.js"></script><main x-data="fixture()" @resize.window="callerOverlayEpoch++">
 <button style="margin-top:260px" @click="callerDialFallback={phone:'03000000000'}">Show actual number</button>
 <div id="dial" x-cloak x-show="${condition}" :style="callerOverlayStyle(330)"><h2>Yeh number milayein</h2><p x-text="callerDialFallback ? callerDialFallback.phone : ''"></p><button @click="callerDialFallback=null">Close fallback</button></div></main>`;
}
const server=http.createServer((req,res)=>{
 if(req.url==='/alpine.js'){res.setHeader('Content-Type','text/javascript');return res.end(fs.readFileSync(require.resolve('alpinejs/dist/cdn.js')));}
 if(req.url==='/baseline'||req.url==='/updated'){res.setHeader('Content-Type','text/html');return res.end(fixture(req.url==='/baseline'?original:updated));}
 res.writeHead(404);res.end();
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const base=assertLocalOnlyBaseUrl('http://127.0.0.1:'+server.address().port);
let browser;
try{
 ({browser}=await launchLocalBrowser());
 for(const width of [1280,390]){
  const context=await browser.newContext({viewport:{width,height:780}});
  await context.route('**/*',route=>new URL(route.request().url()).origin===base?route.continue():route.abort());
  const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  for(const version of ['baseline','updated']){
   await page.goto(base+'/'+version);await page.waitForFunction(()=>window.Alpine&&!document.querySelector('#dial').hasAttribute('x-cloak'));
   await page.evaluate(()=>window.dispatchEvent(new Event('resize')));
   if(version==='baseline')await page.locator('#dial').waitFor({state:'visible'});else await page.locator('#dial').waitFor({state:'hidden'});
   await saveEvidenceScreenshot(page,'caller-'+version+'-idle-'+width);
   await page.getByRole('button',{name:'Show actual number',exact:true}).click();await page.locator('#dial').waitFor({state:'visible'});
   assert((await page.locator('#dial').innerText()).includes('03000000000'));
   await saveEvidenceScreenshot(page,'caller-'+version+'-valid-'+width);
   await page.getByRole('button',{name:'Close fallback',exact:true}).click();
   await page.locator('#dial').waitFor({state:'hidden'});
   await page.evaluate(()=>window.dispatchEvent(new Event('resize')));
   if(version==='baseline')await page.locator('#dial').waitFor({state:'visible'});else await page.locator('#dial').waitFor({state:'hidden'});
  }
  assert.deepEqual(errors,[]);await context.close();console.log('PASS actual Chromium component baseline/fix/valid/close/resize at '+width+'px');
 }
}finally{if(browser)await browser.close();await new Promise(resolve=>server.close(resolve));}
