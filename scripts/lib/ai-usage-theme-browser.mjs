// Read-only theme matrix on disposable synthetic admin pages; never saves server preferences.
export async function verifyAiUsageAdminThemes(page, pass, width) {
  const saved=await page.evaluate(()=>({theme:getAdminTheme(),stored:localStorage.getItem('admin_theme'),dark:document.documentElement.classList.contains('dark')}));
  try {
    for(const theme of ['indigo','emerald','cyan','rose','amber','purple']) for(const dark of [false,true]) {
      await page.evaluate(({theme,dark})=>{applyAdminTheme(theme);document.documentElement.classList.toggle('dark',dark);},{theme,dark});
      const errors=await page.evaluate(()=>{
        const rgb=s=>{const srgb=s.match(/^color\(\s*srgb\s+(.+?)\s*\)$/),m=srgb||s.match(/^rgba?\(([^)]+)\)$/);if(!m)throw Error('Unsupported colour '+s);const c=m[1].replace(/\//g,' ').split(/[,\s]+/).filter(Boolean);return [...c.slice(0,3).map(x=>x.endsWith('%')?parseFloat(x)*2.55:Number(x)*(srgb?255:1)),c[3]==null?1:c[3].endsWith('%')?parseFloat(c[3])/100:Number(c[3])];};
        const over=(a,b)=>a.slice(0,3).map((x,i)=>x*a[3]+b[i]*(1-a[3]));
        const bg=el=>{const chain=[];for(let p=el;p;p=p.parentElement)chain.unshift(p);let c=[255,255,255];for(const p of chain)c=over(rgb(getComputedStyle(p).backgroundColor),c);return c;};
        const lum=c=>c.map(x=>{x/=255;return x<=.04045?x/12.92:((x+.055)/1.055)**2.4;}).reduce((n,x,i)=>n+x*[.2126,.7152,.0722][i],0);
        const ratio=(a,b)=>(Math.max(lum(a),lum(b))+.05)/(Math.min(lum(a),lum(b))+.05);

        const errors=[], root=document.querySelector('#ai-usage-content');
        const els=root.querySelectorAll('h1,h2,p,dt,dd,label,input,button,th,td');let sampled=0;
        for(const el of els){
          if(!el.getClientRects().length || el.type==='hidden')continue;
          sampled++;
          const back=bg(el),r=ratio(over(rgb(getComputedStyle(el).color),back),back);
          if(r<4.5)errors.push(el.tagName+' contrast '+r.toFixed(2));
        }
        if(sampled<35)errors.push('AI usage contrast sample incomplete');
        const rect=root.getBoundingClientRect();
        if(rect.left<0||rect.right>innerWidth+1)errors.push('Usage page exceeds viewport');
        const region=root.querySelector('#ai-usage-events');
        if(region.getBoundingClientRect().right>rect.right+1||getComputedStyle(region).overflowX!=='auto')errors.push('Events escaped bounded scroll region');
        return errors;
      });
      if(errors.length)throw Error('AI usage '+theme+'/'+dark+': '+errors.join('; '));
      for(const selector of ['#usage-month','#input_per_million','#output_per_million','#monthly_budget','#ai-usage-budget-form button[type=submit]']) {
        const control=page.locator(selector);await control.scrollIntoViewIfNeeded();await control.click({trial:true});
        const box=await control.boundingBox();
        if(!box||box.width<44||box.height<30||box.x<0||box.x+box.width>width+1)throw Error('AI usage '+theme+'/'+dark+' unusable control '+selector);
      }
    }
  } finally {
    await page.evaluate(({theme,stored,dark})=>{applyAdminTheme(theme);if(stored===null)localStorage.removeItem('admin_theme');else localStorage.setItem('admin_theme',stored);document.documentElement.classList.toggle('dark',dark);},saved);
    await page.locator('#ai-usage-heading').scrollIntoViewIfNeeded();
  }
  pass('AI USAGE THEMES PASS: '+width+': six admin palettes x light/dark, >=4.5 key-text contrast, usable controls and bounded tables; restored synthetic preference');
}
