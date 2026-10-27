import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source=fs.readFileSync(new URL('../../resources/views/pos/universal.blade.php',import.meta.url),'utf8');
const start=source.indexOf('        async loadReprintBills()');
const method=source.slice(start,source.indexOf('        filteredReprintBills()',start)).replace(/\{\{[\s\S]*?\}\}/g,'/pos/api/todays-bills');
function fixture(){const requests=[];const ui=vm.runInNewContext('({'+method+'})',{fetch:(url)=>new Promise(resolve=>requests.push({url,resolve}))});Object.assign(ui,{reprintBills:[{id:99}],reprintDate:'',reprintPreviewBill:{id:99},_reprintLoadVersion:0});return{ui,requests,reply:(i,date,id)=>requests[i].resolve({ok:true,json:async()=>({success:true,business_date:date,bills:[{id}]})})};}
test('default remains server business day and selecting a date loads only that date',async()=>{const f=fixture();let p=f.ui.loadReprintBills();assert.equal(f.requests[0].url,'/pos/api/todays-bills');assert.equal(f.ui.reprintBills.length,0);assert.equal(f.ui.reprintPreviewBill,null);f.reply(0,'2026-10-20',1);await p;assert.equal(f.ui.reprintDate,'2026-10-20');f.ui.reprintDate='2024-01-02';p=f.ui.loadReprintBills();assert.equal(f.requests[1].url,'/pos/api/todays-bills?date=2024-01-02');f.reply(1,'2024-01-02',2);await p;assert.equal(f.ui.reprintBills[0].id,2);});
test('slower previous date response cannot overwrite latest selected date',async()=>{const f=fixture();f.ui.reprintDate='2024-01-02';const a=f.ui.loadReprintBills();f.ui.reprintDate='2024-01-03';const b=f.ui.loadReprintBills();f.reply(1,'2024-01-03',3);await b;f.reply(0,'2024-01-02',2);await a;assert.equal(f.ui.reprintDate,'2024-01-03');assert.equal(f.ui.reprintBills[0].id,3);assert.equal(f.ui.reprintLoading,false);});
test('failed list never leaves printable bills from a different date',async()=>{const f=fixture();const p=f.ui.loadReprintBills();f.requests[0].resolve({ok:false});await p;assert.equal(f.ui.reprintLoadError,true);assert.equal(f.ui.reprintBills.length,0);assert.equal(f.ui.reprintLoading,false);});
