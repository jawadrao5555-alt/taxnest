const {JSDOM}=require('jsdom'),fs=require('fs'),{execFileSync}=require('child_process'),assert=require('assert/strict');
const root=require('path').resolve(__dirname,'../..');
const baseline=execFileSync('git',['show',(process.argv[2]||'HEAD^')+':resources/views/pos/universal.blade.php'],{cwd:root,encoding:'utf8',maxBuffer:4*1024*1024});
async function check(source){
 const a=source.indexOf('        callerOverlayStyle('),method=source.slice(a,source.indexOf('\n        },',a)+11);
 const condition=source.match(/<div[^>]*x-show="(callerDialFallback[^"]*)"[^>]*:style="callerOverlayStyle\(330\)"/)[1];
 const n=source.indexOf('        callerNumberOf(ev) {'),numberMethod=source.slice(n,source.indexOf('\n        },',n)+11);
 const dom=new JSDOM('<div x-data="fixture" @resize.window="callerOverlayEpoch++"><div id="dial" x-show="'+condition+'" :style="callerOverlayStyle(330)">Number</div></div>',{runScripts:'outside-only',pretendToBeVisual:true});
 dom.window.eval(fs.readFileSync(require.resolve('alpinejs/dist/cdn.js'),'utf8'));
 dom.window.eval('Alpine.data("fixture",()=>({callerDialFallback:null,callerOverlayEpoch:0,'+method+numberMethod+'}));');
 await new Promise(r=>setTimeout(r,25));dom.window.dispatchEvent(new dom.window.Event('resize'));await new Promise(r=>setTimeout(r,25));
 const el=dom.window.document.getElementById('dial'),hidden=dom.window.getComputedStyle(el).display==='none';
 const d=dom.window.Alpine.$data(el);d.callerDialFallback={phone:'03000000000'};await new Promise(r=>setTimeout(r,25));assert.notEqual(dom.window.getComputedStyle(el).display,'none');
 d.callerDialFallback=null;await new Promise(r=>setTimeout(r,25));dom.window.dispatchEvent(new dom.window.Event('resize'));await new Promise(r=>setTimeout(r,25));
 const afterClose=dom.window.getComputedStyle(el).display==='none';dom.window.close();return{hidden,afterClose};
}
(async()=>{const old=await check(baseline),fixed=await check(fs.readFileSync(root+'/resources/views/pos/universal.blade.php','utf8'));assert.equal(old.hidden,false);assert.equal(old.afterClose,false);assert.equal(fixed.hidden,true);assert.equal(fixed.afterClose,true);console.log({baseline:old,updated:fixed,realAlpine:'3.14.9'});})();
