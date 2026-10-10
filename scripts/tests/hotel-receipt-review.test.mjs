import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';

// Execute the shipped dialog controller; only DOM and HTTP transport are doubles.
function desk(fetcher) {
    const nodes = new Map();
    function node(key) {
        if (nodes.has(key)) return nodes.get(key);
        const n = {hidden:false, disabled:false, value:'', checked:false, textContent:'', open:false,
            dataset:{}, listeners:{}, children:[],
            addEventListener(type, fn) {(this.listeners[type] ||= []).push(fn);},
            async fire(type) {for (const fn of this.listeners[type] || []) await fn({preventDefault(){}, target:this});},
            async click() {await this.fire('click');await new Promise(resolve=>setImmediate(resolve));},
            replaceChildren() {this.children=[];}, append(...children) {this.children.push(...children);},
            removeAttribute(name) {delete this[name];}, focus(){},
            querySelector(selector) {return node(selector);},
            querySelectorAll() {return ['flow','method','amount','balance','update'].map(k=>node('[data-desk-'+k+']'));},
            showModal() {this.open=true;}, close() {this.open=false;this.fire('close');},
        };
        nodes.set(key,n); return n;
    }
    const dialog=node('dialog'), open=node('open');
    const form=node('form'); form.dataset={previewUrl:'/preview',confirmUrl:'/confirm',quoteUrl:'/quote'};
    const document={getElementById:()=>dialog,createElement:()=>node('created-'+nodes.size),
        querySelector:s=>s.includes('data-auto-open')?null:form,
        querySelectorAll:()=>[open],addEventListener(){}};
    node('[data-desk-flow]').value='collect'; node('[data-desk-method]').value='cash';
    dialog.dataset.dashboardUrl='/pos/hotel';
    const redirects=[];
    let serial=0;
    const sandbox={document,window:{hotelBillPreviewLabels:new Proxy({}, {get:(_,key)=>String(key)}), location:{assign:url=>redirects.push(url)}},
        fetch:fetcher,URLSearchParams,FormData:class { *[Symbol.iterator](){yield ['_token','synthetic-csrf'];}},
        crypto:{randomUUID:()=> 'attempt-'+(++serial)},setTimeout:()=>1,clearTimeout(){},console};
    vm.runInNewContext(readFileSync(new URL('../../public/js/hotel-bill-preview.js',import.meta.url),'utf8'),sandbox);
    return {dialog,open,redirects,el:k=>node('[data-preview-'+k+']'),field:k=>node('[data-desk-'+k+']')};
}
const quote={preview_token:'token',lines:[],balance:100,total:100,collect_now:100,remaining:0,will_issue:true,allow_balance:false};
const response=data=>({ok:true,status:200,json:async()=>data});
test('closing and reopening an unconfirmed receipt retains method, partial payment and action',async()=>{
    const requests=[];
    const ui=desk(async url=>{requests.push(String(url));return response(quote);});
    await ui.open.click();
    ui.field('method').value='card';ui.field('amount').value='25';ui.field('flow').value='checkout';
    await ui.field('update').click();await ui.el('back').click();await ui.open.click();
    assert.equal(ui.field('amount').value,'25');assert.equal(ui.field('method').value,'card');
    assert.equal(ui.field('flow').value,'checkout');
    assert.match(requests.at(-1),/amount=25/);
});
test('lost confirmation response survives close/reopen and retries exactly the same payment UUID',async()=>{
    const posts=[];
    const ui=desk(async(url,options)=>{
        if(url!=='/confirm')return response(quote);
        posts.push(JSON.parse(options.body));
        if(posts.length===1)throw Error('Response lost after server committed');
        return response({success:true,status:'no_bill',stay_status:'checked_in',stay_url:'/stay'});
    });
    await ui.open.click();await ui.el('confirm').click();
    await ui.el('back').click();await ui.open.click();await ui.el('confirm').click();
    assert.equal(posts.length,2);assert.deepEqual(posts[1],posts[0]);
});
test('paid checkout does not show an amount field or request another payment',async()=>{
    const ui=desk(async url=>response(String(url).startsWith('/quote')?{balance:0,total:0}:{...quote,collect_now:0,remaining:0,will_issue:false}));
    await ui.open.click();
    assert.equal(ui.field('flow').value,'checkout');assert.equal(ui.field('amount').value,'0.00');
    assert.equal(ui.field('amount-label').hidden,true);
});
test('closing during an outstanding quote cannot reopen the receipt when its response arrives',async()=>{
    let resolvePreview;
    const ui=desk(async url=>String(url).startsWith('/quote')?response(quote):new Promise(resolve=>resolvePreview=resolve));
    const opening=ui.open.click();await new Promise(resolve=>setImmediate(resolve));
    // The user explicitly dismisses the visible loading dialog while HTTP is pending.
    ui.dialog.close();resolvePreview(response(quote));await opening;
    assert.equal(ui.dialog.open,false);
});
test('a refused or cancelled draft cannot leave its old edit/delete receipt actionable',async()=>{
    let closed=false;
    const ui=desk(async url=>closed?{ok:false,status:409,json:async()=>({message:'Stay cancelled'})}:response(quote));
    await ui.open.click();closed=true;await ui.field('update').click();
    assert.equal(ui.el('confirm').disabled,true);assert.equal(ui.el('draft-receipt').hidden,true);
    assert.equal(ui.el('edit').hidden,true);assert.equal(ui.el('delete').hidden,true);
    assert.match(ui.el('error').textContent,/Stay cancelled/);
});
test('a definite confirmation refusal requires explicit refreshed review before a new attempt',async()=>{
    const posts=[];
    const ui=desk(async(url,options)=>{
        if(url!=='/confirm')return response(quote);
        posts.push(JSON.parse(options.body));
        if(posts.length===1)return {ok:false,status:409,json:async()=>({message:'Quote changed'})};
        return response({success:true,status:'no_bill',stay_status:'checked_in',stay_url:'/stay'});
    });
    await ui.open.click();await ui.el('confirm').click();
    assert.equal(ui.el('confirm').disabled,true);assert.equal(ui.field('amount').disabled,false);
    await ui.field('update').click();await ui.el('confirm').click();
    assert.equal(posts.length,2);assert.notEqual(posts[0].idempotency_key,posts[1].idempotency_key);
});

