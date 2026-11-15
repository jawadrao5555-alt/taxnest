import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const html=readFileSync(new URL('../../resources/views/saas-admin/platform-expenses.blade.php',import.meta.url),'utf8');
const patterns=[...html.matchAll(/pattern="([^"]+)"/g)].map(m=>m[1]);
test('actual expense input patterns compile under browser Unicode sets',()=>{
 assert.equal(patterns.length,2);
 for(const pattern of patterns)assert.doesNotThrow(()=>new RegExp('^(?:'+pattern+')$','v'));
});
test('category slug allows literal hyphen and underscore, not punctuation or arbitrary text',()=>{
 const p=new RegExp('^(?:'+patterns[0]+')$','v');
 for(const valid of ['hosting','synthetic_1366','cloud-hosting','a'])assert.equal(p.test(valid),true);
 for(const invalid of ['Hosting','a b','a/b','a|b','-hosting','a'.repeat(81)])assert.equal(p.test(invalid),false);
});
