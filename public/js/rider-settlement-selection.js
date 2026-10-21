(function(root){
  'use strict';
  function boxes(form){return [...form.querySelectorAll('input[name="bill_ids[]"]')].filter(box=>!box.disabled);}
  function selection(form){const eligible=boxes(form);return{count:eligible.length,selected:eligible.filter(box=>box.checked).length};}
  function selectAll(form,checked){boxes(form).forEach(box=>{box.checked=!!checked;});}
  function confirmSelection(form,amount,total,pending,confirm,message){
    const state=selection(form);amount=Number(amount);total=Number(total);
    if(pending||!state.selected||!Number.isFinite(amount)||!Number.isFinite(total)||amount<=0||amount>total+0.009)return false;
    return confirm(message.replaceAll(':count',String(state.selected)).replaceAll(':amount',amount.toLocaleString(undefined,{maximumFractionDigits:2})))===true;
  }
  const api={selection,selectAll,confirmSelection};
  if(typeof module==='object'&&module.exports)module.exports=api;else root.TnRiderSettlement=api;
})(typeof window!=='undefined'?window:globalThis);
