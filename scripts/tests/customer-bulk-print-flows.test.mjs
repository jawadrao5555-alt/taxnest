import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source = fs.readFileSync(new URL('../../resources/views/pos/universal.blade.php', import.meta.url), 'utf8');
function method(name) {
  const start = source.indexOf('        ' + name + '(');
  assert.ok(start >= 0);
  return source.slice(start, source.indexOf('\n        },', start) + 11);
}
test('Reprint row and both Enter handlers only open preview, which has explicit Print', () => {
  const section = source.slice(source.indexOf('x-show="showReprint"'), source.indexOf('{{-- REPRINT PREVIEW'));
  assert.ok(section.includes('@click="openReprintPreview(bill)"'));
  assert.ok(!section.includes('reprintBill('));
  assert.ok(!source.includes('this.reprintBill(rlist[this.activeReprintIndex])'));
  assert.ok(source.includes('@click="reprintBill(reprintPreviewBill); reprintPreviewBill = null"'));
  const ui = vm.runInNewContext('({' + method('openReprintPreview') + method('receiptViewUrl') + '})');
  let focused = 0;
  Object.assign(ui, {$nextTick: fn=>fn(), $refs:{reprintPreviewDialog:{focus:()=>focused++}}});
  for (const restaurant of [false, true]) {
    ui.isRestaurantMode = restaurant;
    ui.openReprintPreview({id: 91});
    assert.equal(ui.reprintPreviewBill.id, 91);
    assert.ok(!ui.receiptViewUrl(ui.reprintPreviewBill).includes('auto_print'));
  }
  assert.equal(focused,2);
});
test('preview owns checkout shortcuts without blocking native activation of its explicit Print button', () => {
  const ui=vm.runInNewContext('({'+method('reprintPreviewOwnsKey')+'})');
  ui.reprintPreviewBill={id:91};
  for(const listOpen of [false,true]) {
    ui.showReprint=listOpen;
    let prevented=0;
    const event={key:'Enter',target:{closest:()=>null},preventDefault:()=>prevented++,stopPropagation:()=>{}};
    assert.equal(ui.reprintPreviewOwnsKey(event),true);
    assert.equal(prevented,1);
    event.target.closest=()=>({tagName:'BUTTON'});
    assert.equal(ui.reprintPreviewOwnsKey(event),true);
    assert.equal(prevented,1,'focused preview Print keeps native Enter');
    event.key='F1';assert.equal(ui.reprintPreviewOwnsKey(event),true);
  }
  let stopped=false;
  ui.reprintPreviewOwnsKey({key:'Escape',preventDefault:()=>{},stopPropagation:()=>stopped=true});
  assert.equal(stopped,true);assert.equal(ui.reprintPreviewBill,null);
  assert.equal(ui.reprintPreviewOwnsKey({key:'Enter'}),false);
});
for (const methodName of ['boardProofBill', 'panelProofBill']) {
  test(`${methodName} carries fresh cash/card choice through silent and browser printing`, async () => {
    for (const silent of [false, true]) for (const payment of ['cash', 'card', 'cash']) {
      const queued = [], framed = [];
      const ui = vm.runInNewContext('({' + method(methodName) + '})', {encodeURIComponent, window:{TXT:{proof_bill_sent_to_printer:'Queued'}}});
      Object.assign(ui, {boardMenuTable:{order:{id:51}}, panelOrderId:()=>51, silentBillPrint:silent,
        trySilentPrint:async p=>{queued.push(p);return true;}, _printViaIframe:(_id,url)=>framed.push(url), showToast:()=>{}});
      ui[methodName](payment);
      await Promise.resolve();
      if (silent) { assert.equal(queued[0].proof_payment_method, payment); assert.equal(framed.length,0); }
      else { assert.ok(framed[0].endsWith('payment_method='+payment)); assert.equal(queued.length,0); }
    }
  });
}
test('receipt closes after accepted printing only when the receipt/KOT chain has drained', () => {
  const timers = [];
  const ui = vm.runInNewContext('({' + method('closeReceiptAfterAcceptedPrint') + '})', {setTimeout:fn=>timers.push(fn),Date});
  let busy = true, closes = 0;
  Object.assign(ui,{showReceipt:true,lastTransactionId:4,printChainBusy:()=>busy,cancelReceiptAutoClose:()=>{},closeReceiptPopup:()=>closes++});
  ui.closeReceiptAfterAcceptedPrint(4);
  timers.shift()();
  assert.equal(closes,0);
  busy=false;timers.shift()();assert.equal(closes,1);
  ui.closeReceiptAfterAcceptedPrint(4);ui.lastTransactionId=5;timers.shift()();assert.equal(closes,1);
});
test('iframe timeout is not print acceptance and messages must come from the receipt frame', () => {
  const methodSource = method('_printViaIframe');
  const timers = [], listeners = {};
  const frame = {contentWindow:{},style:{}};
  const ui = vm.runInNewContext('({' + methodSource + '})', {
    Date,Math,encodeURIComponent,clearTimeout:()=>{},
    document:{getElementById:()=>frame,activeElement:null},
    window:{location:{origin:'http://localhost'},focus:()=>{},addEventListener:(n,fn)=>listeners[n]=fn,removeEventListener:()=>{}}
  });
  const results=[];
  Object.assign(ui,{printSessionId:1,printMessageHandlers:[],pendingPrintTimers:[],queuePrintTimer:fn=>{timers.push(fn);return timers.length;}});
  ui._printViaIframe('receipt','/receipt?auto_print=1','',r=>results.push(r));
  timers.shift()();assert.equal(results[0].accepted,false);
  ui._printViaIframe('receipt','/receipt?auto_print=1','',r=>results.push(r));
  const signal=new URL('http://localhost'+frame.src).searchParams.get('_signal');
  listeners.message({data:{type:'pos_print_done',signal,reason:'afterprint'},source:{},origin:'http://localhost'});
  assert.equal(timers.length,1);
  listeners.message({data:{type:'pos_print_done',signal,reason:'afterprint'},source:frame.contentWindow,origin:'http://localhost'});
  timers.pop()();assert.equal(results[1].accepted,true);
});
