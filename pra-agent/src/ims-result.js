'use strict';
const {fiscalNumber}=require('./fiscal-result');

// Normalize only explicit fiscal acknowledgement names and bounded JSON envelopes.
// Never turn an invoice number alone, prose, or a transport status into Code 100.
function resultOnly(data) {
  const codes=[],numbers=[];let invalid=false,visited=0;
  function visit(value,depth) {
    if(depth>2 || ++visited>16) {invalid=true;return;}
    if(typeof value==='string') {
      if(value.length>16384) return;
      try{value=JSON.parse(value);}catch(_){return;}
    }
    if(!value || typeof value!=='object' || Array.isArray(value)) return;
    const entries=Object.entries(value);if(entries.length>64) {invalid=true;return;}
    for(const [key,v] of entries) {
      const name=key.replace(/_/g,'').toLowerCase();
      if(name==='code') {
        if(v===null) continue;
        if(!['string','number'].includes(typeof v) || String(v).trim().length>12) invalid=true;
        else codes.push(String(v).trim());
      } else if(['invoicenumber','fbrinvoicenumber'].includes(name)) {
        if(v===null || (typeof v==='string' && v.trim()==='')) continue;
        const candidate=typeof v==='number' && Number.isSafeInteger(v) ? String(v) : v;
        const number=fiscalNumber(candidate);if(number) numbers.push(number);else invalid=true;
      } else if(name==='response' && typeof v==='string' && /^(?:[0-9]{6}|[A-Z][A-Z-]*-[0-9]{3,}$)/.test(v.trim()) && fiscalNumber(v)) {
        numbers.push(v.trim());
      } else if(['result','data','d','response','getfbrinvoicenumberbymodelresult'].includes(name)) {
        visit(v,depth+1);
      }
    }
  }
  visit(data,0);
  const statuses=[...new Set(codes)],references=[...new Set(numbers)];
  if(!statuses.length && !references.length && !invalid) return null;
  const result={};
  if(invalid || statuses.length>1 || references.length>3) result.Code='AMBIGUOUS';
  else if(statuses.length) result.Code=statuses[0];
  references.slice(0,3).forEach((number,i)=>{result[['InvoiceNumber','FBRInvoiceNumber','invoiceNumber'][i]]=number;});
  return result;
}
// Schema only, never response values: makes an unknown serializer diagnosable.
function describeResponse(data) {
  const fields=[];
  function walk(value,prefix,depth) {
    if(depth>2 || fields.length>=16) return;
    if(typeof value==='string' && value.length<=16384) {try{value=JSON.parse(value);}catch(_){return;}}
    if(!value || typeof value!=='object' || Array.isArray(value)) return;
    for(const [key,v] of Object.entries(value).slice(0,16)) {
      if(fields.length>=16) return;
      if(!/^[A-Za-z][A-Za-z0-9_]{0,39}$/.test(key)) continue;
      const type=v===null?'null':Array.isArray(v)?'array':typeof v;
      if(!['string','number','boolean','object','array','null'].includes(type)) continue;
      const name=prefix+key; if(name.length>80) continue;
      fields.push(name+':'+type);
      if(['result','data','d','response','getfbrinvoicenumberbymodelresult'].includes(key.toLowerCase()))walk(v,name+'.',depth+1);
    }
  }
  walk(data,'',0);return fields;
}
module.exports={resultOnly,describeResponse};
