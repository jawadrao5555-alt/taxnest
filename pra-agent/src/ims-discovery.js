'use strict';
// Read-only metadata discovery: never probe by sending an invoice.
const { execFile } = require('node:child_process');
const { promisify } = require('node:util');
async function discover(axios, {platform=process.platform, run=promisify(execFile)}={}) {
  const ports=new Set([8524,8525]);
  if(platform==='win32') {
    try {
      const {stdout}=await run('netsh.exe',['http','show','servicestate','view=requestq','verbose=no'],{windowsHide:true,timeout:5000,maxBuffer:262144});
      for(const m of String(stdout).matchAll(/HTTP:\/\/(?:\+|\*|localhost|127\.0\.0\.1):(\d{4,5})\/(?:TCPSERVICE(?:\/IMSHTTP)?\/?)?(?=\s|$)/gi)) {
        if(Number(m[1])<=65535 && ports.size<16) ports.add(Number(m[1]));
      }
    } catch (_) { /* Standard ports remain discoverable without elevation. */ }
  }
  const found=await Promise.all([...ports].map(async port=>{
    // WCF hosts may expose metadata on the service or its HTTP endpoint.
    // Both probes are bounded metadata GETs; neither can submit an invoice.
    for (const metadataPath of ['/TcpService?wsdl', '/TcpService/IMSHttp?wsdl']) {
    try {
      const r=await axios.get(`http://127.0.0.1:${port}${metadataPath}`,{timeout:1500,maxRedirects:0,maxContentLength:262144});
      const xml=r.data;
      if(typeof xml==='string' && !/<!DOCTYPE|<!ENTITY/i.test(xml) && /GetFBRInvoiceNumberByModel/.test(xml) && /ITcpService/.test(xml) && /soap\/http/.test(xml)) return {protocol:'soap',url:`http://127.0.0.1:${port}/TcpService/IMSHttp`};
    } catch (_) { }
    }
    return null;
  }));
  return found.filter(Boolean);
}
module.exports={discover};
