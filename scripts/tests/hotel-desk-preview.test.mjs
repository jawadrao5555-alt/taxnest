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
const config = {url:'/quote', availabilityUrl:'/available', room:'1', arrival:'2026-09-27', departure:'2026-09-28', rate:4500, discountType:'amount', discountValue:500, method:'cash', failure:'Failed', unavailable:'Unavailable', dirtyMessage:'Dirty', walkIn:true};
const response = data => ({ok:true,json:async()=>data});
const availableRooms = {rooms:[{id:'1',rate:4500,label:'Room 1'}]};
const withAvailability = quote => (url, options) => String(url).startsWith('/available?')
    ? Promise.resolve(response(availableRooms)) : quote(url, options);
const tick = () => new Promise(resolve => setImmediate(resolve));
test('old quote response cannot replace a newer rate response', async () => {
    const pending = [];
    const form = setup(withAvailability(() => new Promise(resolve => pending.push(resolve)))).hotelBookingForm(config);
    const first = form.refresh(); await tick(); form.rate = 4000; const second = form.refresh(); await tick();
    pending[1]({ok:true,json:async()=>({total:3500,available:true,dirty:false})}); await second;
    pending[0]({ok:true,json:async()=>({total:4000,available:true,dirty:false})}); await first;
    assert.equal(form.quote.total,3500); assert.equal(form.busy,false);
});
test('unavailable and dirty rooms show actionable validation', async () => {
    const form = setup(withAvailability(async()=>response({available:false,dirty:false}))).hotelBookingForm(config);
    await form.refresh(); assert.equal(form.error,'Unavailable');
    const dirty = setup(withAvailability(async()=>response({available:true,dirty:true}))).hotelBookingForm(config);
    await dirty.refresh(); assert.equal(dirty.error,'Dirty');
});
test('late availability response cannot restore a room cleared by newer dates', async () => {
    const pending = [];
    const form = setup(url => new Promise(resolve => pending.push({url:String(url),resolve}))).hotelBookingForm(config);
    const first = form.refresh();
    form.arrival = '2026-10-10'; form.departure = '2026-10-11';
    const second = form.refresh();
    pending[1].resolve(response({rooms:[{id:'2',rate:5000,label:'Room 2'}]})); await second;
    pending[0].resolve(response(availableRooms)); await first;
    assert.equal(form.room,''); assert.equal(form.rate,''); assert.equal(form.quote,null);
    assert.equal(form.options[0].id,'2'); assert.equal(form.error,'Unavailable'); assert.equal(form.busy,false);
    assert.match(pending[1].url,/check_in_date=2026-10-10/);
    assert.equal(pending.length,2,'unavailable selection must not request a quote');
});
test('failed checkout quote blocks stale payment submission', async () => {
    const form = setup(async()=>({ok:false,json:async()=>({message:'Rate changed'})})).hotelCheckoutForm({url:'/checkout',method:'card',quote:{balance:100},amount:100});
    await form.refresh(); assert.equal(form.error,'Rate changed'); assert.equal(form.busy,false);
});
test('cash to card replaces amount with server balance', async () => {
    const form = setup(async()=>({ok:true,json:async()=>({balance:9555})})).hotelCheckoutForm({url:'/checkout',method:'card',amount:10556});
    await form.refresh(); assert.equal(form.amount,9555);
});
