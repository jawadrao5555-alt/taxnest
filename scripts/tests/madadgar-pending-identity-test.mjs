import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const template=fs.readFileSync(new URL('../../resources/views/components/madadgar-support.blade.php',import.meta.url),'utf8');
const code=template.slice(template.indexOf('function tnMadadgar('),template.lastIndexOf('</script>')).replace(/@js\([^\n]*?\)\)/g,'"Synthetic label"');
const store=()=>{const data=new Map();return {getItem:k=>data.get(k)??null,setItem:(k,v)=>data.set(k,String(v)),removeItem:k=>data.delete(k)};};
const ctx={localStorage:store(),sessionStorage:store(),window:{crypto:{randomUUID:()=> '11111111-1111-4111-8111-111111111111'}},crypto:{randomUUID:()=> '11111111-1111-4111-8111-111111111111'},fetch:()=>{throw Error('Recovery must never auto send');}};
vm.createContext(ctx);vm.runInContext(code,ctx);
const first=ctx.tnMadadgar(true,'','/pos/madadgar','tenant-1-user-2');first.init();
first.pending={request_id:'22222222-2222-4222-8222-222222222222',title:'Synthetic',summary:'Synthetic',kind:'problem'};first.savePending();first.busy=true;
const identity=JSON.stringify({sid:first.sid,pending:first.pending});
first.newChat(); // Direct method call must also be protected, not only disabled HTML.
assert.equal(JSON.stringify({sid:first.sid,pending:first.pending}),identity);
assert.match(template,/<button @click="newChat\(\)" :disabled="busy"/);
const reload=ctx.tnMadadgar(true,'','/pos/madadgar','tenant-1-user-2');reload.init();
assert.equal(JSON.stringify({sid:reload.sid,pending:reload.pending}),identity);
const foreign=ctx.tnMadadgar(true,'','/pos/madadgar','tenant-3-user-4');foreign.init();assert.equal(foreign.pending,null);
console.log('PASS: busy New Chat UI/method guard, exact refresh identity, foreign-user storage isolation, no automatic send');

// Execute the real confirmation path with a saved acknowledgment loaded from history.
reload.messages=[{role:'assistant',content:'Saved confirmation',escalated:true,ref:42},
    {role:'assistant',content:'Saved confirmation',escalated:false,ref:null}];
reload.headers=()=>({});reload.scrollDown=()=>{};
ctx.fetch=async()=>({ok:true,json:async()=>({reply:'Saved confirmation',ref:42})});
reload.confirmEscalation();
await new Promise(resolve=>setImmediate(resolve));
assert.equal(reload.busy,false);
assert.equal(reload.pending,null);
assert.equal(reload.messages.length,2);
assert.equal(reload.messages.filter(m=>m.escalated&&m.ref===42).length,1);
reload.appendEscalationConfirmation({reply:'Saved confirmation',ref:43});
assert.equal(reload.messages.length,3); // Identical wording with another identity is distinct.
assert.equal(reload.messages[2].ref,43);
console.log('PASS: real confirmation after history recovery shows one ref; distinct refs and non-escalation messages retained');
