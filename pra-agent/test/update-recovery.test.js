'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {buildRecoveryScript, recoveryRegistration, registerRecovery} = require('../src/update-recovery');
const {buildUpdateHandoffScript} = require('../src/update-handoff');
const {failedHandoff, recoveryPending} = require('../src/update-install-result');
const paths = {
  exePath:'C:\\Agent A\\Agent.exe', destDir:'C:\\Agent A', backupDir:'C:\\User Data\\recovery\\backup',
  readyPath:'C:\\User Data\\recovery\\backup.ready', resultPath:'C:\\User Data\\recovery\\result',
  logPath:'C:\\User Data\\recovery\\repair.log', recoveryPath:'C:\\User Data\\recovery\\repair.cmd',
};
const decode = command => Buffer.from(command.split(' ').at(-1),'base64').toString('utf16le');
test('recovery registration is per exact installation, current-user and one-time only',()=>{
  const a=recoveryRegistration(paths.exePath,paths.recoveryPath);
  assert.notEqual(a.name,recoveryRegistration('C:\\Agent B\\Agent.exe',paths.recoveryPath).name);
  assert.equal(a.name,recoveryRegistration(paths.exePath.toUpperCase(),paths.recoveryPath).name);
  const script=decode(a.register);
  assert.match(script,/Registry\]::CurrentUser/);assert.match(script,/RunOnce/);
  assert.match(script,/\/v:off/);assert.ok(script.includes(paths.recoveryPath));
  assert.doesNotMatch(script,/LocalMachine|schtasks|ExecutionPolicy/);
  assert.match(decode(a.unregister),/DeleteValue/);
  assert.throws(()=>recoveryRegistration('bad%PATH%',paths.recoveryPath),/path/);
});
test('recovery refuses drive roots, a different executable and recursive backups',()=>{
  for(const change of [
    {destDir:'C:\\',exePath:'C:\\Agent.exe'},
    {exePath:'C:\\Other\\Agent.exe'},
    {backupDir:'C:\\Agent A\\backup'},
    {backupDir:'C:\\'},
    {recoveryPath:'C:\\Agent A\\repair.cmd'},
  ]) assert.throws(()=>buildRecoveryScript({...paths,...change}),/installation|backup|outside/);
});

test('verified backup and recovery registration precede installation writes',()=>{
  const s=buildUpdateHandoffScript({...paths,srcDir:'C:\\Temp\\new',sourcePid:123});
  const reg=recoveryRegistration(paths.exePath,paths.recoveryPath);
  assert.ok(s.indexOf(reg.register)<s.indexOf(':copyloop'));
  assert.ok(s.indexOf('echo ready>')<s.indexOf(':copyloop'));
  assert.ok(s.indexOf('fc /b "C:\\Agent A\\resources\\app.asar" "C:\\User Data\\recovery\\backup\\resources\\app.asar"')<s.indexOf('echo ready>'));
  assert.ok(s.indexOf('echo installed>')<s.indexOf(reg.unregister));
  assert.match(s,/if errorlevel 1 goto recovery_unavailable/);
  assert.doesNotMatch(s,/taskkill|uninstall/i);
  assert.throws(()=>buildUpdateHandoffScript({...paths,readyPath:undefined}),/paired/);
});
test('interrupted outcomes never claim successful installation',()=>{
  for(const outcome of ['copying','recovered_old_version','failed_recovery_registration','failed_restore']) {
    const result=failedHandoff({target:'1.13.22',resultPath:'fixture'},'1.13.21',(r,l)=>r!==l,()=>outcome);
    assert.equal(result.stage,'install');assert.ok(result.error);assert.doesNotMatch(result.error,/unrecognized/);
  }
});

