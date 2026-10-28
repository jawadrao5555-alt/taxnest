'use strict';
// Actual packaged POS download handler + native Windows Save As. Fictional loopback data only.
const {app, BrowserWindow, session} = require('electron');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const http = require('node:http');
const {spawn} = require('node:child_process');
const root = path.resolve(process.argv[2]);
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'taxnest-native-download-'));
const downloads = path.join(temp, 'Downloads'), chosen = path.join(temp, 'Chosen');
fs.mkdirSync(downloads); fs.mkdirSync(chosen);
fs.mkdirSync(path.join(temp, 'profile'));
app.setPath('userData', path.join(temp, 'profile'));
app.setPath('downloads', downloads);
let server, win, ask = false;
const drivers = new Set();
const payload = 'Fictional report only\r\nfixture,42\r\n';
const wait = async (fn, label) => {
  const end = Date.now() + 25000;
  while (Date.now() < end) { if (await fn()) return; await new Promise(r => setTimeout(r, 50)); }
  throw Error('Timed out: ' + label);
};
function driver(action, destination) {
  const child = spawn('powershell.exe', ['-NoProfile','-NonInteractive','-File',path.join(__dirname,'native-download-dialog.ps1'),
    '-TargetProcessId',String(process.pid),'-Action',action,'-FixtureRoot',temp,'-Destination',destination], {windowsHide:true});
  drivers.add(child);
  return new Promise((resolve,reject) => {
    let error=''; child.stderr.on('data',d=>{error+=d;});
    child.once('error',reject);
    child.once('exit',code=>{drivers.delete(child);code===0?resolve():reject(Error('Own native Save As driver: '+error));});
  });
}
async function download(action, destination) {
  const ses = win.webContents.session;
  const done = new Promise(resolve => ses.once('will-download', (_event,item) => {
    assert.equal(item.getFilename(),'fictional-report.csv');
    item.once('done',(_e,state)=>resolve({state,path:item.getSavePath()}));
  }));
  // Install rejection handler before opening a native modal dialog.
  const driven = action ? driver(action,destination) : Promise.resolve();
  const result = Promise.all([done,driven]);
  win.webContents.downloadURL(server.origin+'/fixture.csv');
  return (await result)[0];
}
(async()=>{
  assert.equal(process.platform,'win32','No skipped native acceptance');
  await app.whenReady();
  const ses = session.fromPartition('persist:pos');
  ses.webRequest.onBeforeRequest((details,callback)=>{
    const u = new URL(details.url);
    callback({cancel:!['file:','data:','devtools:'].includes(u.protocol) && !(u.protocol==='http:' && u.hostname==='127.0.0.1')});
  });
  server = http.createServer((req,res)=>{
    if(req.url==='/fixture.csv') {res.writeHead(200,{'Content-Type':'text/csv','Content-Disposition':'attachment; filename="fictional-report.csv"'});return res.end(payload);}
    if(req.url==='/pos/invoice/create') {res.writeHead(200,{'Content-Type':'text/html'});return res.end('<html><body>Fictional native download verification</body></html>');}
    res.writeHead(404);res.end();
  });
  await new Promise(r=>server.listen(0,'127.0.0.1',r));
  server.origin = 'http://127.0.0.1:'+server.address().port;
  const windows = require(path.join(root,'src/pos-window'));
  win = windows.openPosWindow({serverUrl:server.origin+'/api/agent'}, {shouldAskDownloadLocation:()=>ask,isQuitting:()=>true});
  await wait(()=>win.webContents.getURL()===server.origin+'/pos/invoice/create' && !win.webContents.isLoading(),'packaged POS window');
  // Real OS focus and keep-alive, without external browser or production origin.
  win.hide();assert.equal(win.isVisible(),false);
  assert.equal(windows.openPosWindow({serverUrl:server.origin+'/api/agent'}),win);
  await wait(()=>win.isVisible() && win.isFocused(),'existing POS foreground');
  const sentinel = path.join(downloads,'fictional-report.csv');fs.writeFileSync(sentinel,'existing report');
  let result = await download();
  assert.equal(result.state,'completed');assert.equal(result.path,path.join(downloads,'fictional-report (1).csv'));
  assert.equal(fs.readFileSync(result.path,'utf8'),payload);assert.equal(fs.readFileSync(sentinel,'utf8'),'existing report');
  ask=true;
  const target = path.join(chosen,'renamed-native-report.csv');
  result = await download('Save',target);
  assert.equal(result.state,'completed');assert.equal(result.path,target);assert.equal(fs.readFileSync(target,'utf8'),payload);
  const before = fs.readdirSync(downloads).sort();
  const cancelled = path.join(chosen,'cancelled.csv');
  result = await download('Cancel',cancelled);
  assert.equal(result.state,'cancelled');assert.equal(fs.existsSync(cancelled),false);
  assert.deepEqual(fs.readdirSync(downloads).sort(),before,'Cancel must not silently save to Downloads');
  ask=false;result=await download();
  assert.equal(result.state,'completed');assert.equal(result.path,path.join(downloads,'fictional-report (2).csv'));
  assert.equal(fs.readFileSync(result.path,'utf8'),payload);
  const evidence=path.resolve('.local/browser-evidence/agent-native');fs.mkdirSync(evidence,{recursive:true});
  fs.writeFileSync(path.join(evidence,'native-download-results.json'),JSON.stringify({packagedRoot:path.basename(root),silentCollision:true,nativeSaveAs:true,nativeCancel:true,dynamicOptOut:true,foregroundKeepAlive:true,fictionalLoopbackOnly:true},null,2));
  console.log('PASS: actual packaged POS foreground/keep-alive, collision-safe download, native Save As chosen path, native Cancel without fallback, dynamic OFF. No installed Agent, registry, browser handoff, print or production.');
})().then(()=>finish(0)).catch(e=>{console.error(e.stack||e);finish(1);});
function finish(code) {
  for(const child of drivers)child.kill();
  for(const w of BrowserWindow.getAllWindows())w.destroy();
  if(server)server.close();
  app.exit(code);
}
setTimeout(()=>{console.error('FAIL: native download acceptance deadline');finish(1);},150000).unref();
