export async function b03DesignsWorkflow(page,t,v,{baseUrl,waitForOperationalSurface,dismiss,saveEvidenceScreenshot,pass}) {
  const option=(stream,key)=>page.locator('[name="receipt_options_'+stream+'['+key+']"]');
  const open=async stream=>{const panel=page.locator('[data-receipt-options="'+stream+'"]');if(!await panel.evaluate(el=>el.open))await panel.locator('summary').click();};
  const savedPaper=await page.locator('[name="rp_printer_size"]').inputValue();
  const writes=[];
  const watch=req=>{if(req.method()==='POST')writes.push(new URL(req.url()).pathname);};
  page.on('request',watch);
  try {
    for(const stream of ['local','pra']) {
      await open(stream);
      for(const kind of ['receipt','kot']) {
        const key=kind==='receipt'?'design':'kot_design';
        const select=option(stream,key);
        const values=await select.locator('option').evaluateAll(options=>options.map(o=>o.value));
        if(values.length!==9||values[0]!=='inherit')throw Error('Nine opt-in styles including Existing required for '+kind);
        for(const paper of kind==='receipt'?['80mm','58mm']:['80mm']) {
        if(kind==='receipt')await page.locator('[name="rp_printer_size"]').selectOption(paper);
        for(const design of values) {
          await select.selectOption(design);
          await page.locator('[data-print-design-preview="'+kind+'"][data-stream="'+stream+'"]').click();
          const modal=page.getByRole('dialog',{name:'Print design preview'});
          await modal.waitFor({state:'visible'});
          const preview=modal.frameLocator('iframe');
          await preview.getByText('DESIGN PREVIEW — NOT A SALE / TAX INVOICE',{exact:true}).waitFor();
          const html=await preview.locator('html').innerHTML();
          if(design!=='inherit'&&!html.includes('b03-'+kind+'-design:'+design))throw Error('Actual preview renderer lost '+stream+'/'+kind+'/'+design);
          const body=await preview.locator('body').innerText();
          if(!body.includes('Sample'))throw Error('Preview lost full item name');
          const quantity=preview.locator(kind==='receipt'?'.items-table td.col-qty':'.items-table tr:not(.items-head) td.qty').first();
          if(!/^2(?:\.0+)?$/.test((await quantity.innerText()).trim()))throw Error('Design lost the exact item quantity');
          if(kind==='receipt'&&!body.includes('580.00'))throw Error('Design changed payable amount');
          if(kind==='kot'&&body.includes('580.00'))throw Error('KOT exposed money');
          if(await preview.locator('script').count())throw Error('Read-only preview contains executable scripts');
          if(await preview.locator('body').evaluate(el=>el.scrollWidth)>await modal.locator('iframe').evaluate(el=>el.clientWidth)+2)throw Error('Print preview clips at '+v.width);
          if(design==='boxed'||design==='checklist')await saveEvidenceScreenshot(page,'b03-'+stream+'-'+kind+'-'+paper+'-'+v.width+'.png');
          await modal.getByRole('button',{name:'Close preview — nothing saved',exact:true}).click();
        }
        }
      }
    }
    if(writes.length)throw Error('Preview created a POST: '+writes.join(','));
    await page.reload({waitUntil:'domcontentloaded'});await waitForOperationalSurface(page);await dismiss(page);
    for(const stream of ['local','pra'])for(const key of ['design','kot_design'])if(await option(stream,key).inputValue()!=='inherit')throw Error('Preview/cancel changed saved design');
    if(await page.locator('[name="rp_printer_size"]').inputValue()!==savedPaper)throw Error('Preview changed the saved paper width');
    // Real scoped Save touches only the design shown in the preview, not the form's other fields.
    const scoped=async value=>{
      await open('local');await option('local','design').selectOption(value);
      await page.locator('[data-print-design-preview="receipt"][data-stream="local"]').click();
      const modal=page.getByRole('dialog',{name:'Print design preview'});
      await modal.frameLocator('iframe').getByText('DESIGN PREVIEW — NOT A SALE / TAX INVOICE',{exact:true}).waitFor();
      const [response]=await Promise.all([page.waitForResponse(r=>new URL(r.url()).pathname==='/pos/receipt-settings'&&r.request().method()==='POST'),modal.getByRole('button',{name:'Save this design only',exact:true}).click()]);
      if(!response.ok()||(await response.json()).success!==true)throw Error('Scoped design save failed');
      await modal.getByRole('status').filter({hasText:'Design saved.'}).waitFor({state:'visible'});
      await modal.getByRole('button',{name:'Close preview',exact:true}).click();
      await page.reload({waitUntil:'domcontentloaded'});await waitForOperationalSurface(page);await dismiss(page);
      if(await option('local','design').inputValue()!==value||await option('pra','design').inputValue()!=='inherit'||await option('local','kot_design').inputValue()!=='inherit')throw Error('Scoped design Save changed another stream/ticket');
    };
    await scoped('compact');await scoped('inherit');
    const chosen={local:{design:'compact',kot_design:'quantity_first'},pra:{design:'boxed',kot_design:'order_first'}};
    for(const stream of ['local','pra']){await open(stream);for(const [key,value]of Object.entries(chosen[stream]))await option(stream,key).selectOption(value);}
    const save=async()=>{await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('#rcptSettingsForm button[type="submit"]').click()]);await waitForOperationalSurface(page);await dismiss(page);};
    await save();
    for(const stream of ['local','pra'])for(const [key,value]of Object.entries(chosen[stream]))if(await option(stream,key).inputValue()!==value)throw Error('Explicit design save lost independent stream');
    for(const stream of ['local','pra']){await open(stream);for(const key of ['design','kot_design'])await option(stream,key).selectOption('inherit');}
    await save();
    for(const stream of ['local','pra'])for(const key of ['design','kot_design'])if(await option(stream,key).inputValue()!=='inherit')throw Error('Existing design restore failed');
    pass(t.name+'/'+v.width+': 54 real sandboxed Local/Fiscal previews: all nine receipt designs at 58/80mm and KOT at its existing 80mm width, no preview POST/script/print; explicit independent Save and legacy restore');
  } finally {page.off('request',watch);}
}

