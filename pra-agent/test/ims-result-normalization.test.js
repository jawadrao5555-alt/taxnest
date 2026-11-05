'use strict';
const test=require('node:test');const assert=require('node:assert/strict');
const {resultOnly}=require('../src/pra-ims-bridge');
const {parseFiscalResult}=require('../src/fiscal-result');
const ack={Code:'100',InvoiceNumber:'123456TEST12345678'};
test('explicit acknowledgements survive casing, whitespace and bounded response wrappers',()=>{
 for(const data of [ack,{Code:null,code:100,InvoiceNumber:ack.InvoiceNumber,FBRInvoiceNumber:' '},{code:' 100 ',invoice_number:ack.InvoiceNumber},
  {Result:JSON.stringify(ack)},{d:{response:JSON.stringify(ack)}}]) {
  const normalized=resultOnly(data);assert.equal(normalized?.Code,'100');
  assert.deepEqual(parseFiscalResult(normalized),{accepted:true,number:ack.InvoiceNumber});
 }
});
test('conflicting status or reference cannot become an accepted acknowledgement',()=>{
 for(const data of [{Code:100,code:400,InvoiceNumber:ack.InvoiceNumber},
  {Code:100,InvoiceNumber:ack.InvoiceNumber,invoice_number:'123456OTHER123456'},
  {Code:100,InvoiceNumber:ack.InvoiceNumber,result:{Code:400}},
  {Code:100,InvoiceNumber:ack.InvoiceNumber,FBRInvoiceNumber:{}},
  {InvoiceNumber:ack.InvoiceNumber},{Code:100,Response:'Invoice successfully processed'},
  {data:{data:{data:ack}}},{...ack,...Object.fromEntries(Array.from({length:65},(_,i)=>['extra'+i,i]))}]) assert.equal(parseFiscalResult(resultOnly(data)).accepted,false);
});
test('only fiscal acknowledgement fields survive; errors and unrelated objects are unconfirmed',()=>{
 assert.deepEqual(resultOnly({...ack,Token:'secret',BuyerCNIC:'private'}),ack);
 for(const data of [null,[],{Token:'secret'},{Result:'not json'}]) assert.equal(resultOnly(data),null);
});

test('diagnostic shape retains only bounded field names/types, never values',()=>{
 const {describeResponse}=require('../src/ims-result');
 const shape=describeResponse({result:{Code:100,InvoiceNumber:'123456TEST12345678',Token:'secret-value',BuyerCNIC:'private-value'}});
 assert(shape.includes('result.Code:number'));assert(shape.includes('result.InvoiceNumber:string'));
 assert(!JSON.stringify(shape).includes('secret-value'));assert(!JSON.stringify(shape).includes('private-value'));
 assert(describeResponse(Object.fromEntries(Array.from({length:99},(_,i)=>['key'+i,'secret']))).length<=16);
});

test('legacy safe numeric references remain compatible; imprecise numeric IDs stay unknown',()=>{
 assert.equal(parseFiscalResult(resultOnly({Code:100,InvoiceNumber:123456789})).number,'123456789');
 assert.equal(parseFiscalResult(resultOnly({Code:100,InvoiceNumber:9000052011142444901})).accepted,false);
});
