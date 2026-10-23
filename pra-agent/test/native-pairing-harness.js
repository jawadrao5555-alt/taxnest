'use strict';
// Disposable Electron process + actual packaged HTML/preload/windows/coordinator.
// Only loopback fictional HTTP fixtures. No production main, printers or registry.
const { app, BrowserWindow, ipcMain, dialog, session } = require('electron');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const { spawn } = require('node:child_process');
const root = path.resolve(process.argv[2]);
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'taxnest-native-pairing-'));
app.setPath('userData', temp);
const { createCompanyConfigurator } = require(path.join(root, 'src/company-configurator'));
const windows = require(path.join(root, 'src/pos-window'));
let server, settings, origin, config, running = false, mode = 'Connect', posts = 0, saves = 0, nativeDialogs = 0;
let fixtureCompany = 101, reject = false, lastResult, nativeError;
const clients = new Set(), errors = [], calls = { test: 0, save: 0, toggle: 0, open: 0 };
const key = id => 'fixture-key-' + id;
const sha = value => crypto.createHash('sha256').update(value).digest('hex');
const wait = async (predicate, label) => {
  const end = Date.now() + 25000;
  while (Date.now() < end) { if (nativeError) throw nativeError; if (await predicate()) return; await new Promise(r => setTimeout(r, 50)); }
  throw Error('Timed out: ' + label);
};
async function click(win, selector) {
  const rect = await win.webContents.executeJavaScript(`(() => { const el=document.querySelector(${JSON.stringify(selector)}); if(!el)throw Error('Missing control'); el.scrollIntoView({block:'center'}); const r=el.getBoundingClientRect(); return {x:Math.round(r.x+r.width/2),y:Math.round(r.y+r.height/2)}; })()`);
  win.focus();
  win.webContents.sendInputEvent({type:'mouseDown',button:'left',clickCount:1,...rect});
  win.webContents.sendInputEvent({type:'mouseUp',button:'left',clickCount:1,...rect});
}
function watch(win) {
  win.webContents.on('preload-error', (_event, _file, error) => errors.push('preload: '+error.message));
  win.webContents.on('console-message', (_event, details) => { if(details.level === 'error') errors.push(details.message); });
  return win;
}
function handle(name, fn) { ipcMain.handle(name, fn); }
const status = () => ({running,connected:false,logs:[],stats:{},lastError:null});
const configurator = createCompanyConfigurator({
  getConfig: () => ({...config}),
  saveConfig: next => { config = next; saves++; fs.writeFileSync(path.join(temp,'fictional-config.json'),JSON.stringify(next)); },
  onConfigured: () => {}, // Intentionally no Agent lifecycle, fiscal requests or printing.
  showMessageBox: async options => {
    nativeDialogs++;
    assert.ok(options.message.includes('Fixture '+fixtureCompany));
    assert.ok(!JSON.stringify(options).includes(key(fixtureCompany)));
    assert.equal(options.defaultId,0); assert.equal(options.cancelId,0);
    if (process.platform !== 'win32') throw Error('Native UI acceptance requires Windows');
    const driver = spawn('powershell.exe',['-NoProfile','-NonInteractive','-File',path.join(__dirname,'native-dialog-click.ps1'),'-TargetProcessId',String(process.pid),'-ExpectedCompany','Fixture '+fixtureCompany,'-Button',mode],{windowsHide:true});
    const driverDone = new Promise((resolve,rejectPromise) => {
      let stderr=''; driver.stderr.on('data',d=>{stderr+=d;});
      driver.on('error',rejectPromise); driver.on('exit',code=>code===0?resolve():rejectPromise(Error('Native UI driver failed: '+stderr)));
    });
    driverDone.catch(e=>{nativeError=e;});
    const result = await dialog.showMessageBox(settings,options);
    await driverDone;
    assert.equal(result.response,mode==='Connect'?1:0);
    return result;
  },
});
async function login(panel) {
  lastResult=undefined;
  const cb=async(s,o,current)=>{lastResult=await configurator(s,o,panel,current);return lastResult;};
  const win=watch(panel==='pos'?windows.openPosWindow({serverUrl:origin+'/api/agent'},{onLoggedIn:cb,isQuitting:()=>true}):windows.openFbrPosWindow({serverUrl:origin+'/api/agent'},{onLoggedIn:cb,isQuitting:()=>true}));
  await wait(()=>win.webContents.getURL().endsWith('/login')&&!win.webContents.isLoading(),'fictional login '+panel);
  await click(win,'#fixtureLogin');
  await wait(()=>lastResult!==undefined,'native confirmed pairing '+panel);
  return win;
}
async function logout(win,panel) {
  await win.loadURL(origin+(panel==='pos'?'/pos':'/fbr-pos')+'/logout');
  await wait(()=>win.webContents.getURL().endsWith('/login'),'logout '+panel);
  await new Promise(r=>setTimeout(r,100));
}
(async()=>{
  await app.whenReady();
  // Reject every external request from default and the real production partitions.
  for(const ses of [session.defaultSession,session.fromPartition('persist:pos'),session.fromPartition('persist:fbrpos')]) {
    ses.webRequest.onBeforeRequest((details,callback)=>{
      const u=new URL(details.url);callback({cancel:!['file:','devtools:','data:'].includes(u.protocol)&&!(u.protocol==='http:'&&u.hostname==='127.0.0.1')});
    });
  }
  const claims=new Map();
  server=http.createServer((req,res)=>{
    const url=new URL(req.url,'http://127.0.0.1'); const prefix=url.pathname.startsWith('/fbr-pos/')?'/fbr-pos':'/pos';
    const send=(code,body,headers={})=>{res.writeHead(code,{'Cache-Control':'no-store',...headers});res.end(body);};
    if(url.pathname.endsWith('/login')) return send(200,`<html><body><form action="${prefix}/signin" method="post"><button id="fixtureLogin">Fictional company login</button></form></body></html>`,{'Content-Type':'text/html'});
    if(url.pathname.endsWith('/signin')) { const sid=crypto.randomBytes(16).toString('hex');clients.add(sid);return send(302,'',{Location:prefix+'/invoice/create','Set-Cookie':'fixtureSession='+sid+'; HttpOnly; SameSite=Strict; Path='+prefix}); }
    const sid=(req.headers.cookie||'').match(/fixtureSession=([a-f0-9]+)/)?.[1];
    if(url.pathname.endsWith('/logout')) {clients.delete(sid);return send(302,'',{Location:prefix+'/login','Set-Cookie':'fixtureSession=; Max-Age=0; Path='+prefix});}
    if(!clients.has(sid)) return send(302,'',{Location:prefix+'/login'});
    if(url.pathname.endsWith('/desktop/agent-pairing')) {
      if(req.method==='GET') {
        const nonce=crypto.randomBytes(32).toString('hex'),csrf=crypto.randomBytes(20).toString('hex');
        claims.set(sid,{nonce,csrf,id:fixtureCompany});
        return send(200,JSON.stringify({success:true,company_id:fixtureCompany,company_name:'Fixture '+fixtureCompany,nonce,csrf_token:csrf,agent_enabled:true,credential_digest:sha(key(fixtureCompany))}),{'Content-Type':'application/json'});
      }
      let body='';req.on('data',d=>{body+=d;});req.on('end',()=>{
        const data=JSON.parse(body),claim=claims.get(sid); claims.delete(sid);posts++;
        if(reject||!claim||claim.id!==fixtureCompany||data.nonce!==claim.nonce||data.company_id!==claim.id||req.headers['x-csrf-token']!==claim.csrf) return send(409,'{}',{'Content-Type':'application/json'});
        return send(200,JSON.stringify({success:true,company_id:fixtureCompany,api_key:key(fixtureCompany),server_url:origin+'/api/agent'}),{'Content-Type':'application/json'});
      });return;
    }
    send(200,'<html><body>Fictional authenticated invoice page</body></html>',{'Content-Type':'text/html'});
  });
  await new Promise(r=>server.listen(0,'127.0.0.1',r));origin='http://127.0.0.1:'+server.address().port;
  config={serverUrl:origin+'/api/agent',apiKey:'fixture-old',companyId:100,pcName:'Fictional Counter',receiptPrinter:'Fictional Kitchen',silentPrint:false};
  handle('get-config',()=>config);handle('get-status',status);handle('get-version',()=>({version:'fixture'}));
  handle('get-printers',()=>({printers:[{name:'Fictional Kitchen'}]}));
  handle('test-connection',()=>{calls.test++;return {ok:true,data:{company:{id:100,name:'Fixture 100',pra_pos_id:'TEST'}}};});
  handle('save-config',(_e,c)=>{calls.save++;config={...config,...c};return{ok:true};});
  handle('toggle-agent',(_e,on)=>{calls.toggle++;running=on;return{ok:true};});
  handle('get-pos-settings',()=>({}));handle('get-lan-settings',()=>({}));handle('get-lan-status',()=>({enabled:false}));
  handle('lan-list-devices',()=>({devices:[]}));handle('lan-core-list-devices',()=>({devices:[]}));
  handle('check-update',()=>({available:false}));handle('check-ims-service',()=>({}));
  handle('open-pos-window',()=>{calls.open++;return{ok:true};});
  settings=watch(new BrowserWindow({width:900,height:900,webPreferences:{contextIsolation:true,nodeIntegration:false,sandbox:true,preload:path.join(root,'preload.js')}}));
  await settings.loadFile(path.join(root,'index.html'));
  await wait(()=>settings.webContents.executeJavaScript("!!window.agentAPI && document.getElementById('apiKey').value==='fixture-old'"),'real sandbox preload bridge');
  assert.equal(settings.webContents.getLastWebPreferences().sandbox,true);
  await click(settings,'#testBtn');await wait(()=>calls.test===1,'actual Test Connection click');
  await wait(()=>settings.webContents.executeJavaScript("document.getElementById('testResult').textContent.includes('Connected to Fixture 100')"),'visible connection result');
  await click(settings,'#saveBtn');await wait(()=>calls.save===1,'actual Save & Connect click');assert.equal(config.silentPrint,false);assert.equal(config.receiptPrinter,'Fictional Kitchen');assert.equal(config.printerExplicit,false);
  await click(settings,'#toggleBtn');await wait(()=>calls.toggle===1,'actual Start click');assert.equal(running,true);
  await click(settings,'#openPosBtn');await wait(()=>calls.open===1,'actual Open POS click');
  let win=await login('pos'); assert.equal(lastResult,true);assert.equal(config.companyId,101);assert.equal(posts,1);assert.equal(saves,1);
  assert.equal(config.pcName,'Fictional Counter');assert.equal(config.receiptPrinter,'Fictional Kitchen');assert.equal(config.silentPrint,false);
  let count=nativeDialogs;await logout(win,'pos');lastResult=undefined;await click(win,'#fixtureLogin');await wait(()=>lastResult!==undefined,'same-company login');assert.equal(lastResult,true);assert.equal(nativeDialogs,count);assert.equal(saves,1);assert.equal(posts,1);
  await logout(win,'pos');fixtureCompany=202;mode='Cancel';lastResult=undefined;await click(win,'#fixtureLogin');await wait(()=>lastResult!==undefined,'cancel actual company switch');assert.equal(config.companyId,101);assert.equal(posts,1);assert.equal(saves,1);
  await logout(win,'pos');reject=true;mode='Connect';lastResult=undefined;await click(win,'#fixtureLogin');await wait(()=>lastResult!==undefined,'revoked fixture response');assert.equal(lastResult,false);assert.equal(config.companyId,101);assert.equal(saves,1);assert.equal(posts,2);reject=false;
  windows.closePosWindow();fixtureCompany=303;win=await login('fbrpos');assert.equal(lastResult,true);assert.equal(config.companyId,303);assert.equal(posts,3);assert.equal(saves,2);assert.equal(config.receiptPrinter,'Fictional Kitchen');assert.equal(config.silentPrint,false);
  assert.equal(errors.length,0,errors.join('\n'));
  const evidence=path.resolve('.local/browser-evidence/agent-native');fs.mkdirSync(evidence,{recursive:true});
  // Capture only the public fictional page; never settings/API-key fields.
  fs.writeFileSync(path.join(evidence,'fbr-paired.png'),(await win.webContents.capturePage()).toPNG());
  console.log('PASS: actual packaged sandbox preload, real renderer clicks, native Windows Connect/Cancel, real PRA/FBR BrowserWindows/session fetch, same-company re-login, rejected exchange and preference preservation. No production Agent, registry or print.');
})().then(()=>finish(0)).catch(e=>{console.error(e.stack||e);finish(1);});
function finish(code) {
  for(const win of BrowserWindow.getAllWindows()) win.destroy();
  if(server) server.close();
  // Chromium may still hold its disposable profile until app exit; OS runner
  // teardown removes it. Never recursively delete a live user's profile.
  app.exit(code);
}
setTimeout(()=>{console.error('FAIL: native acceptance deadline');finish(1);},150000).unref();
