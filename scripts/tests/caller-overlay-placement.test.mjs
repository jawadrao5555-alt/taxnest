import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const s=fs.readFileSync(new URL('../../resources/views/pos/universal.blade.php',import.meta.url),'utf8');const a=s.indexOf('        callerOverlayStyle(');const method=s.slice(a,s.indexOf('\n        },',a)+11);
function style(bottom,height,width=340){const u=vm.runInNewContext('({'+method+'})',{document:{querySelector:()=>bottom===null?null:{getBoundingClientRect:()=>({bottom})}},window:{innerHeight:height}});return u.callerOverlayStyle(width);}
test('normal and expanded headers determine viewport clearance, never a fixed 80px guess',()=>{assert.equal(style(64,900).top,'72px');assert.equal(style(64,900).maxHeight,'820px');assert.equal(style(144,900).top,'152px');assert.equal(style(144,900).maxHeight,'740px');});
test('short mobile view is bounded with scrolling; old/no-header fallback remains usable',()=>{assert.equal(style(140,300).top,'148px');assert.equal(style(140,300).maxHeight,'144px');assert.equal(style(null,700).top,'72px');assert.equal(style(64,700,0).width,'auto');assert.equal(style(64,700).maxWidth,'calc(100vw - 32px)');assert.equal(style(64,700).overflowY,'auto');assert.equal(style(64,700).display,undefined);});
