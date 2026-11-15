@extends('fbr-pos.hospital.layout')
@section('content')
<h1>{{ __('hospital.invoice') }} HI-{{ $invoice->id }}</h1>
<section class="card"><dl><dt>{{ __('hospital.patient') }}</dt><dd>{{ $invoice->patient_reference }} {{ $invoice->patient_name }}</dd><dt>{{ __('hospital.service') }}</dt><dd>{{ $invoice->service_lines ? implode(' + ', array_column(json_decode($invoice->service_lines,true),'label')) : __('hospital.'.$invoice->service_head) }}</dd><dt>{{ __('hospital.total') }}</dt><dd>PKR {{ number_format($invoice->total_paisa/100,2) }}</dd><dt>{{ __('hospital.balance') }}</dt><dd id="hospital-balance" data-paisa="{{ $balance }}">PKR {{ number_format($balance/100,2) }}</dd></dl>
@foreach($payments as $payment)<p><a href="/fbr-pos/hospital/receipts/{{ $payment->id }}{{ !empty($hospitalUnassigned) ? '?unassigned=1' : '' }}">HP-{{ $payment->id }}</a> · {{ __('hospital.'.$payment->payment_method) }} · PKR {{ number_format($payment->amount_paisa/100,2) }}</p>@endforeach
</section>
<p id="hospital-settlement-status">{{ __($balance === 0 ? 'hospital_ledger.settled' : 'hospital_ledger.partial') }}</p>
<section class="card"><h2>{{ __('hospital.more') }}</h2>
<form id="hospital-payment" method="post" action="/fbr-pos/hospital/invoices/{{ $invoice->id }}/payments" data-closed="{{ ($closed || $balance === 0) ? 1 : 0 }}">
@csrf<input type="hidden" name="unassigned" value="{{ !empty($hospitalUnassigned) ? 1 : 0 }}"><input type="hidden" name="request_key" value="{{ $requestKey }}"><input type="hidden" name="expected_branch_key" value="{{ $hospitalCampusId ?? 0 }}">@include('fbr-pos.hospital.payment-fields')
</form></section>
@include('fbr-pos.hospital.payment-script',['formId'=>'hospital-payment','invoiceKey'=>$invoice->id])
@endsection
