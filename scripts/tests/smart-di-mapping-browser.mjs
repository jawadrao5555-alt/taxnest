// Real Alpine mapping screen, served only on loopback with fictional response fixtures.
// Backend upload/mapping coverage lives in InvoiceBulkImportTest; this is component UI QA.
import {readFileSync} from 'node:fs';
import {createServer} from 'node:http';
import assert from 'node:assert/strict';
import {assertLocalOnlyBaseUrl, launchLocalBrowser, saveEvidenceScreenshot} from '../lib/local-browser.mjs';
const blade = readFileSync(new URL('../../resources/views/invoice/index.blade.php', import.meta.url), 'utf8');
const start = blade.indexOf('function bulkImport()');
const js = blade.slice(start, blade.indexOf('</script>', start)).replace(/\{\{[^\n]*?\}\}/g, 'false');
const begin = blade.indexOf('<div x-show="step === \'mapping\'"');
const markup = blade.slice(begin, blade.indexOf('<div x-show="step === \'preview\'"', begin))
    .replace(/@if\(!empty\(\$aiReaderAllowed\)\)[\s\S]*?@endif/g, '').replace(/\{\{--[\s\S]*?--\}\}/g, '');
const fixture = {mapping_token: 'x'.repeat(40), headers: ['Qty', 'outlet-name', 'Corrected Outlet'], fields: [
    {key: 'buyer_name', value_required: true}, {key: 'quantity', value_required: true}, {key: 'document_type', value_required: true, options: ['Sale Invoice', 'Credit Note']},
], presets: [{id: 7, name: 'Local Test Format', matches: true, mapping: {buyer_name: 'Outlet Name', quantity: 'Qty'}, defaults: {document_type: 'Sale Invoice'}}], smart_mapping_enabled: true, recommended_preset_id: 7};
const html = `<!doctype html><meta name="csrf-token" content="local-test"><style>[x-cloak]{display:none}</style><script>${js}</script><div x-data="bulkImport()" x-init='setupMapping(${JSON.stringify(fixture)})'>${markup}<div x-show="step === 'preview'">Validated preview</div></div><script src="/alpine.js" defer></script>`;
const calls = [];
const server = createServer((req, res) => {
    if (req.url === '/alpine.js') {res.setHeader('Content-Type', 'text/javascript');res.end(readFileSync(new URL('../../node_modules/alpinejs/dist/cdn.js', import.meta.url)));return;}
    if (req.method === 'POST') {let body='';req.on('data', c=>body+=c);req.on('end',()=>{calls.push({url:req.url,body:JSON.parse(body)});res.setHeader('Content-Type','application/json');res.end(JSON.stringify({batch_id:1,preview:[],total:1,valid_count:1,error_count:0}));});return;}
    res.setHeader('Content-Type','text/html');res.end(html);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const base = assertLocalOnlyBaseUrl(`http://127.0.0.1:${server.address().port}`);
const {browser} = await launchLocalBrowser();
try {
    const page = await browser.newPage();
    const errors=[];page.on('pageerror', e=>errors.push(e.message));
    await page.goto(base);
    await page.getByText('RECOMMENDED', {exact:true}).waitFor();
    const column = page.locator('select').first();
    await page.waitForFunction(()=>document.querySelector('select')?.value==='outlet-name');
    assert.equal(await page.locator('select').nth(3).inputValue(), 'Sale Invoice', 'Saved fixed value must be visible');
    assert.equal(calls.length,0);
    await column.selectOption('Corrected Outlet');
    await saveEvidenceScreenshot(page,'smart-di-mapping-review.png');
    await page.getByText('Continue to Preview',{exact:true}).click();
    await page.getByText('Validated preview',{exact:true}).waitFor();
    assert.equal(calls.length,1);assert.equal(calls[0].body.mapping.buyer_name,'Corrected Outlet');assert.equal(calls[0].body.preset_id,undefined);
    await page.evaluate(f=>Alpine.$data(document.querySelector('[x-data]')).setupMapping({...f,recommended_preset_id:null,format_match_reason:'ambiguous_presets'}),fixture);
    await page.getByText('Several saved presets match.',{exact:false}).waitFor();
    await page.getByText('Review mapping',{exact:true}).click();
    await page.waitForFunction(()=>document.querySelector('select')?.value==='outlet-name');
    assert.equal(calls.length,1);assert.deepEqual(errors,[]);
    console.log('Chrome mapping review, correction, ambiguity and explicit confirmation passed; no page errors');
} finally {await browser.close();server.close();}
