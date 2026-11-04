'use strict';
const test=require('node:test');
const assert=require('node:assert/strict');
const soap=require('../src/ims-soap');
const {discover}=require('../src/ims-discovery');
test('SOAP request follows XSD order, namespaces, nils, items and escapes names',async()=>{
  let sent;
  const axios={post:async(...args)=>{sent=args;return {data:'<s:Envelope><s:Body><GetFBRInvoiceNumberByModelResponse><GetFBRInvoiceNumberByModelResult>{&quot;Code&quot;:&quot;100&quot;,&quot;InvoiceNumber&quot;:&quot;FBR123456789&quot;}</GetFBRInvoiceNumberByModelResult></GetFBRInvoiceNumberByModelResponse></s:Body></s:Envelope>'};}};
  const r=await soap.submit(axios,'http://127.0.0.1:8524/TcpService/IMSHttp',{DateTime:'2026-11-04 13:14:15',USIN:'T-1',POSID:123,BuyerName:'A & <B>',BuyerCNIC:null,Items:[{TotalAmount:10,ItemName:'Food',Quantity:1,ItemCode:'A'}]});
  assert.equal(r.data.Code,'100');
  assert.match(sent[1],/<a:DateTime>2026-11-04T13:14:15<\/a:DateTime>/);
  assert.match(sent[1],/BuyerName>A &amp; &lt;B&gt;/);
  assert.match(sent[1],/BuyerCNIC i:nil="true"/);
  assert.ok(sent[1].indexOf('<a:Items>')<sent[1].indexOf('<a:POSID>'));
  assert.ok(sent[1].indexOf('<a:ItemCode>')<sent[1].indexOf('<a:Quantity>'));
  assert.equal(sent[2].headers.SOAPAction,'"'+soap.ACTION+'"');
  assert.equal(sent[2].maxRedirects,0);
  assert.equal(sent[2].headers.Authorization,undefined);
});
test('faults, missing/duplicate results, DTDs and non JSON results stay unconfirmed',()=>{
  for(const body of ['<Fault/>','<!DOCTYPE x><GetFBRInvoiceNumberByModelResult>{}</GetFBRInvoiceNumberByModelResult>','<root/>','<GetFBRInvoiceNumberByModelResult>100</GetFBRInvoiceNumberByModelResult>','<GetFBRInvoiceNumberByModelResult>FBR1234</GetFBRInvoiceNumberByModelResult>']) assert.throws(()=>soap.decode(body));
  assert.equal(soap.endpoint('http://127.0.0.1:8524/TcpService/IMSHttp'),true);
  for(const url of ['http://evil.example:8524/TcpService/IMSHttp','http://localhost:8524/TcpService/IMSHttp?x=1','http://u:p@localhost:8524/TcpService/IMSHttp']) assert.equal(soap.endpoint(url),false);
});
test('discovery reads WSDL only, finds nonstandard Windows listener, ignores generic HTTP',async()=>{
  const calls=[];
  const axios={get:async(url,options)=>{calls.push([url,options]);return {data:url.includes(':9001/')?'<wsdl ITcpService="x">GetFBRInvoiceNumberByModel soap/http</wsdl>':'<html>running</html>'};}};
  const r=await discover(axios,{platform:'win32',run:async()=>({stdout:'HTTP://+:9001/TCPSERVICE/\nHTTP://evil:9002/\n'})});
  assert.deepEqual(r,[{protocol:'soap',url:'http://127.0.0.1:9001/TcpService/IMSHttp'}]);
  assert.ok(calls.every(([url,opts])=>/\/TcpService(?:\/IMSHttp)?\?wsdl$/.test(url) && opts.maxRedirects===0));
});
