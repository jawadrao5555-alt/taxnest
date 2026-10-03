import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../public/js/hotel-settings.js', import.meta.url), 'utf8');
function fixture(fetcher) {
    const applied = [];
    const context = { fetch: fetcher, document: {
        querySelector: () => ({content:'synthetic-csrf'}),
        body: {setAttribute: (...args) => applied.push(args)},
    }};
    vm.createContext(context); vm.runInContext(source, context);
    return { ui: context.TnHotelSettings({theme:'blue', whatsapp:true}, {saving:'Saving', saved:'Saved', failed:'Failed'}), applied };
}
test('failed HTTP, invalid JSON and network saves preserve confirmed values and colour', async () => {
    for (const fetcher of [
        async () => ({ok:false, json:async()=>({success:false,message:'Denied'})}),
        async () => ({ok:true, json:async()=>({success:false})}),
        async () => ({ok:true, json:async()=>{throw Error('Not JSON');}}),
        async () => {throw Error('Offline');},
    ]) {
        const {ui,applied} = fixture(fetcher);
        assert.equal(await ui.save('theme','/pos/settings/theme',{theme:'rose'},'rose'),false);
        assert.equal(ui.values.theme,'blue');
        assert.equal(ui.busy.theme,false);
        assert.ok(ui.error); assert.equal(applied.length,0);
    }
});
test('a confirmed save changes value/colour and uses same-origin CSRF JSON request', async () => {
    let request;
    const {ui,applied} = fixture(async (url, options) => {request={url,options};return {ok:true,json:async()=>({success:true})};});
    assert.equal(await ui.save('theme','/pos/settings/theme',{theme:'rose'},'rose'),true);
    assert.equal(ui.values.theme,'rose'); assert.equal(ui.status,'Saved');
    assert.equal(applied[0][1],'rose');
    assert.equal(request.options.credentials,'same-origin');
    assert.equal(request.options.headers['X-CSRF-TOKEN'],'synthetic-csrf');
    assert.equal(JSON.parse(request.options.body).theme,'rose');
});
test('repeated clicks during save produce one request and leave old value until acknowledgement', async () => {
    let release, calls=0;
    const {ui}=fixture(async()=>{calls++;await new Promise(r=>release=r);return {ok:true,json:async()=>({success:true})};});
    const first=ui.save('whatsapp','/pos/settings/whatsapp-bill-toggle',{enabled:false},false);
    assert.equal(ui.values.whatsapp,true);
    assert.equal(await ui.save('whatsapp','/pos/settings/whatsapp-bill-toggle',{enabled:false},false),false);
    release(); assert.equal(await first,true);
    assert.equal(calls,1); assert.equal(ui.values.whatsapp,false);
});
