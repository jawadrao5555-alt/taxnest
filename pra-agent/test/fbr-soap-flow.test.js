'use strict';
const test=require('node:test');const assert=require('node:assert/strict');
const fs=require('node:fs');const path=require('node:path');const vm=require('node:vm');
async function run(soap,failure=false) {
 const fiscal=[],callbacks=[];
 const axios={get:async(url)=>({data:soap && url.includes(':8524/') ? '<wsdl>ITcpService GetFBRInvoiceNumberByModel soap/http</wsdl>' : '<html>not metadata</html>'}),post:async(url,body,options)=>{
  if(url.endsWith('/submit-result')) {callbacks.push(body);return{data:{ok:true}};}
  fiscal.push({url,body,options});
  if(failure)throw Object.assign(Error('timeout'),{code:'ECONNABORTED'});
  return{data:soap?'<GetFBRInvoiceNumberByModelResult>{&quot;Code&quot;:100,&quot;InvoiceNumber&quot;:&quot;900000000001&quot;}</GetFBRInvoiceNumberByModelResult>':{Code:100,InvoiceNumber:'900000000001'}};
 }};
 const context={module:{exports:{}},console:{log(){}},setTimeout,clearTimeout,setInterval,clearInterval,URL,
 require(name){if(name==='axios')return axios;if(name==='./printer')return{};if(name.startsWith('./'))return require(path.join(__dirname,'../src',name));return require(name);}};
 vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../src/agent.js'),'utf8')+'\nmodule.exports.lab=async()=>{currentConfig={serverUrl:"http://127.0.0.1/api/agent"};await submitToPra({transaction_id:91,payload:{POSID:123,Items:[]},submission_claim:"fixture"},"http://127.0.0.1:8524/api/IMSFiscal/GetInvoiceNumberByModel","","fiscal_device","sandbox",true);};',context);
 await context.module.exports.lab();return{fiscal,callbacks};
}
test('actual standalone Agent chooses SOAP and retains claim evidence',async()=>{
 const r=await run(true);assert.equal(r.fiscal.length,1);assert.match(r.fiscal[0].url,/TcpService\/IMSHttp$/);assert.equal(typeof r.fiscal[0].body,'string');assert.equal(r.callbacks[0].success,true);assert.equal(r.callbacks[0].submission_claim,'fixture');
});
test('actual standalone Agent preserves previously working JSON transport',async()=>{
 const r=await run(false);assert.equal(r.fiscal.length,1);assert.match(r.fiscal[0].url,/GetInvoiceNumberByModel$/);assert.equal(typeof r.fiscal[0].body,'object');assert.equal(r.callbacks[0].success,true);
});
test('SOAP timeout stays unknown and never causes a second fiscal POST',async()=>{
 const r=await run(true,true);assert.equal(r.fiscal.length,1);assert.equal(r.callbacks[0].outcome_unknown,true);assert.equal(r.callbacks[0].success,false);
});
