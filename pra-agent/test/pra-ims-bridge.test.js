'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('fs');const os=require('os');const path=require('path');
const {createBridge,localEndpoint,resultOnly}=require('../src/pra-ims-bridge');
const config={serverUrl:'http://127.0.0.1/api/agent',apiKey:'synthetic-agent-key',deviceUid:'synthetic-device'};
const invoice={id:17,claim:'a'.repeat(64),endpoint:'http://127.0.0.1:8525/api/IMSFiscal/GetInvoiceNumberByModel',payload:{POSID:12345,Items:[]}};
test('alongside IMS dispatch is one-shot; callback retries are scoped and survive restart',async()=>{
 const directory=fs.mkdtempSync(path.join(os.tmpdir(),'ims-bridge-'));let fiscal=0,claims=0,callbacks=0;let fail=true;
 const axios={post:async(url,body,options)=>{
  assert.equal(options.maxRedirects,0);
  if(url.endsWith('/claim')){claims++;return {data:{invoice:claims===1?invoice:null}};}
  if(url===invoice.endpoint){fiscal++;assert.equal(options.headers.Authorization,undefined);return {data:{Code:'100',InvoiceNumber:'TEST-IMS-123456',secret:'never persist'}};}
  assert(url.endsWith('/result'));callbacks++;assert.equal(body.id,17);assert.equal(body.response.secret,undefined);if(fail)throw Error('synthetic callback outage');return{data:{ok:true}};
 }};
 try{
  await createBridge({axios,directory})(config);assert.equal(fiscal,1);assert.equal(fs.readdirSync(directory).length,1);
  const stored=fs.readFileSync(path.join(directory,fs.readdirSync(directory)[0]),'utf8');assert(!stored.includes(config.apiKey));assert(!stored.includes('never persist'));
  // Another company/key cannot replay this callback.
  await createBridge({axios,directory})({...config,apiKey:'another-synthetic-key'});assert.equal(callbacks,1);
  fail=false;await createBridge({axios,directory})(config);assert.equal(fiscal,1);assert.equal(callbacks,2);assert.equal(fs.readdirSync(directory).length,0);
 }finally{fs.rmSync(directory,{recursive:true,force:true});}
});
test('timeout never repeats fiscal POST, and invalid/local redirect targets never get the invoice',async()=>{
 for(const endpoint of [invoice.endpoint,'https://untrusted.invalid/steal','http://127.0.0.1:8525/wrong','http://localhost:99999/api/IMSFiscal/GetInvoiceNumberByModel']){
  const directory=fs.mkdtempSync(path.join(os.tmpdir(),'ims-bridge-'));let claims=0,fiscal=0;const callbacks=[];
  const axios={post:async(url,body)=>{if(url.endsWith('/claim'))return{data:{invoice:++claims===1?{...invoice,endpoint}:null}};if(url.endsWith('/result')){callbacks.push(body);return{data:{ok:true}};}fiscal++;throw Error('timeout');}};
  try{const sync=createBridge({axios,directory});await sync(config);await sync(config);assert.equal(fiscal,endpoint===invoice.endpoint?1:0);assert.equal(callbacks.length,1);assert.equal(callbacks[0].response,null);}finally{fs.rmSync(directory,{recursive:true,force:true});}
 }
});
test('response reference conflicts survive sanitization; endpoints allow only exact local IMS model route',()=>{
 assert(localEndpoint(invoice.endpoint));assert(!localEndpoint('http://localhost:8525/api/IMSFiscal/GetInvoiceNumberByModel?redirect=1'));
 assert.deepEqual(resultOnly({Code:100,InvoiceNumber:'TEST-111111',FBRInvoiceNumber:'TEST-222222',Token:'secret'}),{Code:'100',InvoiceNumber:'TEST-111111',FBRInvoiceNumber:'TEST-222222'});
});

test('SOAP string-wrapped acknowledgement reaches callback once; diagnostic does not leak raw response',async()=>{
 const directory=fs.mkdtempSync(path.join(os.tmpdir(),'ims-ack-'));let posts=0,claims=0;const callbacks=[];
 const soapInvoice={...invoice,endpoint:'http://127.0.0.1:8524/TcpService/IMSHttp'};
 const result={Code:100,InvoiceNumber:'123456TEST12345678',Token:'secret-must-not-persist'};
 const axios={post:async(url,body)=>{
  if(url.endsWith('/claim')) return {data:{invoice:++claims===1?soapInvoice:null}};
  if(url.endsWith('/result')) {callbacks.push(body);return {data:{ok:true}};}
  posts++;return {data:'<GetFBRInvoiceNumberByModelResult>'+JSON.stringify(JSON.stringify(result))+'</GetFBRInvoiceNumberByModelResult>'};
 }};
 try {const sync=createBridge({axios,directory});await sync(config);await sync(config);
  assert.equal(posts,1);assert.equal(callbacks.length,1);
  assert.equal(callbacks[0].response.InvoiceNumber,result.InvoiceNumber);
  assert.equal(callbacks[0].diagnostic,'response_received');assert(!JSON.stringify(callbacks).includes(result.Token));
 } finally {fs.rmSync(directory,{recursive:true,force:true});}
});
