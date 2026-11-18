<script>
(() => {
 const form=document.getElementById(@json($formId)); const button=form.querySelector('button[type="submit"]');
 const key=form.querySelector('[name="request_key"]');const notice=document.getElementById('hospital-recovery');
 const storageKey=@json('taxnest.hospital.pending.'.$company->id.'.'.($hospitalCampusId??0).'.'.auth('fbrpos')->id().'.'.$invoiceKey);
 const message=@json(__('hospital.recovery'));let pending=null,ready=true,busy=false;
 const show=s=>{notice.textContent=s;notice.hidden=false;};
 try{pending=sessionStorage.getItem(storageKey);if(pending){if(!/^[0-9a-f-]{36}$/i.test(pending))throw Error();key.value=pending;show(message);}}catch(_){ready=false;show(@json(__('hospital.storage')));}
 const controls=()=>{button.disabled=!ready||busy||(form.dataset.closed==='1'&&!pending);}; controls();
 window.addEventListener('pageshow',event=>{
  if(event.persisted&&!busy){
   try{pending=sessionStorage.getItem(storageKey);key.value=pending||crypto.randomUUID();if(!pending)notice.hidden=true;}
   catch(_){ready=false;show(@json(__('hospital.storage')));}
  }
  controls();
 });
 form.addEventListener('submit',async e=>{e.preventDefault();if(button.disabled)return;
 try{sessionStorage.setItem(storageKey,key.value);if(sessionStorage.getItem(storageKey)!==key.value)throw Error();pending=key.value;}catch(_){ready=false;show(@json(__('hospital.storage')));controls();return;}
 busy=true;controls();const abort=new AbortController();const timer=setTimeout(()=>abort.abort(),30000);
 try{const response=await fetch(form.action,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body:new FormData(form),signal:abort.signal});const result=await response.json();
 if(response.ok&&result.success&&/^\/fbr-pos\/hospital\/receipts\/\d+(?:\?unassigned=1)?$/.test(result.receipt_url)){window.location.assign(result.receipt_url);return;}
 show(Object.values(result.errors||{}).flat().join(' ')+' '+(result.message||'')+' '+message);
 }catch(_){show(message);}finally{clearTimeout(timer);busy=false;controls();}
 });
})();
</script>
