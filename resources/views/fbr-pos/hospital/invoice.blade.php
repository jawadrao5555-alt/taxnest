@extends('fbr-pos.hospital.layout')
@section('content')
@if(session('hospital_correction'))<p role="status" class="notice">{{ session('hospital_correction') }}</p>@endif
<h1>{{ __('hospital.invoice') }} HI-{{ $invoice->id }}</h1>
<section class="card"><dl><dt>{{ __('hospital.patient') }}</dt><dd>{{ str_starts_with((string) $invoice->patient_reference, 'Walk-in-') ? 'Walk-in' : $invoice->patient_reference }} {{ $invoice->patient_name }}</dd><dt>{{ __('hospital.service') }}</dt><dd>{{ $invoice->service_lines ? implode(' + ', array_column(json_decode($invoice->service_lines,true),'label')) : __('hospital.'.$invoice->service_head) }}</dd><dt>{{ __('hospital.total') }}</dt><dd>PKR {{ number_format($invoice->total_paisa/100,2) }}</dd><dt>{{ __('hospital.balance') }}</dt><dd id="hospital-balance" data-paisa="{{ $balance }}">PKR {{ number_format($balance/100,2) }}</dd></dl>
@foreach($payments as $payment)<p><a href="/fbr-pos/hospital/receipts/{{ $payment->id }}{{ !empty($hospitalUnassigned) ? '?unassigned=1' : '' }}">HP-{{ $payment->id }}</a> · {{ __('hospital.'.$payment->payment_method) }} · PKR {{ number_format($payment->amount_paisa/100,2) }}</p>@endforeach
</section>
<p id="hospital-settlement-status">{{ __($balance === 0 ? 'hospital_ledger.settled' : 'hospital_ledger.partial') }}</p>
<section class="card"><h2>{{ __('hospital.more') }}</h2>
<form id="hospital-payment" method="post" action="/fbr-pos/hospital/invoices/{{ $invoice->id }}/payments" data-closed="{{ ($closed || $balance === 0) ? 1 : 0 }}">
@csrf<input type="hidden" name="unassigned" value="{{ !empty($hospitalUnassigned) ? 1 : 0 }}"><input type="hidden" name="request_key" value="{{ $requestKey }}"><input type="hidden" name="expected_branch_key" value="{{ $hospitalCampusId ?? 0 }}">@include('fbr-pos.hospital.payment-fields')
</form></section>
@if($canCorrect ?? false)
<section class="card">
    <h2>Correct local invoice</h2>
    <form method="POST" action="{{ route('fbrpos.hospital.invoice.correct', $invoice->id) }}">
        @csrf
        <input type="hidden" name="unassigned" value="{{ !empty($hospitalUnassigned) ? 1 : 0 }}">
        <input type="hidden" name="expected_branch_key" value="{{ $hospitalCampusId ?? 0 }}">
        <label>Patient name (optional)<input name="patient_name" maxlength="100" value="{{ old('patient_name', $invoice->patient_name) }}"></label>
        <label>Invoice total (PKR)<input name="total_amount" inputmode="decimal" value="{{ old('total_amount', number_format($invoice->total_paisa / 100, 2, '.', '')) }}"></label>
        @if($payments->count() === 1)
            <label>Payment amount (PKR)<input name="payment_amount" inputmode="decimal" value="{{ old('payment_amount', number_format($payments->first()->amount_paisa / 100, 2, '.', '')) }}"></label>
        @endif
        <label>Reason for correction<input required name="reason" maxlength="300"></label>
        @if($errors->any())<p role="alert" class="error">{{ $errors->first() }}</p>@endif
        <button name="action" value="edit">Save changes</button>
        <button name="action" value="void" onclick="return confirm('Void this local invoice and its payments?')">Void local invoice</button>
    </form>
</section>
@endif
@include('fbr-pos.hospital.payment-script',['formId'=>'hospital-payment','invoiceKey'=>$invoice->id])
@endsection
