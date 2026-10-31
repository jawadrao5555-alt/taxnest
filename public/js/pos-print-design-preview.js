/* Real renderer in a sandbox; only the explicit scoped Save writes one choice. */
(function () {
    document.addEventListener('click', function (event) {
        const button=event.target.closest('[data-print-design-preview]');
        if(!button) return;
        event.preventDefault();
        const stream=button.dataset.stream, kind=button.dataset.printDesignPreview;
        const query=new URLSearchParams({kind,stream});
        const form=button.closest('form'), prefix='receipt_options_'+stream;
        if(form)for(const [key,value] of new FormData(form))if(key.startsWith(prefix+'['))query.set(key,String(value));
        const paper=form?.querySelector('[name="rp_printer_size"]')?.value;
        if(kind==='receipt'&&['58mm','80mm'].includes(paper))query.set('paper',paper);
        const key=kind==='receipt'?'design':'kot_design', value=query.get(prefix+'['+key+']')||'inherit';
        const dialog=document.createElement('dialog');
        dialog.setAttribute('aria-label','Print design preview');
        dialog.style.cssText='width:min(95vw,700px);height:90vh;padding:12px;border:1px solid #333;border-radius:12px;';
        const close=document.createElement('button');close.type='button';close.textContent='Close preview — nothing saved';close.style.cssText='padding:10px;margin-bottom:8px;';
        const save=document.createElement('button');save.type='button';save.textContent='Save this design only';save.style.cssText='padding:10px;margin:0 0 8px 8px;border:1px solid #333;border-radius:6px;font-weight:bold;';
        const status=document.createElement('p');status.setAttribute('role','status');status.style.cssText='margin:0 0 6px;';
        const frame=document.createElement('iframe');frame.title='Synthetic print design preview';frame.setAttribute('sandbox','');frame.style.cssText='width:100%;height:calc(100% - 85px);border:0;background:white;';
        frame.src='/pos/receipt-design-preview?'+query;
        close.addEventListener('click',()=>{dialog.close();dialog.remove();});dialog.addEventListener('cancel',()=>dialog.remove());
        save.addEventListener('click',async()=>{
            save.disabled=true;status.textContent='Saving design…';
            try{
                const response=await fetch('/pos/receipt-settings',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':form?.querySelector('[name="_token"]')?.value||''},body:JSON.stringify({[prefix]:{[key]:value}})});
                const data=await response.json();
                if(!response.ok||!data.success)throw Error('The design could not be saved. Your other settings have not changed.');
                status.textContent='Design saved. Other edits have not been saved.';close.textContent='Close preview';
            }catch(error){status.textContent=error.message||'The design could not be saved. Try again.';save.disabled=false;}
        });
        dialog.append(close,save,status,frame);document.body.append(dialog);dialog.showModal();
    });
})();
