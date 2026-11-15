import assert from 'node:assert/strict';

/** Real computed style and geometry, not a source-string claim. No payment is sent. */
export async function collectionControlChecks(page, formSelector) {
  const form=page.locator(formSelector), submit=form.locator('button[type="submit"]').first();
  const confirmation=form.locator('.cash-confirm').first();
  for(const target of [confirmation,submit]) {
    await target.evaluate(el=>el.scrollIntoView({block:'center',inline:'nearest'}));
    const result=await target.evaluate(el=>{
      const r=el.getBoundingClientRect(),support=document.querySelector('[data-madadgar-placement]');
      const bubble=support?.querySelector(':scope > button'),b=bubble?.getBoundingClientRect();
      const intersects=b&&Math.min(r.right,b.right)>Math.max(r.left,b.left)&&Math.min(r.bottom,b.bottom)>Math.max(r.top,b.top);
      const top=document.elementFromPoint(r.left+r.width/2,r.top+r.height/2);
      return {overlap:!!intersects,hit:top===el||el.contains(top),supportPosition:support?getComputedStyle(support).position:null};
    });
    assert.equal(result.overlap,false,'Support launcher overlaps collection confirmation/action');
    assert.equal(result.hit,true,'Collection control is obscured');
    if(result.supportPosition)assert.equal(result.supportPosition,'relative','Collection support must remain in normal document flow');
  }
  const style=await submit.evaluate(el=>{const s=getComputedStyle(el),r=el.getBoundingClientRect();return {width:r.width,height:r.height,bg:s.backgroundColor,fg:s.color};});
  assert(style.width>=44&&style.height>=44,'Payment action needs a 44px touch target');
  const luminance=rgb=>{
    const channels=rgb.match(/[\d.]+/g)?.map(Number);assert(channels?.length>=3,'Expected RGB colors');
    if(channels.length===4)assert.equal(channels[3],1,'Payment button must not have transparent background');
    const [r,g,b]=channels.slice(0,3).map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4;});
    return .2126*r+.7152*g+.0722*b;
  };
  const a=luminance(style.bg),b=luminance(style.fg);
  assert((Math.max(a,b)+.05)/(Math.min(a,b)+.05)>=4.5,'Payment action contrast is below 4.5:1');
}
