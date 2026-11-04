const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(process.env.FBR_SETTINGS_VIEW || 'resources/views/company/fbr-settings.blade.php', 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace('var initData = @json($initData);', 'var initData = {};');
function page(responses) {
  const context = {document:{querySelector:()=>({content:'local-test-csrf'})}, setTimeout:()=>{}, fetch:async()=>{const next=responses.shift();if(next instanceof Error) throw next;return next;}};
  vm.createContext(context);vm.runInContext(script,context);
  const p=context.fbrSettingsPage();p.form.fbr_environment='sandbox';return p;
}
const json=(body)=>({ok:true,status:200,redirected:false,headers:{get:()=> 'application/json'},json:async()=>body});
for (const [label, response] of [
  ['HTML onboarding redirect',{ok:true,status:200,redirected:true,headers:{get:()=> 'text/html'},json:async()=>{throw Error('HTML');}}],
  ['network failure',new Error('offline')],
  ['server failure',{ok:false,status:500,redirected:false,headers:{get:()=> 'text/html'}}]
]) test(`successful persistence survives ${label} in connection check`,async()=>{
 const p=page([json({success:true}),response]);await p.saveSettings();
 assert.equal(p.saveSuccess,true);assert.equal(p.saveDone,true);assert.match(p.saveMessage,/saved/i);assert.equal(p.connStatus,'unknown');assert.equal(p.saving,false);
});
test('save refusal remains a failure',async()=>{const p=page([json({success:false,message:'Not allowed'})]);await p.saveSettings();assert.equal(p.saveSuccess,false);assert.equal(p.saveDone,false);assert.equal(p.saveMessage,'Not allowed');});
test('healthy connection remains healthy',async()=>{const p=page([json({success:true}),json({status:'green',message:'healthy'})]);await p.saveSettings();assert.equal(p.saveSuccess,true);assert.equal(p.connStatus,'green');});
