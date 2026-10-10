import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {randomUUID} from 'node:crypto';
import {readFileSync} from 'node:fs';

// Execute the shipped dialog controller; only DOM and HTTP transport are doubles.
function desk(fetcher, {storage = new Map(), scope = 'hotel-confirm-v1:1:1:1', startup = false, token = 'synthetic-csrf', recovery = async()=>response({state:'not_found'})} = {}) {
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
    dialog.dataset = {recoveryKey:scope, recoveryUrl:'/recovery'};
    const form=node('form'); form.dataset={previewUrl:'/preview',confirmUrl:'/confirm',quoteUrl:'/quote'};
    const document={getElementById:()=>dialog,createElement:()=>node('created-'+nodes.size),
        querySelector:s=>s.includes('data-auto-open')?null:(s.includes('data-hotel-recovery-form')&&!startup?null:form),
        querySelectorAll:()=>[open],addEventListener(){}};
    node('[data-desk-flow]').value='collect'; node('[data-desk-method]').value='cash';
    dialog.dataset.dashboardUrl='/pos/hotel';
    const redirects=[];
    const sandbox={document,window:{localStorage:{getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)},hotelBillPreviewLabels:new Proxy({}, {get:(_,key)=>String(key)}), location:{assign:url=>redirects.push(url)}},
        fetch:(url,options)=>String(url).startsWith('/recovery')?recovery(url,options):fetcher(url,options),URLSearchParams,FormData:class { *[Symbol.iterator](){yield ['_token',token];}},
        crypto:{randomUUID},setTimeout:()=>1,clearTimeout(){},console};
    vm.runInNewContext(readFileSync(new URL('../../public/js/hotel-bill-preview.js',import.meta.url),'utf8'),sandbox);
    return {dialog,open,redirects,el:k=>node('[data-preview-'+k+']'),field:k=>node('[data-desk-'+k+']')};
}
const quote={preview_token:'token',lines:[],balance:100,total:100,collect_now:100,remaining:0,will_issue:true,allow_balance:false};
const response=data=>({ok:true,status:200,json:async()=>data});
const tick=()=>new Promise(resolve=>setImmediate(resolve));
test('reload reconciles a committed nonzero payment with GET only and keeps the original receipt',async()=>{
    const storage=new Map(), posts=[], saved={success:true,status:'local',bill_id:7,invoice_number:'ORIGINAL',receipt_url:'/original',stay_status:'checked_in',stay_url:'/stay'};
    const transport=async(url,options)=>{
        if(url!=='/confirm')return response(quote);
        posts.push(JSON.parse(options.body));throw Error('Lost response after actual operation');
    };
    let ui=desk(transport,{storage});
    await ui.open.click();ui.field('amount').value='25';await ui.field('update').click();await ui.el('confirm').click();
    const record=[...storage.values()][0];
    assert.equal(JSON.parse(record).payload._token,undefined,'CSRF must not be persisted');
    let lookups=0;
    ui=desk(transport,{storage,startup:true,recovery:async url=>{
        ++lookups;assert.match(String(url),new RegExp(posts[0].idempotency_key));
        assert.doesNotMatch(String(url),/preview_token|_token/);
        return response({state:'confirmed',result:saved});
    }});
    await tick();
    assert.equal(lookups,1);assert.equal(posts.length,1);
    assert.equal(ui.el('receipt').href,'/original');assert.equal(ui.el('confirm').hidden,true);
    assert.equal(storage.size,0);assert.equal(ui.dialog.open,true);
});
test('reload before commit permits only explicit original-payload retry using the current CSRF',async()=>{
    const storage=new Map(), posts=[];
    const transport=async(url,options)=>{
        if(url!=='/confirm')return response(quote);
        posts.push(JSON.parse(options.body));
        if(posts.length===1)throw Error('Connection cut before commit');
        return response({success:true,status:'no_bill',stay_status:'checked_in',stay_url:'/stay'});
    };
    let ui=desk(transport,{storage});
    await ui.open.click();ui.field('amount').value='25';await ui.field('update').click();await ui.el('confirm').click();
    ui=desk(transport,{storage,startup:true,token:'renewed-csrf'});await tick();
    assert.equal(posts.length,1);assert.equal(ui.field('amount').disabled,true);
    await ui.el('confirm').click();
    const original={...posts[0],_token:'renewed-csrf'};
    assert.deepEqual(posts[1],original);assert.equal(storage.size,0);
});
test('another tenant or user cannot recover a saved pending payment',async()=>{
    const storage=new Map(), posts=[];
    const transport=async(url,options)=>{
        if(url!=='/confirm')return response(quote);
        posts.push(JSON.parse(options.body));throw Error('Lost response');
    };
    const ui=desk(transport,{storage});await ui.open.click();await ui.el('confirm').click();
    let lookups=0;
    const foreign=desk(transport,{storage,scope:'hotel-confirm-v1:2:8:1',startup:true,recovery:async()=>{++lookups;return response({state:'not_found'});}});
    await tick();assert.equal(lookups,0);assert.equal(posts.length,1);
    await foreign.open.click();assert.equal(foreign.field('amount').disabled,false);assert.equal(storage.size,1);
});
test('blocked storage refuses monetary POST; expired authentication keeps the original pending identity',async()=>{
    const blocked={get(){throw Error('Storage blocked');},set(){throw Error('Storage blocked');},delete(){}};
    let posts=0;
    const ui=desk(async()=>{++posts;return response(quote);},{storage:blocked});
    await ui.open.click();await ui.el('confirm').click();assert.equal(posts,0);
    const storage=new Map();
    const authTransport=async(url)=>url==='/confirm'?{ok:false,status:419,json:async()=>({message:'Expired CSRF'})}:response(quote);
    const first=desk(authTransport,{storage});await first.open.click();await first.el('confirm').click();
    assert.equal(storage.size,1);assert.equal(first.field('amount').disabled,true);
    const restored=desk(authTransport,{storage,startup:true,recovery:async()=>({ok:false,status:401,json:async()=>({message:'Sign in again'})})});
    await tick();assert.equal(restored.el('confirm').disabled,true);assert.equal(storage.size,1);
});
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
    const sandbox={window:{},fetch:(url,options)=>String(url).startsWith('/recovery')?recovery(url,options):fetcher(url,options),URLSearchParams,console};
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
