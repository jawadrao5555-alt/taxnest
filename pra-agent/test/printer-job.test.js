'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const {processCloudPrintJob} = require('../src/printer-job');
const job = {id: 1, target_printer: 'Synthetic queue', type: 'kot'};

test('204 and empty documents acknowledge no-document without calling the spooler', async () => {
  for (const content of [{status:204,data:''},{status:200,data:''}]) {
    let prints=0; const reports=[];
    const result=await processCloudPrintJob(job,{fetchContent:async()=>content,printHtml:async()=>{prints++;},report:async(_,r)=>reports.push(r)});
    assert.equal(prints,0); assert.equal(result.outcome,'no_document');
    assert.equal(reports.length,1); assert.equal(reports[0].success,true);
  }
});

test('a content timeout is pre-spool failure and a late response cannot invoke printing', async () => {
  let prints=0, lateResolve; const reports=[];
  const late=new Promise(resolve=>{lateResolve=resolve;});
  const timedOut=Promise.reject(new Error('timeout of 15000ms exceeded'));
  const result=await processCloudPrintJob(job,{fetchContent:()=>Promise.race([timedOut,late]),printHtml:async()=>{prints++;},report:async(_,r)=>reports.push(r)});
  lateResolve({status:200,data:'<html>late</html>'}); await new Promise(resolve=>setImmediate(resolve));
  assert.equal(prints,0); assert.equal(result.outcome,'pre_spool_failed');
  assert.equal(reports[0].stage,'content_fetch'); assert.equal(reports.length,1);
});

test('Windows acceptance is reported separately from physical paper confirmation', async () => {
  let prints=0; const reports=[]; let time=0;
  const result=await processCloudPrintJob(job,{clock:()=>time,fetchContent:async()=>{time=50;return {status:200,data:'<html>synthetic</html>'};},
    printHtml:async(html,printer,type)=>{assert.equal(printer,job.target_printer);assert.equal(type,'kot');prints++;time=80;return {success:true,error:null};},report:async(_,r)=>reports.push(r)});
  assert.equal(prints,1); assert.equal(result.outcome,'spool_accepted'); assert.equal(result.content_ms,50);assert.equal(result.print_ms,30);
  assert.equal(reports.length,1);
});

test('lost result acknowledgement never invokes the spooler again or reports a false pre-spool failure', async () => {
  let prints=0,reports=0;
  await assert.rejects(processCloudPrintJob(job,{fetchContent:async()=>({status:200,data:'<html>synthetic</html>'}),
    printHtml:async()=>{prints++;return {success:true};},report:async()=>{reports++;throw new Error('ack lost');}}),/ack lost/);
  assert.equal(prints,1);assert.equal(reports,1);
});

test('a print-call failure remains output-unknown and does not automatically retry', async () => {
  let prints=0;const reports=[];
  const result=await processCloudPrintJob(job,{fetchContent:async()=>({status:200,data:'<html>synthetic</html>'}),
    printHtml:async()=>{prints++;throw new Error('print timeout (30s)');},report:async(_,r)=>reports.push(r)});
  assert.equal(prints,1);assert.equal(result.outcome,'output_unknown');assert.equal(result.stage,'print_call');assert.equal(reports.length,1);
});

test('one transient content retry keeps the same claim and invokes printing only once', async () => {
  let fetches=0,prints=0;const reports=[];
  const result=await processCloudPrintJob(job,{pause:async()=>{},fetchContent:async actual=>{
    assert.equal(actual,job);fetches++;
    if(fetches===1)throw Object.assign(new Error('timeout of 15000ms exceeded'),{code:'ECONNABORTED'});
    return {status:200,data:'<html>recovered</html>'};
  },printHtml:async()=>{prints++;return {success:true};},report:async(_,r)=>reports.push(r)});
  assert.equal(fetches,2);assert.equal(prints,1);assert.equal(reports.length,1);assert.equal(result.content_attempts,2);
});

test('repeated transport failures stop after two downloads with no spool invocation', async () => {
  let fetches=0,prints=0;const reports=[];
  const result=await processCloudPrintJob(job,{pause:async()=>{},fetchContent:async()=>{fetches++;throw Object.assign(new Error('timeout'),{code:'ETIMEDOUT'});},
    printHtml:async()=>{prints++;},report:async(_,r)=>reports.push(r)});
  assert.equal(fetches,2);assert.equal(prints,0);assert.equal(reports.length,1);assert.equal(result.outcome,'pre_spool_failed');
});

test('an HTTP stale-claim rejection is never retried', async () => {
  let fetches=0;
  await processCloudPrintJob(job,{fetchContent:async()=>{fetches++;throw Object.assign(new Error('stale claim'),{code:'ERR_BAD_REQUEST',response:{status:409}});},
    printHtml:async()=>{assert.fail('No print permitted');},report:async()=>{}});
  assert.equal(fetches,1);
});
