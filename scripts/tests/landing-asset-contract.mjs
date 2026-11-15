import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import postcss from 'postcss';

// Inspect the exact manifest asset served by Blade, not an arbitrary CSS file.
// Browser geometry/visibility assertions remain the authoritative UI check.
const manifest=JSON.parse(readFileSync('public/build/manifest.json','utf8'));
const css=postcss.parse(readFileSync('public/build/'+manifest['resources/css/app.css'].file,'utf8'));
for (const [selector,display] of [['.xl\\:hidden','none'],['.xl\\:flex','flex']]) {
  let found=false;
  css.walkRules(selector,rule=>{
    if(rule.parent.type==='atrule'&&rule.parent.name==='media'&&/^\(min-width:\s*1280px\)$/.test(rule.parent.params)) {
      rule.walkDecls('display',decl=>{if(decl.value===display)found=true;});
    }
  });
  assert(found,`Served CSS is stale: missing ${selector} at 1280px`);
}
console.log('PASS: manifest CSS contains exact landing tablet/desktop navigation rules');
