'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const {imsMode} = require('../src/ims-visibility');
const {discover} = require('../src/ims-discovery');
const http = require('node:http');
const axios = require('axios');

test('PRA cloud login can prepare FBR IMS before reporting is enabled without mutating routing', () => {
  const status = {running:true, connected:true, serverInfo:{product_type:'pos', pra_connection_mode:'cloud', fbr_alongside_pra:false}};
  const before = JSON.stringify(status);
  assert.equal(imsMode(status), 'fbr');
  assert.equal(JSON.stringify(status), before);
});

test('PRA local login retains PRA status and can prepare optional FBR before enabling', () => {
  assert.equal(imsMode({running:true, connected:true, serverInfo:{product_type:'pos', pra_connection_mode:'fiscal_device', fbr_alongside_pra:false}}), 'both');
});

test('discovery finds endpoint-scoped WSDL without sending an invoice or following redirects', async () => {
  const calls=[];
  const client={get:async(url,options)=>{
    calls.push({url,options});
    if(url==='http://127.0.0.1:8524/TcpService/IMSHttp?wsdl') return {data:'<wsdl ITcpService="x">GetFBRInvoiceNumberByModel soap/http</wsdl>'};
    throw Error('metadata not available at this path');
  }};
  assert.deepEqual(await discover(client,{platform:'linux'}), [{protocol:'soap',url:'http://127.0.0.1:8524/TcpService/IMSHttp'}]);
  assert.ok(calls.every(c=>c.url.startsWith('http://127.0.0.1:') && c.url.endsWith('?wsdl') && c.options.maxRedirects===0));
});

test('endpoint-specific Windows registrations are bounded to local supported paths', async () => {
  const calls=[];
  const client={get:async(url)=>{calls.push(url);return {data:url.includes(':9007/')?'<wsdl ITcpService="x">GetFBRInvoiceNumberByModel soap/http</wsdl>':'<html>not IMS</html>'};}};
  assert.deepEqual(await discover(client,{platform:'win32',run:async()=>({stdout:'HTTP://+:9007/TCPSERVICE/IMSHTTP/\nHTTP://evil:9008/\nHTTP://+:9009/UNRELATED/\n'})}), [{protocol:'soap',url:'http://127.0.0.1:9007/TcpService/IMSHttp'}]);
  assert.ok(calls.every(url=>!url.includes(':9008/') && !url.includes(':9009/')));
});

test('generic listeners and unsafe metadata never become FBR services', async () => {
  for (const data of ['<html>running</html>', '<!DOCTYPE x><wsdl ITcpService="x">GetFBRInvoiceNumberByModel soap/http</wsdl>', '<wsdl>GetFBRInvoiceNumberByModel</wsdl>']) {
    assert.deepEqual(await discover({get:async()=>({data})},{platform:'linux'}), []);
  }
});

test('real loopback metadata host is discovered before any reporting profile is enabled', async () => {
  const requests=[];
  const server=http.createServer((req,res)=>{
    requests.push([req.method,req.url]);
    if(req.url!=='/TcpService/IMSHttp?wsdl') {res.writeHead(404);res.end('not found');return;}
    res.setHeader('content-type','text/xml');
    res.end('<wsdl:definitions xmlns:wsdl="http://schemas.xmlsoap.org/wsdl/" xmlns:soap="http://schemas.xmlsoap.org/wsdl/soap/"><wsdl:portType name="ITcpService"><wsdl:operation name="GetFBRInvoiceNumberByModel"/></wsdl:portType><soap:binding transport="http://schemas.xmlsoap.org/soap/http"/></wsdl:definitions>');
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  const port=server.address().port;
  try {
    const client=axios.create({proxy:false});
    const services=await discover(client,{platform:'win32',run:async()=>({stdout:`HTTP://+:${port}/TCPSERVICE/IMSHTTP/\n`})});
    assert.ok(services.some(s=>s.url===`http://127.0.0.1:${port}/TcpService/IMSHttp`));
    assert.deepEqual(requests,[['GET','/TcpService?wsdl'],['GET','/TcpService/IMSHttp?wsdl']]);
  } finally {await new Promise(resolve=>server.close(resolve));}
});