export async function b03DeliveryNavigationWorkflow(originalPage,t,v,{baseUrl,waitForOperationalSurface,dismiss,saveEvidenceScreenshot,pass,loopback,login}) {
  // Route faults must reach the frame: a controlling service worker owns its
  // network fetches and bypasses Playwright page.route. Other PWA journeys keep
  // their service workers enabled and run unchanged in the original context.
  const context=await originalPage.context().browser().newContext({viewport:v,serviceWorkers:'block'});
  await context.route('**/*',route=>loopback(new URL(route.request().url()).hostname)?route.continue():route.abort('blockedbyclient'));
  const page=await context.newPage();
  try {
  await login(page,t);
  await page.goto(baseUrl+'/pos/invoice/create',{waitUntil:'domcontentloaded'});await waitForOperationalSurface(page);await dismiss(page);
  const root=page.locator('[data-tn-sale-root]');
  const financial=[];
  const watch=req=>{const path=new URL(req.url()).pathname;if(req.method()==='POST'&&(/\/pos\/invoice(?:\/|$)/.test(path)||/\/pos\/restaurant\/(?:hold|orders)/.test(path)||path==='/pos/api/print-jobs'))financial.push(path);};
  page.on('request',watch);
  try {
    await root.evaluate(el=>{const d=Alpine.$data(el);d.cart=[{cart_uid:'b03-navigation-draft',item_id:null,item_type:'manual',item_name:'Synthetic B03 draft',quantity:1,unit_price:100,special_notes:'',is_tax_exempt:true}];});
    let injected=false;
    const failure=route=>{injected=true;return route.fulfill({status:200,contentType:'text/html',body:'<html><body>Synthetic unavailable frame</body></html>'});};
    await page.route('**/pos/deliveries',failure);
    await page.locator('button[onclick="tnOpenDeliveryBoard()"]:visible').first().click();
    const board=page.locator('#tn-delivery-board');
    if(!await board.evaluate(el=>el.parentElement===document.body))throw Error('Delivery overlay did not escape the sale stacking context');
    await board.locator('#tn-delivery-board-error').waitFor({state:'visible'});
    if(!injected)throw Error('Embedded document fault was not injected');
    if(await root.evaluate(el=>Alpine.$data(el).cart[0]?.cart_uid)!=='b03-navigation-draft')throw Error('Failed frame changed parent draft');
    await page.unroute('**/pos/deliveries',failure);
    await board.locator('button[onclick="tnRetryDeliveryBoard()"]:visible').click();
    await board.frameLocator('iframe').locator('[data-tn-delivery-page]').waitFor();
    await board.locator('#tn-delivery-board-error').waitFor({state:'hidden'});
    page.once('dialog',dialog=>dialog.dismiss());
    await board.locator('[data-b03-delivery-new-sale]').click();
    if(!await board.isVisible()||await root.evaluate(el=>Alpine.$data(el).cart[0]?.cart_uid)!=='b03-navigation-draft')throw Error('Cancelled New Sale lost draft or overlay');
    page.once('dialog',dialog=>dialog.accept());
    await board.locator('[data-b03-delivery-new-sale]').click();
    await board.waitFor({state:'hidden'});
    if(await root.evaluate(el=>Alpine.$data(el).cart.length)!==0)throw Error('Confirmed New Sale did not clear draft');
    if(financial.length)throw Error('Navigation sent a financial/print write: '+financial.join(','));
    await root.locator('[x-ref="searchInput"]').waitFor({state:'visible'});
    await page.waitForFunction(()=>document.activeElement===document.querySelector('[data-tn-sale-root] [x-ref="searchInput"]'));
    await saveEvidenceScreenshot(page,'b03-delivery-new-sale-'+v.width+'.png');
    pass(t.name+'/'+v.width+': unexpected embedded document stays locally recoverable; Retry succeeds; cancel preserves draft, confirmed New Sale dismisses frame and restores focus; no sale/KOT/print POST');
  } finally {page.off('request',watch);}
  } finally {await context.close();}
}

