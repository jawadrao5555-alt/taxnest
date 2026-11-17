@extends('fbr-pos.school-fees.layout', ['showInteractiveSupport' => false])
@section('content')
@php
    $print = \App\Support\SchoolReceiptPreferences::for($company);
    $paperWidth = match ($print['paper']) { '58mm' => '58mm', 'a4' => '180mm', default => '80mm' };
    $lines = is_array($receipt->fee_lines ?? null) ? $receipt->fee_lines : json_decode($receipt->fee_lines ?? '[]', true);
    if (!$lines) $lines = [['label' => $receipt->fee_head_label ?? __('pos.school_head_'.$receipt->fee_head), 'amount_paisa' => $receipt->amount_paisa]];
    $method = in_array($receipt->payment_method ?? 'cash', ['cash','card','bank'], true) ? ($receipt->payment_method ?? 'cash') : 'cash';
@endphp
<style>
.school-receipt{width:{{ $paperWidth }};max-width:100%;margin:18px auto;overflow-wrap:anywhere;color:#111}.school-receipt-logo{display:block;max-width:32mm;max-height:24mm;object-fit:contain;margin:0 auto 8px}.school-receipt h1,.school-receipt h2{font-size:19px}.school-receipt dl{font-size:13px;grid-template-columns:minmax(0,1fr) minmax(0,1fr)}.school-receipt table{table-layout:fixed;font-size:13px}.school-receipt td,.school-receipt th{padding:6px 2px}.school-receipt .total{font-size:22px}.school-receipt .receipt-brand{text-align:{{ $print['center'] ? 'center' : 'start' }}}
@media print{ @page{size:{{ $print['paper'] === 'a4' ? 'A4 portrait' : $print['paper'].' auto' }};margin:{{ $print['paper'] === 'a4' ? '15mm' : '0' }}} main{width:{{ $paperWidth }}!important;max-width:{{ $paperWidth }}!important;padding:{{ $print['margin_mm'] }}mm!important;margin:0 auto!important}main> :not(.school-receipt){display:none!important}.school-receipt{width:100%;margin:0;border:0;border-radius:0;box-shadow:none;padding:0}.school-receipt .notice{padding:5px;font-size:11px}.school-receipt h1,.school-receipt h2{font-size:16px}.school-receipt dl,.school-receipt table{font-size:11px}}
</style>
@if($preview ?? false)<div id="school-print-preview-notice" class="notice no-print">{{ __('school_print.preview_notice') }}</div>@endif
<article class="card school-receipt" data-paper="{{ $print['paper'] }}">
@if($preview ?? false)<p class="notice">{{ __('school_print.preview_notice') }}</p>@endif
<div class="receipt-brand">
@if($print['show_logo'] && $company->logo_path)<img data-school-logo class="school-receipt-logo" src="{{ asset('storage/'.$company->logo_path) }}" alt="{{ $company->name }}">@endif
<h1>{{ $company->name }}</h1>
@if($print['show_contact'])<div class="muted">@if($company->address)<div>{{ $company->address }}</div>@endif @if($company->phone)<div>{{ $company->phone }}</div>@endif @if($company->email)<div>{{ $company->email }}</div>@endif</div>@endif
<h2>{{ __('pos.school_receipt') }} · SF-{{ $receipt->id }}</h2>
</div>
@if(($receipt->fbr_pos_transaction_id ?? null) && ($receipt->fbr_status ?? null)==='submitted' && ($receipt->fbr_invoice_number ?? null) && ($receipt->fbr_qr_payload ?? null))
<div class="notice" id="school-fbr-accepted"><strong>FBR IMS Accepted</strong><br>{{ $receipt->fbr_invoice_number }}
@php $schoolFbrQr=\App\Support\QrImage::dataUri((string)$receipt->fbr_qr_payload,5,4); @endphp
@if($schoolFbrQr)<br><img src="{{ $schoolFbrQr }}" alt="FBR fiscal QR" style="width:34mm;height:34mm;margin:8px auto;display:block">@endif</div>
@elseif(in_array(($receipt->fbr_status ?? null),['pending','offline','verification_pending'],true))
<div class="notice" id="school-fbr-pending">FBR IMS: {{ str_replace('_',' ',ucfirst($receipt->fbr_status)) }}</div>
@elseif(!in_array(($receipt->fbr_status ?? null), ['failed', 'config_error'], true) && (json_decode($receipt->fiscal_route_snapshot ?? 'null', true)['route'] ?? null) !== 'ims')
<div class="notice">{{ __('pos.school_local_receipt') }}</div>
@endif
@include('fbr-pos.partials.collection-ims-retry', ['imsReceipt' => $receipt, 'imsRetryRoute' => 'fbrpos.school-fees.ims-retry'])
<dl><dt>{{ __('pos.school_student_reference') }}</dt><dd>{{ $receipt->student_reference }}@if($receipt->student_name ?? null)<br>{{ $receipt->student_name }}@endif</dd><dt>{{ __('pos.school_collected_at') }}</dt><dd>{{ $receipt->collected_at }}</dd><dt>{{ __('pos.school_payment') }}</dt><dd id="school-receipt-method">{{ __('school_print.'.$method) }}</dd>
<dt>{{ __('school_audit.reference') }}</dt><dd id="school-payment-reference">{{ $receipt->payment_reference ?? __('school.not_recorded') }}</dd>
<dt>{{ __('school.period') }}</dt><dd id="school-receipt-period">{{ $receipt->fee_period ?? __('school.not_recorded') }}</dd><dt>{{ __('school.campus') }}</dt><dd id="school-receipt-campus">{{ $receipt->campus_name ?? __('school.not_recorded') }}</dd><dt>{{ __('school.cashier') }}</dt><dd id="school-receipt-cashier">{{ $receipt->cashier_name ?? __('school.not_recorded') }}</dd></dl>
<table id="school-receipt-head"><thead><tr><th>{{ __('pos.school_fee_head') }}</th><th>{{ __('school_print.amount') }}</th></tr></thead><tbody>@foreach($lines as $line)<tr><td>{{ $line['label'] }}</td><td>PKR {{ number_format($line['amount_paisa']/100, 2) }}</td></tr>@endforeach</tbody></table>
<dl><dt>{{ __('school_print.payable') }}</dt><dd>PKR {{ number_format(($receipt->payable_paisa ?? $receipt->amount_paisa)/100, 2) }}</dd><dt>{{ __('school_print.paid') }}</dt><dd>PKR {{ number_format(($receipt->paid_paisa ?? $receipt->amount_paisa)/100, 2) }}</dd><dt>{{ __('school_print.balance') }}</dt><dd id="school-receipt-balance">PKR {{ number_format(($receipt->balance_paisa ?? 0)/100, 2) }}</dd></dl>
<div class="total">{{ __('school_print.this_payment') }}: PKR {{ number_format($receipt->amount_paisa / 100, 2) }}</div>
<p class="muted">{{ __('pos.school_no_tax_inference') }}</p>
<div class="actions no-print"><button type="button" id="school-print-receipt" onclick="window.print()">{{ __('pos.school_print_reprint') }}</button>@if(!($preview ?? false))<a href="/fbr-pos/school-fees">{{ __('pos.school_new_collection') }}</a>@else<a href="/fbr-pos/school-fees/printing">{{ __('school_print.settings') }}</a>@endif</div>
<p class="muted no-print">{{ __('school_print.browser_printer') }} @if($print['printer_label']){{ __('school_print.preferred') }}: {{ $print['printer_label'] }}@endif</p>
</article>
@if(!($preview ?? false))
<script>
(() => {
    const storageKey = @json('taxnest.school.pending.'.$company->id.'.'.($receipt->branch_id ?? 0).'.'.auth('fbrpos')->id());
    try {
        if (sessionStorage.getItem(storageKey) === @json($receipt->request_key)) sessionStorage.removeItem(storageKey);
    } catch (_) { /* Storage failure never modifies the saved receipt. */ }
})();
</script>
@endif
@endsection
