import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
const code = readFileSync(new URL('../../public/js/hotel-desk.js', import.meta.url), 'utf8');
function setup(fetch) {
    const context = {window:{}, fetch, URLSearchParams, setTimeout, clearTimeout};
    vm.runInNewContext(code, context);
    return context.window;
}
const config = {url:'/quote', room:'1', arrival:'2026-09-27', departure:'2026-09-28', rate:4500, discountType:'amount', discountValue:500, method:'cash', failure:'Failed', unavailable:'Unavailable', dirtyMessage:'Dirty', walkIn:true};
test('old quote response cannot replace a newer rate response', async () => {
    const pending = [];
    const form = setup(() => new Promise(resolve => pending.push(resolve))).hotelBookingForm(config);
    const first = form.refresh(); form.rate = 4000; const second = form.refresh();
    pending[1]({ok:true,json:async()=>({total:3500,available:true,dirty:false})}); await second;
    pending[0]({ok:true,json:async()=>({total:4000,available:true,dirty:false})}); await first;
    assert.equal(form.quote.total,3500); assert.equal(form.busy,false);
});
test('unavailable and dirty rooms show actionable validation', async () => {
    const form = setup(async()=>({ok:true,json:async()=>({available:false,dirty:false})})).hotelBookingForm(config);
    await form.refresh(); assert.equal(form.error,'Unavailable');
    const dirty = setup(async()=>({ok:true,json:async()=>({available:true,dirty:true})})).hotelBookingForm(config);
    await dirty.refresh(); assert.equal(dirty.error,'Dirty');
});
test('failed checkout quote blocks stale payment submission', async () => {
    const form = setup(async()=>({ok:false,json:async()=>({message:'Rate changed'})})).hotelCheckoutForm({url:'/checkout',method:'card',quote:{balance:100},amount:100});
    await form.refresh(); assert.equal(form.error,'Rate changed'); assert.equal(form.busy,false);
});
test('cash to card replaces amount with server balance', async () => {
    const form = setup(async()=>({ok:true,json:async()=>({balance:9555})})).hotelCheckoutForm({url:'/checkout',method:'card',amount:10556});
    await form.refresh(); assert.equal(form.amount,9555);
});
