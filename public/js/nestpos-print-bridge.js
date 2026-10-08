/* Detect installed extension without retaining credentials or installing anything. */
(() => {
    'use strict';
    const channel = 'nestpos-print-bridge-v1';
    let installed = false;
    function request(action, data = {}, timeout = 600) {
        return new Promise((resolve,reject) => {
            const requestId = crypto.randomUUID();
            let timer;
            const receive = event => {
                const m = event.data;
                if (event.source !== window || event.origin !== location.origin || m?.channel !== channel || m.direction !== 'reply' || m.requestId !== requestId) return;
                clearTimeout(timer); window.removeEventListener('message',receive); resolve(m.result);
            };
            window.addEventListener('message',receive);
            timer = setTimeout(() => {window.removeEventListener('message',receive);reject(new Error(action === 'probe' ? 'extension_unavailable' : 'enqueue_uncertain'));},timeout);
            window.postMessage({channel,direction:'request',requestId,action,...data},location.origin);
        });
    }
    const ready = request('probe').then(result => {installed = result.installed === true; return installed;}).catch(() => false);
    ready.then(() => document.querySelectorAll('[data-hotel-extension-status]').forEach(el => { el.textContent = installed ? el.dataset.present : el.dataset.absent; }));
    window.nestposPrintBridge = {
        ready,
        async detect() { await ready; return {installed,requiresAgent:true}; },
        async enqueue(url,body,csrf,agentOnline) {
            await ready;
            // A healthy selected Agent uses the established route directly.
            if (agentOnline || !installed) return fetch(url,{method:'POST',credentials:'same-origin',
                headers:{'Content-Type':'application/json',Accept:'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify(body)});
            const result = await request('enqueue',{url,body,csrf},15000);
            // Never switch routes automatically after an uncertain enqueue.
            if (result.error) throw new Error(result.error);
            return new Response(JSON.stringify(result.body),{status:result.status,headers:{'Content-Type':'application/json'}});
        },
    };
})();