test('completed marker is compared as exact trimmed file content, including missing final newline',()=>{
  const script=buildRecoveryScript(paths);
  const encoded=script.split('\r\n').find(line=>line.includes('-EncodedCommand')&&decode(line.split(' >>')[0]).includes('ReadAllText'));
  assert.ok(encoded);
  const check=decode(encoded.split(' >>')[0]);
  assert.ok(check.includes(paths.resultPath));assert.match(check,/Trim\(\) -ceq 'installed'/);
  assert.ok(script.indexOf(encoded)<script.indexOf('robocopy'));
});

test('automatic recovery is bounded and retains manual repair after repeated failure',()=>{
  const script=buildRecoveryScript(paths);
  assert.match(script,/retry1/);assert.match(script,/retry2/);
  assert.ok(script.indexOf('retry2')<script.lastIndexOf(recoveryRegistration(paths.exePath,paths.recoveryPath).register));
  assert.doesNotMatch(script,/rmdir|del \/|taskkill/i);
});

test('an uncertain or actively copying handoff blocks a second installer; terminal outcomes do not',()=>{
  const state={recoveryPath:'fixture',resultPath:'result'};
  assert.equal(recoveryPending(state,()=> 'copying'),true);
  assert.equal(recoveryPending(state,()=> 'preparing'),true);
  assert.equal(recoveryPending(state,()=> 'partial-or-unknown'),true);
  assert.equal(recoveryPending(state,()=>{throw Error('missing')}),true);
  for(const result of ['installed','recovered_old_version','failed_copy_or_verify','failed_backup','failed_recovery_registration'])
    assert.equal(recoveryPending(state,()=>result),false);
  assert.equal(recoveryPending({target:'legacy'},()=>{throw Error('must not read')}),false);
});

test('arming recovery is awaited and a Windows policy failure is not ignored',async()=>{
  const calls=[];
  await registerRecovery(paths.exePath,paths.recoveryPath,(program,args,options,callback)=>{
    calls.push({program,args,options});callback(null);
  });
  assert.equal(calls[0].program,'powershell.exe');assert.equal(calls[0].options.windowsHide,true);
  assert.match(decode('encoded '+calls[0].args.at(-1)),/RunOnce/);
  await assert.rejects(registerRecovery(paths.exePath,paths.recoveryPath,(_p,_a,_o,cb)=>cb(Error('policy denied'))),/policy denied/);
});

