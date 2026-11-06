'use strict';
// PRAL's WCF contract. Field order follows the published XSD sequence.
const NS = 'http://schemas.datacontract.org/2004/07/IMS_ServiceLibrary';
const ACTION = 'http://tempuri.org/ITcpService/GetFBRInvoiceNumberByModel';
const fields = ['BuyerCNIC','BuyerNTN','BuyerName','BuyerPhoneNumber','DateTime','Discount','FurtherTax','InvoiceNumber','InvoiceType','Items','POSID','PaymentMode','RefUSIN','TotalBillAmount','TotalQuantity','TotalSaleValue','TotalTaxCharged','USIN'];
const itemFields = ['Discount','FurtherTax','InvoiceType','ItemCode','ItemName','PCTCode','Quantity','RefUSIN','SaleValue','TaxCharged','TaxRate','TotalAmount'];
const escape = v => String(v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&apos;'}[c]));
function endpoint(value) {
  try { const u = new URL(value); return u.protocol === 'http:' && ['localhost','127.0.0.1'].includes(u.hostname) && Number(u.port)>=1024 && Number(u.port)<=65535 && u.pathname === '/TcpService/IMSHttp' && !u.search && !u.hash && !u.username && !u.password; } catch (_) { return false; }
}
function serialize(data, keys) {
  return keys.filter(k => data[k] !== undefined).map(k => {
    if (data[k] === null) return `<a:${k} i:nil="true"/>`;
    if (k === 'Items') {
      if (!Array.isArray(data[k])) throw new Error('Invalid IMS items');
      return '<a:Items>'+data[k].map(item=>'<a:InvoiceItems>'+serialize(item,itemFields)+'</a:InvoiceItems>').join('')+'</a:Items>';
    }
    if (!['string','number'].includes(typeof data[k]) || (typeof data[k]==='number' && !Number.isFinite(data[k]))) throw new Error('Invalid IMS field');
    // Convert only the separator; do not reinterpret the shop's local clock.
    const value = k==='DateTime' ? String(data[k]).replace(/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})$/, '$1T$2') : data[k];
    return `<a:${k}>${escape(value)}</a:${k}>`;
  }).join('');
}
function envelope(invoice) {
  return `<?xml version="1.0" encoding="utf-8"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><GetFBRInvoiceNumberByModel xmlns="http://tempuri.org/"><invoice xmlns:a="${NS}" xmlns:i="http://www.w3.org/2001/XMLSchema-instance">${serialize(invoice,fields)}</invoice></GetFBRInvoiceNumberByModel></s:Body></s:Envelope>`;
}
function decode(xml) {
  if (typeof xml !== 'string' || xml.length>1048576 || /<!DOCTYPE|<!ENTITY|<(?:\w+:)?Fault\b/i.test(xml)) throw new Error('Unconfirmed IMS SOAP response');
  const matches = [...xml.matchAll(/<(?:\w+:)?GetFBRInvoiceNumberByModelResult\b[^>]*>([\s\S]*?)<\/(?:\w+:)?GetFBRInvoiceNumberByModelResult\s*>/g)];
  if(matches.length!==1) throw new Error('Missing IMS SOAP result');
  const result = matches[0][1].replace(/<!\[CDATA\[([\s\S]*?)\]\]>/g,'$1').replace(/&#(x[\da-f]+|\d+);/gi,(_,n)=>String.fromCodePoint(n[0].toLowerCase()==='x'?parseInt(n.slice(1),16):Number(n))).replace(/&(quot|apos|lt|gt|amp);/g,(_,n)=>({quot:'"',apos:"'",lt:'<',gt:'>',amp:'&'}[n]));
  // Some WCF installations JSON-encode the JSON response string once more.
  // Decode only that bounded wrapper; never infer success from a bare number.
  let data=JSON.parse(result);
  if (typeof data==='string') data=JSON.parse(data);
  if (!data || typeof data!=='object' || Array.isArray(data)) throw new Error('Invalid IMS result');
  return data;
}
async function submit(axios,url,payload) {
  if (!endpoint(url)) throw new Error('Invalid local SOAP endpoint');
  const r=await axios.post(url,envelope(payload),{timeout:45000,maxRedirects:0,maxContentLength:1048576,headers:{'Content-Type':'text/xml; charset=utf-8',SOAPAction:`"${ACTION}"`}});
  return { ...r, data:decode(r.data) };
}
module.exports={endpoint,envelope,decode,submit,ACTION};
