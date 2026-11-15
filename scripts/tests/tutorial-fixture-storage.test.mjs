import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const seed=readFileSync(new URL('../rc-browser-fixture-seed.php',import.meta.url),'utf8');
const erp=readFileSync(new URL('../check-erp-demo-browser.sh',import.meta.url),'utf8');
test('synthetic tutorials use runtime storage without dirtying public source or dangling URLs',()=>{
 for(const extension of ['mp4','vtt']){
  assert.ok(seed.includes("storage_path('app/public/rc-browser-tutorials/rc-onboarding-synthetic."+extension+"')"));
  assert.ok(seed.includes("'/storage/rc-browser-tutorials/rc-onboarding-synthetic."+extension+"'"));
  assert.ok(!seed.includes("public_path('videos/rc-onboarding-synthetic."+extension+"')"));
 }
 assert.ok(erp.includes('git status --porcelain --untracked-files=normal'));
 assert.ok(!erp.includes('rc-onboarding-synthetic'));
});
