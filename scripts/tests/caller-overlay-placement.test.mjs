import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const s=fs.readFileSync(new URL('../../resources/views/pos/universal.blade.php',import.meta.url),'utf8');const a=s.indexOf('        callerOverlayStyle(');const method=s.slice(a,s.indexOf('\n        },',a)+11);
function style(bottom,height,width=340){const u=vm.runInNewContext('({'+method+'})',{document:{querySelector:()=>bottom===null?null:{getBoundingClientRect:()=>({bottom})}},window:{innerHeight:height}});return u.callerOverlayStyle(width);}
test('normal and expanded headers determine viewport clearance, never a fixed 80px guess',()=>{assert.match(style(64,900),/top:72px;max-height:820px;/);assert.match(style(144,900),/top:152px;max-height:740px;/);});
test('short mobile view is bounded with scrolling; old/no-header fallback remains usable',()=>{assert.match(style(140,300),/top:148px;max-height:144px;/);assert.match(style(null,700),/top:72px;/);assert.match(style(64,700,0),/width:auto;/);assert.match(style(64,700),/max-width:calc\(100vw - 32px\);overflow-y:auto/);});