test('only explicitly closing a successful checkout returns to the Hotel dashboard',async()=>{
    const ui=desk(async url=>response(url==='/confirm'?{success:true,status:'local',stay_status:'checked_out',stay_url:'/stay'}:quote));
    await ui.open.click();ui.field('flow').value='checkout';await ui.field('update').click();await ui.el('confirm').click();
    assert.deepEqual(ui.redirects,[]);assert.equal(ui.dialog.open,true);
    await ui.el('back').click();assert.deepEqual(ui.redirects,['/pos/hotel']);
});
test('closing draft or collection receipts retains the current page',async()=>{
    const ui=desk(async url=>response(url==='/confirm'?{success:true,status:'local',stay_status:'checked_in',stay_url:'/stay'}:quote));
    await ui.open.click();await ui.el('back').click();assert.deepEqual(ui.redirects,[]);
    await ui.open.click();await ui.el('confirm').click();await ui.el('back').click();assert.deepEqual(ui.redirects,[]);
});

function booking(fetcher) {
    const sandbox={window:{},fetch:fetcher,URLSearchParams,console};
    vm.runInNewContext(readFileSync(new URL('../../public/js/hotel-desk.js',import.meta.url),'utf8'),sandbox);
    return sandbox.window.hotelBookingForm({url:'/quote',availabilityUrl:'/available',room:'1',rate:100,rooms:{1:{rate:100}},
        options:[{id:'1',rate:100,label:'Room 1'}],arrival:'2026-10-10',departure:'2026-10-11',walkIn:true,
        discountType:'amount',discountValue:0,method:'cash',failure:'Failed',unavailable:'Unavailable'});
}
test('booking refresh removes unavailable rooms and clears a stale selected room before quoting',async()=>{
    const requests=[];
    const form=booking(async url=>{requests.push(String(url));return response(String(url).startsWith('/available')?{rooms:[{id:'2',rate:200,label:'Room 2'}]}:{available:false});});
    await form.refresh();
    assert.equal(form.room,'');assert.equal(form.rate,'');assert.equal(form.quote,null);assert.equal(form.error,'Unavailable');
    assert.equal(form.options.length,1);assert.equal(form.options[0].id,'2');
    assert.equal(requests.length,1);
});
test('booking availability refresh retains an available selection and quotes with the same walk-in mode',async()=>{
    const requests=[];
    const form=booking(async url=>{requests.push(String(url));return response(String(url).startsWith('/available')?{rooms:[{id:'1',rate:100,label:'Room 1'}]}:{available:true,dirty:false,total:100});});
    await form.refresh();
    assert.equal(form.room,'1');assert.equal(form.rate,100);assert.equal(form.quote.total,100);assert.equal(form.error,'');
    assert.match(requests[0],/check_in_date=2026-10-10/);assert.match(requests[1],/walk_in=1/);
});
test('failed availability lookup cannot leave an old quote confirmable',async()=>{
    const form=booking(async()=>({ok:false,json:async()=>({message:'Unavailable now'})}));
    form.quote={total:100};await form.refresh();assert.equal(form.quote,null);assert.equal(form.error,'Unavailable now');assert.equal(form.busy,false);
});
