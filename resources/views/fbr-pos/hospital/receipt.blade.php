@extends('fbr-pos.hospital.layout', ['showInteractiveSupport' => false])
@section('content')
@php
$print = \App\Support\HospitalReceiptPreferences::for($company);
$lines = is_array($invoice->service_lines ?? null) ? $invoice->service_lines : json_decode($invoice->service_lines ?? '[]', true);
$paperWidth = match ($print['paper']) { '58mm'=>'58mm', 'a4'=>'180mm', default=>'80mm' };
@endphp
<style>
.hospital-receipt{width:{{ $paperWidth }};max-width:100%;margin:18px auto;overflow-wrap:anywhere;color:#111}.hospital-receipt-logo{display:block;max-width:32mm;max-height:24mm;object-fit:contain;margin:0 auto 8px}.hospital-receipt h1,.hospital-receipt h2{font-size:19px}.hospital-receipt dl{font-size:13px;grid-template-columns:minmax(0,1fr) minmax(0,1fr)}.hospital-receipt .receipt-brand{text-align:{{ $print['center'] ? 'center':'start' }}}
@media print{@page{size:{{ $print['paper'] === 'a4' ? 'A4 portrait':$print['paper'].' auto' }};margin:{{ $print['paper'] === 'a4' ? '15mm':'0' }}}main{width:{{ $paperWidth }}!important;max-width:{{ $paperWidth }}!important;padding:{{ $print['margin_mm'] }}mm!important;margin:0 auto!important}main>:not(.hospital-receipt){display:none!important}.hospital-receipt{width:100%;margin:0;border:0;border-radius:0;box-shadow:none;padding:0}.hospital-receipt .notice{padding:5px;font-size:11px}.hospital-receipt h1,.hospital-receipt h2{font-size:16px}.hospital-receipt dl{font-size:11px}}
</style>
<article class="card hospital-receipt" data-paper="{{ $print['paper'] }}">
@if($preview ?? false)<p id="hospital-print-preview-notice" class="notice">{{ __('hospital_print.preview_notice') }}</p>@endif
<div class="receipt-brand">
@if($print['show_logo'] && $company->logo_path)<img data-hospital-logo class="hospital-receipt-logo" src="{{ asset('storage/'.$company->logo_path) }}" alt="{{ $company->name }}">@endif
<h1>{{ $company->name }}</h1>
@if($print['show_contact'])<div class="muted">@if($company->address)<div>{{ $company->address }}</div>@endif @if($company->phone)<div>{{ $company->phone }}</div>@endif @if($company->email)<div>{{ $company->email }}</div>@endif</div>@endif
<h2>{{ __('hospital.receipt') }} HP-{{ $payment->id }}</h2><p>HI-{{ $invoice->id }} · {{ $payment->collected_at }}</p>
</div>
@if(($payment->fbr_status ?? null)==='submitted' && !empty($payment->fbr_invoice_number))
<p class="notice" id="hospital-fiscal-accepted"><strong>FBR IMS Accepted</strong><br>FBR Invoice: {{ $payment->fbr_invoice_number }}</p>
@php $hospitalFbrQr=\App\Support\QrImage::dataUri((string)($payment->fbr_qr_payload ?: $payment->fbr_invoice_number),5,4); @endphp
@if($hospitalFbrQr)<div class="receipt-brand"><img alt="FBR verification QR" style="width:34mm;height:34mm" src="{{ $hospitalFbrQr }}"></div>@endif
@elseif(in_array(($payment->fbr_status ?? null),['pending','offline','verification_pending'],true))
<p class="notice" id="hospital-fiscal-pending">FBR IMS: {{ $payment->fbr_status }}</p>
@elseif(!in_array(($payment->fbr_status ?? null), ['failed', 'config_error'], true) && (json_decode($payment->fiscal_route_snapshot ?? 'null', true)['route'] ?? null) !== 'ims')
<p class="notice">{{ __('hospital_print.local_only') }}</p>
@endif
@include('fbr-pos.partials.collection-ims-retry', ['imsReceipt' => $payment, 'imsRetryRoute' => 'fbrpos.hospital.ims-retry'])
<dl><dt>{{ __('hospital.patient') }}</dt><dd>{{ $invoice->patient_reference }}@if($invoice->patient_name ?? null)<br>{{ $invoice->patient_name }}@endif</dd><dt>{{ __('hospital.service') }}</dt><dd>@if($lines)@foreach($lines as $line)<div>{{ $line['label'] }} · PKR {{ number_format($line['amount_paisa']/100,2) }}</div>@endforeach @else{{ __('hospital.'.$invoice->service_head) }}@endif</dd><dt>{{ __('hospital.method') }}</dt><dd>{{ __('hospital_print.'.$payment->payment_method) }}@if($payment->payment_reference ?? null)<br>{{ $payment->payment_reference }}@endif</dd><dt>{{ __('hospital.campus') }}</dt><dd>{{ $payment->campus_name }}</dd><dt>{{ __('hospital.cashier') }}</dt><dd>{{ $payment->cashier_name }}</dd><dt>{{ __('hospital.total') }}</dt><dd>PKR {{ number_format($invoice->total_paisa/100,2) }}</dd><dt>{{ __('hospital.amount') }}</dt><dd id="hospital-payment-amount" data-paisa="{{ $payment->amount_paisa }}">PKR {{ number_format($payment->amount_paisa/100,2) }}</dd><dt>{{ __('hospital.receipt_balance') }}</dt><dd id="hospital-receipt-balance" data-paisa="{{ $payment->balance_paisa }}">PKR {{ number_format($payment->balance_paisa/100,2) }}</dd></dl>
<div class="actions no-print"><button type="button" id="hospital-print-receipt" onclick="window.print()">{{ __('hospital.print') }}</button>@if($preview ?? false)<a href="/fbr-pos/hospital/printing">{{ __('hospital_print.settings') }}</a>@else<a href="/fbr-pos/hospital/invoices/{{ $invoice->id }}{{ !empty($hospitalUnassigned) ? '?unassigned=1' : '' }}">{{ __('hospital.invoice') }} HI-{{ $invoice->id }}</a>@endif</div>
<p class="muted no-print">{{ __('hospital_print.browser_printer') }} @if($print['printer_label']){{ __('hospital_print.preferred') }}: {{ $print['printer_label'] }}@endif</p>
</article>
@if(!($preview ?? false))
<script>(()=>{const key=@json('taxnest.hospital.pending.'.$company->id.'.'.($payment->branch_id??0).'.'.auth('fbrpos')->id().'.'.($payment->request_key===$invoice->request_key?'new':$invoice->id));try{if(sessionStorage.getItem(key)===@json($payment->request_key))sessionStorage.removeItem(key);}catch(_){}})();</script>
@endif
@include('fbr-pos.partials.collection-ims-auto-update', ['imsReceipt' => $payment])
@endsection
