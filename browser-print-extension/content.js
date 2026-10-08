/* Optional browser queue bridge; the existing Agent remains the physical printer. */
(() => {
    'use strict';
    const channel = 'nestpos-print-bridge-v1';
    if (!['https://taxnest.pk','https://www.taxnest.pk'].includes(location.origin) || !location.pathname.startsWith('/pos/')) return;
    const allowed = url => url.origin === location.origin &&
        /^\/pos\/hotel\/stays\/\d+\/(?:bills\/\d+\/print|statement\/silent-print)$/.test(url.pathname) && !url.search && !url.hash;
    const jobs = new Map();
    const reply = (requestId, result) => window.postMessage({channel,direction:'reply',requestId,result},location.origin);
    window.addEventListener('message', async event => {
        const m = event.data;
        if (event.source !== window || event.origin !== location.origin || !m || m.channel !== channel || m.direction !== 'request' || !/^[a-f0-9-]{36}$/i.test(m.requestId || '')) return;
        if (m.action === 'probe') { reply(m.requestId,{installed:true,version:'1.0.0',requiresAgent:true}); return; }
        if (m.action !== 'enqueue') return;
        let url;
        try { url = new URL(m.url,location.href); } catch (_) { reply(m.requestId,{error:'invalid_request',certain:true}); return; }
        const b = m.body;
        if (!allowed(url) || !b || !/^[a-f0-9-]{36}$/i.test(b.print_attempt_uuid || '') ||
            Object.keys(b).some(k => !['print_attempt_uuid','paper'].includes(k)) ||
            (b.paper !== undefined && !['58mm','80mm'].includes(b.paper)) || typeof m.csrf !== 'string' || m.csrf.length < 20 || m.csrf.length > 100) {
            reply(m.requestId,{error:'invalid_request',certain:true}); return;
        }
        const key = url.href + '|' + b.print_attempt_uuid;
        if (!jobs.has(key)) {
            if (jobs.size >= 100) { reply(m.requestId,{error:'bridge_busy',certain:true}); return; }
            const job = fetch(url.href,{method:'POST',credentials:'same-origin',redirect:'error',
                headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-TOKEN':m.csrf},body:JSON.stringify(b)})
                .then(async response => ({status:response.status,body:await response.json()}))
                .catch(() => ({error:'enqueue_uncertain',certain:false}));
            jobs.set(key,job);
            // Memory bounds do not replace server-side durable UUID deduplication.
            setTimeout(() => jobs.delete(key),60000);
        }
        const result = await jobs.get(key);
        if (result.error) jobs.delete(key); // An explicit retry keeps the same server UUID.
        reply(m.requestId,result);
    });
})();
