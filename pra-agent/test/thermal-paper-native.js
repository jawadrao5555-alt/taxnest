'use strict';
// Run with the pinned Electron runtime, --no-sandbox --ozone-platform=headless.
// No printer, live account, HTTP endpoint or physical spool submission is used.
const {app, BrowserWindow, ipcMain} = require('electron');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {prepareThermalPaper} = require('../src/thermal-paper');
app.disableHardwareAcceleration();
// Keep the test process alive between fixtures. Electron otherwise quits with
// exit 0 when the first window is destroyed, skipping the remaining assertions.
app.on('window-all-closed', () => {});
const out = path.resolve(__dirname, '../../.local/thermal-paper-evidence');
fs.mkdirSync(out,{recursive:true});
const watchdog = setTimeout(() => { console.error('Native paper test timed out'); app.exit(1); },60000);
app.whenReady().then(async () => {
  for (const width of [58,80]) {
    for (const rows of [1,40]) {
      const win = new BrowserWindow({show:false,width:420,height:800,webPreferences:{sandbox:true,contextIsolation:true}});
      const fixture = `<html><head><style>
      @page { size:${width}mm 3276mm; margin:0 }
      *{box-sizing:border-box;margin:0} body{font:13px Arial;width:80mm;padding:3mm}
      table{width:100%;border-collapse:collapse}td{border-bottom:1px solid;padding:3px}
      @media print{body{width:auto;max-width:72mm;padding:3mm}.no-print{display:none}}
      </style></head><body><h3>TEST KITCHEN</h3><table><tr><th>ITEM</th><th>QTY</th></tr>
      ${Array.from({length:rows},(_,i)=>`<tr><td>Test item ${i+1} with a long wrapped description</td><td>2</td></tr>`).join('')}
      </table><svg width="120" height="35"><rect x="0" width="4" height="35"/><rect x="8" width="3" height="35"/><rect x="14" width="6" height="35"/></svg>
      <p id="footer">END OF TEST TICKET</p><div class="no-print" style="height:500px">Screen buttons</div></body></html>`;
      const file=path.join(out,`${width}-${rows}.html`);fs.writeFileSync(file,fixture);
      await win.loadFile(file);
      if(rows===1) {
        const before=await win.webContents.printToPDF({preferCSSPageSize:true,printBackground:true});
        fs.writeFileSync(path.join(out,`${width}-before.pdf`),before);
        assert.match(before.toString('latin1'),/9286/,'3276mm driver-like form reproduced as very long page');
      }
      const size=await prepareThermalPaper(win.webContents,width);
      assert.equal(size.width,width*1000);
      assert.ok(size.height>20000 && size.height<1000000);
      const metrics=await win.webContents.executeJavaScript(`({bodyWidth:document.body.getBoundingClientRect().width,footerBottom:document.querySelector('#footer').getBoundingClientRect().bottom,buttons:getComputedStyle(document.querySelector('.no-print')).display,rows:document.querySelectorAll('tr').length})`);
      assert.equal(metrics.buttons,'none');assert.equal(metrics.rows,rows+1);
      assert.ok(metrics.footerBottom*25.4/96<size.height/1000);
      assert.ok(Math.abs(metrics.bodyWidth-(width===58?48:72)*96/25.4)<1);
      const pdf=await win.webContents.printToPDF({preferCSSPageSize:true,printBackground:true});
      fs.writeFileSync(path.join(out,`${width}-${rows}-after.pdf`),pdf);
      const media=[...pdf.toString('latin1').matchAll(/\/MediaBox\s*\[([^\]]+)\]/g)];
      assert.equal(media.length,1,'all items and footer fit on ONE page');
      const dims=media[0][1].trim().split(/\s+/).map(Number);
      assert.ok(Math.abs(dims[2]*25.4/72-width)<1);
      assert.ok(Math.abs(dims[3]*25.4/72-size.height/1000)<1);
      console.log(JSON.stringify({width,rows,size,metrics,pdfPoints:dims}));
      win.destroy();
    }
  }
  // Real Agent settings DOM + real UI module, mocked IPC only (never starts Agent).
  let saved;
  ipcMain.handle('thermal-fixture-save', (_event,config) => {saved=config;return {ok:true};});
  const preload=path.join(out,'settings-preload.cjs');
  fs.writeFileSync(preload, `
    const {contextBridge,ipcRenderer}=require('electron');
    const api={
      getConfig:async()=>({serverUrl:'http://127.0.0.1:8000/api/agent',apiKey:'fixture-only',receiptPrinter:'Counter',printerPaperProfiles:{'Kitchen <one>':58,Unavailable:80}}),
      getPrinters:async()=>({printers:[{name:'Kitchen <one>'},{name:'Counter'}]}),
      saveConfig:config=>ipcRenderer.invoke('thermal-fixture-save',config)
    };
    for(const key of ['getVersion','getStatus','getLanSettings','getLanStatus','getPosSettings','lanListDevices','checkImsService']) api[key]=async()=>({});
    for(const key of ['onConfigAutofilled','onStatusUpdate','onUpdateAvailable','onImsProgress']) api[key]=()=>{};
    api.testConnection=async()=>({ok:false});
    contextBridge.exposeInMainWorld('agentAPI',api);
  `);
  const ui=new BrowserWindow({show:true,width:900,height:1400,webPreferences:{preload,sandbox:true,contextIsolation:true,backgroundThrottling:false}});
  const errors=[];
  ui.webContents.on('console-message', (_event, level, message) => { if(level>=3) errors.push(message); });
  await ui.loadFile(path.join(__dirname,'../index.html'));
  console.log('Settings fixture: loaded');
  await new Promise(resolve=>setTimeout(resolve,100));
  await ui.webContents.executeJavaScript(`window.testProfiles=printerPaperProfiles;document.querySelector('details').open=true;`);
  console.log('Settings fixture: profiles loaded');
  const state=await ui.webContents.executeJavaScript(`(()=>{const c=document.getElementById('printerPaperModes');const selects=c.querySelectorAll('select');if(selects.length!==3)throw Error('missing printer');selects[1].value='80';selects[1].dispatchEvent(new Event('change'));return {profiles:window.testProfiles,html:c.innerHTML};})()`);
  assert.deepEqual(state.profiles,{'Kitchen <one>':58,Counter:80,Unavailable:80});
  assert.ok(state.html.includes('&lt;one&gt;'),'printer names are text, not injected markup');
  await ui.webContents.executeJavaScript(`document.getElementById('saveBtn').click()`);
  await new Promise(resolve=>setTimeout(resolve,100));
  assert.equal(saved.printerExplicit,false);
  assert.deepEqual(saved.printerPaperProfiles,state.profiles);
  assert.deepEqual(errors,[], 'Agent settings must render without JavaScript errors');
  console.log('Settings fixture: save assertions passed');
  fs.writeFileSync(path.join(out,'settings.png'),(await ui.webContents.capturePage()).toPNG());
  ui.destroy();clearTimeout(watchdog);console.log('Native thermal paper and settings checks passed');app.exit(0);
}).catch(error=>{console.error(error);clearTimeout(watchdog);app.exit(1);});
