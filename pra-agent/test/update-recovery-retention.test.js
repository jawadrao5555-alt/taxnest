'use strict';
const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const os=require('node:os');
const path=require('node:path');
const {pruneCompletedRecovery}=require('../src/update-recovery-retention');
function fixture() {
  const base=fs.mkdtempSync(path.join(os.tmpdir(),'taxnest-retention-'));
  const root=path.join(base,'update-recovery','a'.repeat(20));fs.mkdirSync(root,{recursive:true});
  const add=(id,status)=>{
    const folder=path.join(root,String(id));fs.mkdirSync(folder);fs.writeFileSync(path.join(folder,'apply-update.result'),status);
    fs.writeFileSync(path.join(folder,'backup-proof'),'rollback copy');return folder;
  };
  return {base,root,add,clean:()=>fs.rmSync(base,{recursive:true,force:true,maxRetries:10,retryDelay:50})};
}
test('real retention keeps two recent completions, the referenced copy and all uncertain attempts; foreign install untouched',()=>{
  const f=fixture();try {
    for(let i=1;i<=4;i++)f.add(1700000000000+i,'installed');
    f.add(1700000000005,'copying');f.add(1700000000006,'failed_restore');f.add(1700000000007,'unrecognized');
    const foreign=path.join(f.base,'update-recovery','b'.repeat(20));fs.mkdirSync(foreign);fs.writeFileSync(path.join(foreign,'keep'),'other installation');
    const r=pruneCompletedRecovery(f.root,path.join(f.root,'1700000000001','repair-update.cmd'));
    assert.deepEqual(r.removed,['1700000000002']);
    for(const id of [1,3,4,5,6,7])assert.equal(fs.readFileSync(path.join(f.root,String(1700000000000+id),'backup-proof'),'utf8'),'rollback copy');
    assert.equal(fs.readFileSync(path.join(foreign,'keep'),'utf8'),'other installation');
    assert.deepEqual(pruneCompletedRecovery(f.root,path.join(f.root,'1700000000001','repair-update.cmd')).removed,[]);
  } finally {f.clean();}
});
test('the only rollback copy survives and a foreign journal prevents pruning',()=>{
  const f=fixture();try {
    f.add(1700000000001,'recovered_old_version');assert.deepEqual(pruneCompletedRecovery(f.root).removed,[]);
    for(let i=2;i<=5;i++)f.add(1700000000000+i,'installed');
    fs.mkdirSync(path.join(f.base,'foreign'));
    const r=pruneCompletedRecovery(f.root,path.join(f.base,'foreign','repair-update.cmd'));
    assert.equal(r.reason,'foreign-journal');assert.equal(fs.readdirSync(f.root).length,5);
    assert.throws(()=>pruneCompletedRecovery(f.base),/dedicated/);
  } finally {f.clean();}
});
test('symlink-bearing attempts and links outside recovery are never traversed or deleted',()=>{
  const f=fixture();try {
    const old=f.add(1700000000001,'installed');
    for(let i=2;i<=4;i++)f.add(1700000000000+i,'installed');
    const outside=path.join(f.base,'outside');fs.mkdirSync(outside);fs.writeFileSync(path.join(outside,'keep'),'outside');
    fs.symlinkSync(outside,path.join(old,'linked'),'junction');
    assert.deepEqual(pruneCompletedRecovery(f.root).removed,['1700000000002']);
    assert.equal(fs.readFileSync(path.join(outside,'keep'),'utf8'),'outside');
    assert.equal(fs.existsSync(old),true);
  } finally {f.clean();}
});
