import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
test('fiscal browser fixture cannot overwrite shared administrator or tenant identities',()=>{
 const seed=readFileSync(new URL('../rc-browser-fixture-seed.php',import.meta.url),'utf8');
 const block=seed.split('$pilotFiscalCases=[];')[1].split('$aiUsageCases=[];')[0];
 for(const variable of ['admin','support','owner','c','category','label','width'])assert.doesNotMatch(block,new RegExp('\\$'+variable+'\\b'));
 assert.match(block,/\$fiscalAdmin=AdminUser::create/);
 assert.match(block,/'login'=>\$fiscalAdmin->email/);
 assert.match(seed,/'name'=>'b01-company-account'[^\n]*'login'=>\$admin->email/);
});
