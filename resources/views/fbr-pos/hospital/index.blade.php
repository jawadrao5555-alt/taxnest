@extends('fbr-pos.hospital.layout')
@section('content')
<h1>{{ __('hospital.collect') }}</h1><p>{{ __('hospital.scope') }}</p>
<section class="card">
<form id="hospital-collection" method="post" action="/fbr-pos/hospital" data-closed="{{ $closed ? 1 : 0 }}">
@csrf<input type="hidden" name="request_key" value="{{ $requestKey }}"><input type="hidden" name="expected_branch_key" value="{{ $hospitalCampusId ?? 0 }}">
<label for="patient_reference">{{ __('hospital.patient') }}</label><input id="patient_reference" name="patient_reference" maxlength="100" required autocomplete="off">
<label for="service_head">{{ __('hospital.service') }}</label><select id="service_head" name="service_head">@foreach(['consultation','lab','procedure'] as $head)<option value="{{ $head }}">{{ __('hospital.'.$head) }}</option>@endforeach</select>
<label for="total_amount">{{ __('hospital.total') }} (PKR)</label><input id="total_amount" name="total_amount" type="number" min="0.01" max="99999999.99" step="0.01" required>
@include('fbr-pos.hospital.payment-fields')
</form></section>
@include('fbr-pos.hospital.payment-script',['formId'=>'hospital-collection','invoiceKey'=>'new'])
@endsection