export async function b03AutoKotWorkflow(page,t,v,{baseUrl,waitForOperationalSurface,dismiss,saveEvidenceScreenshot,pass,openMobileCart}) {
  await page.goto(baseUrl+'/pos/invoice/create',{waitUntil:'domcontentloaded'});await waitForOperationalSurface(page);await dismiss(page);
  const root=page.locator('[data-tn-sale-root]');
  if(!await root.evaluate(el=>Alpine.$data(el).kitchenSettings.dine_in_auto_kot))throw Error('Persisted auto-KOT fixture is not ON');
  const posts=[];const watch=req=>{if(req.method()==='POST')posts.push(new URL(req.url()).pathname);};page.on('request',watch);
  const reserve='/pos/restaurant/tables/'+t.b03AutoKot.tableId+'/reserve';
  const delay=async route=>{await new Promise(resolve=>setTimeout(resolve,500));await route.continue();};
  try {
    await root.locator('[x-ref="searchInput"]:visible').fill(t.b03AutoKot.product);
    const tile=root.locator('.prod-card').filter({has:page.getByText(t.b03AutoKot.product,{exact:true}).and(page.locator('p[x-text="item.name"]'))});
    await tile.waitFor({state:'visible'});await tile.click();
    if(v.width<768){
      await root.locator('[data-cart-menu-open]:visible').click();
      await page.waitForFunction(()=>Alpine.$data(document.querySelector('[data-tn-sale-root]')).mobileView==='cart');
    }
    await page.locator('[data-video="counter-dine-in"]:visible').click();
    await page.route('**'+reserve,delay);
    const target=page.locator('button[data-video="counter-table"]').filter({hasText:t.b03AutoKot.tableNumber});
    page.once('dialog',dialog=>dialog.dismiss());
    await target.dblclick();
    await page.waitForFunction(id=>{const d=Alpine.$data(document.querySelector('[data-tn-sale-root]'));return Number(d.selectedTable?.id)===Number(id)&&!d._tableSelectionBusy;},t.b03AutoKot.tableId);
    const state=await root.evaluate(el=>{const d=Alpine.$data(el);return{cart:d.cart.map(i=>[i.item_name,i.quantity]),recall:d.recalledOrderId,incoming:d.incomingOrderId};});
    if(state.cart.length!==1||state.cart[0][0]!==t.b03AutoKot.product||state.recall||state.incoming)throw Error('Cancelled auto-KOT lost or committed the draft');
    if(posts.filter(p=>p===reserve).length!==1||posts.some(p=>/\/restaurant\/(?:hold|orders)|\/api\/print-jobs|\/invoice(?:\/|$)/.test(p)))throw Error('Table double-click duplicated reserve or posted an order/KOT');
    await saveEvidenceScreenshot(page,'b03-table-explicit-commit-'+v.width+'.png');
    if(v.width<768){
      await root.locator('[data-cart-menu-back]:visible').click();
      await page.waitForFunction(()=>Alpine.$data(document.querySelector('[data-tn-sale-root]')).mobileView==='menu');
    }
    page.once('dialog',dialog=>dialog.accept());
    await Promise.all([page.waitForResponse(response=>new URL(response.url()).pathname==='/pos/restaurant/tables/'+t.b03AutoKot.tableId+'/release'&&response.request().method()==='POST'),page.locator('[data-nav-new-sale="action"]:visible').first().click()]);
    pass(t.name+'/'+v.width+': persisted auto-KOT ON, actual table double-click reserves once; Cancel keeps draft and posts no order/print; explicit New Sale releases reservation');
  } finally {page.off('request',watch);await page.unroute('**'+reserve,delay);}
}