function makeWindowsFixtureAgent(exePath) {
  // STARTing a .cmd file opens a persistent command shell and locks its CWD.
  // A tiny real Windows executable models the production launch and exits.
  const code='using System; using System.IO; public static class FixtureAgent { public static void Main() { File.WriteAllText(Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "started.probe"), "started"); } }';
  const literal=value=>"'"+value.replace(/'/g,"''")+"'";
  const compile="$ErrorActionPreference='Stop';Add-Type -TypeDefinition "+literal(code)+
    ' -Language CSharp -OutputAssembly '+literal(exePath)+' -OutputType WindowsApplication';
  execFileSync('powershell.exe',['-NoProfile','-NonInteractive','-EncodedCommand',Buffer.from(compile,'utf16le').toString('base64')],{timeout:30000,stdio:'pipe'});
}

// Windows-only disposable filesystem acceptance: no real Agent, installer,
// registry, restart, printer, company config or network. Registry commands are
// injected no-ops; the generated production rollback/copy/launch body is real.
for(const stage of ['before-backup','during-copy','completed','completed-crlf','missing-backup']) {
 test('Windows actual recovery at '+stage,{skip:process.platform!=='win32'},async()=>{
  const root=fs.mkdtempSync(path.join(os.tmpdir(),'taxnest recovery test-'));
  let primaryError;
  try {
    const destDir=path.join(root,'install with spaces'),backupDir=path.join(root,'safe recovery','backup');
    fs.mkdirSync(path.join(destDir,'resources'),{recursive:true});
    fs.mkdirSync(path.join(backupDir,'resources'),{recursive:true});
    const p={destDir,backupDir,exePath:path.join(destDir,'FixtureAgent.exe'),readyPath:path.join(root,'ready'),
      resultPath:path.join(root,'result'),logPath:path.join(root,'log'),recoveryPath:path.join(root,'repair.cmd')};
    const probe=path.join(destDir,'started.probe');
    makeWindowsFixtureAgent(p.exePath);
    fs.copyFileSync(p.exePath,path.join(backupDir,'FixtureAgent.exe'));
    fs.writeFileSync(path.join(backupDir,'resources','app.asar'),'verified old app');
    fs.writeFileSync(path.join(destDir,'resources','app.asar'),stage==='during-copy'?'TRUNCATED':'completed new app');
    if(stage!=='before-backup')fs.writeFileSync(p.readyPath,'ready');
    fs.writeFileSync(p.resultPath,stage==='completed'?'installed':stage==='completed-crlf'?'installed\r\n':'copying');
    if(stage==='missing-backup')fs.rmSync(backupDir,{recursive:true});
    // No registry access in this fixture; verify real registration separately.
    fs.writeFileSync(p.recoveryPath,buildRecoveryScript(p,{registration:{register:'rem register fixture',unregister:'rem unregister fixture'}}));
    let code=0,runError;
    try {execFileSync('cmd.exe',['/d','/v:off','/s','/c',`""${p.recoveryPath}""`],{windowsVerbatimArguments:true,timeout:60000,stdio:'pipe'});}catch(e){code=e.status;runError=e;}
    if(stage==='missing-backup') {
      assert.equal(code,1);assert.equal(fs.readFileSync(p.resultPath,'utf8').trim(),'failed_restore');
      assert.equal(fs.existsSync(probe),false);
    } else {
      assert.equal(code,0,'Recovery command failed: '+String(runError?.stderr||runError?.message||'')+'; log: '+(fs.existsSync(p.logPath)?fs.readFileSync(p.logPath,'utf8'):''));
      assert.equal(fs.readFileSync(p.resultPath,'utf8').trim(),stage==='during-copy'?'recovered_old_version':stage==='before-backup'?'recovered_before_copy':'installed');
      assert.equal(fs.readFileSync(path.join(destDir,'resources','app.asar'),'utf8'),stage==='during-copy'?'verified old app':'completed new app');
      for(let i=0;i<100&&!fs.existsSync(probe);i++)await new Promise(resolve=>setTimeout(resolve,50));
      assert.equal(fs.existsSync(probe),true,'the recovered fictional launcher actually ran');
      assert.equal(fs.readFileSync(path.join(backupDir,'resources','app.asar'),'utf8'),'verified old app');
    }
  } catch(error) { primaryError=error; }
  try { fs.rmSync(root,{recursive:true,force:true,maxRetries:20,retryDelay:50}); }
  catch(cleanupError) {
    if(primaryError)throw new AggregateError([primaryError,cleanupError],'Recovery assertion and fixture cleanup both failed');
    throw cleanupError;
  }
  if(primaryError)throw primaryError;
 });
}

test('real settings banner explains pending recovery instead of claiming automatic installation',()=>{
  const html=fs.readFileSync(path.join(__dirname,'../index.html'),'utf8');
  const source=html.slice(html.indexOf('  function showUpdateBanner(info) {'),html.indexOf("  $('checkUpdateBtn').addEventListener"));
  const nodes={};const $=id=>nodes[id]||(nodes[id]={style:{},textContent:''});
  const render=require('node:vm').runInNewContext('('+source.trim()+')',{$,setTimeout:()=>{}});
  render({available:true,recoveryPending:true,latestBuild:'1.13.23'});
  assert.match(nodes.updateMsg.textContent,/Windows dobara/);
  assert.doesNotMatch(nodes.updateMsg.textContent,/install khud-ba-khud hogi/);
  assert.equal(nodes.updateBtn.style.display,'inline-block');
  render({available:true,downloading:true,progress:42,latestBuild:'1.13.23'});
  assert.equal(nodes.updateBtn.style.display,'none');assert.match(nodes.updateMsg.textContent,/42%/);
});
