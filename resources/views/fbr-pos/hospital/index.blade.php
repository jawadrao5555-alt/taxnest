@extends('fbr-pos.hospital.layout')
@section('content')
<h1>{{ __('hospital.collect') }}</h1><p>{{ __('hospital.scope') }}</p>
@if($cutover ?? false)
<section class="card" role="status">
    <p>{{ __('hospital.erp_cutover_new_collection') }}</p>
    <a href="/fbr-pos/hospital/history">{{ __('hospital.history') }}</a>
</section>
@else
<section class="card">
<form id="hospital-collection" method="post" action="/fbr-pos/hospital" data-closed="{{ $closed ? 1 : 0 }}">
@csrf<input type="hidden" name="request_key" value="{{ $requestKey }}"><input type="hidden" name="expected_branch_key" value="{{ $hospitalCampusId ?? 0 }}">
<label for="patient_reference">{{ __('hospital.patient') }}</label><input id="patient_reference" name="patient_reference" placeholder="Optional — walk-in" list="hospital-patient-lookup" maxlength="100" autocomplete="off">
<datalist id="hospital-patient-lookup">@foreach($patients as $patient)<option value="{{ $patient->patient_reference }}">{{ $patient->patient_name }}</option>@endforeach</datalist>
<label for="patient_name">{{ __('hospital_ledger.patient_name') }}</label><input id="patient_name" name="patient_name" maxlength="100">
<label for="service_head">{{ __('hospital.service') }}</label><select id="service_head" name="service_head">@foreach(['consultation','lab','procedure'] as $head)<option value="{{ $head }}">{{ __('hospital.'.$head) }}</option>@endforeach</select>
<label for="total_amount">{{ __('hospital.total') }} (PKR)</label><input id="total_amount" name="total_amount" type="number" min="0.01" max="99999999.99" step="0.01" required>
<fieldset><legend>{{ __('hospital_ledger.service_lines') }}</legend><label><input id="hospital-multi-mode" type="checkbox" style="width:auto"> {{ __('hospital_ledger.itemized') }}</label><div id="hospital-service-editor" hidden><div id="hospital-services"></div><button id="hospital-add-service" type="button">{{ __('hospital_ledger.add_service') }}</button></div><p>{{ __('hospital_ledger.scope') }}</p></fieldset>
@include('fbr-pos.hospital.payment-fields')
</form></section>
<script>
(() => {
 const catalog = @json($services); const heads = ['consultation','lab','procedure'];
 const labels = @json(collect(['consultation','lab','procedure'])->mapWithKeys(fn($h)=>[$h=>__('hospital.'.$h)]));
 const mode = document.getElementById('hospital-multi-mode'), rows = document.getElementById('hospital-services'), total = document.getElementById('total_amount');
 const recalc = () => { if (mode.checked) total.value = [...rows.querySelectorAll('input')].reduce((sum,input)=>sum+Math.round(Number(input.value||0)*100),0)/100; };
 const names = () => [...rows.children].forEach((row,i)=>{row.querySelector('select').name=`service_lines[${i}][service_head]`;row.querySelector('input').name=`service_lines[${i}][amount]`;});
 const add = () => {
  const row=document.createElement('div'); row.className='grid hospital-service-line';const select=document.createElement('select');
  heads.forEach(h=>select.add(new Option(labels[h],h)));catalog.forEach(s=>select.add(new Option(s.label,s.code)));
  const amount=document.createElement('input');amount.type='number';amount.min='0.01';amount.max='99999999.99';amount.step='0.01';amount.required=true;
  select.onchange=()=>{const service=catalog.find(s=>s.code===select.value);if(service)amount.value=(service.rate_paisa/100).toFixed(2);recalc();}; amount.oninput=recalc;
  const remove=document.createElement('button');remove.type='button';remove.textContent=@json(__('hospital_ledger.remove'));remove.onclick=()=>{row.remove();names();recalc();};
  row.append(select,amount,remove);rows.append(row);names();recalc();
 };
 mode.onchange=()=>{document.getElementById('hospital-service-editor').hidden=!mode.checked;if(mode.checked&&!rows.children.length)add();[...rows.querySelectorAll('input,select')].forEach(e=>e.disabled=!mode.checked);total.readOnly=mode.checked;recalc();};
 document.getElementById('hospital-add-service').onclick=()=>{if(rows.children.length<20)add();};
})();
</script>
@include('fbr-pos.hospital.payment-script' ,['formId'=>'hospital-collection','invoiceKey'=>'new'])
@endif
@endsection
