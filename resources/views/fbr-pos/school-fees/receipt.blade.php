@extends('fbr-pos.school-fees.layout')
@section('content')
<article class="card">
<h1>{{ $company->name }}</h1>
<h2>{{ __('pos.school_receipt') }} · SF-{{ $receipt->id }}</h2>
<div class="notice">{{ __('pos.school_local_receipt') }}</div>
<dl><dt>{{ __('pos.school_student_reference') }}</dt><dd>{{ $receipt->student_reference }}</dd><dt>{{ __('pos.school_fee_head') }}</dt><dd id="school-receipt-head">{{ $receipt->fee_head_label ?? __('pos.school_head_'.$receipt->fee_head) }}</dd><dt>{{ __('pos.school_collected_at') }}</dt><dd>{{ $receipt->collected_at }}</dd><dt>{{ __('pos.school_payment') }}</dt><dd>{{ __('pos.school_cash') }}</dd></dl>
<dl><dt>{{ __('school.period') }}</dt><dd id="school-receipt-period">{{ $receipt->fee_period ?? __('school.not_recorded') }}</dd><dt>{{ __('school.campus') }}</dt><dd id="school-receipt-campus">{{ $receipt->campus_name ?? __('school.not_recorded') }}</dd><dt>{{ __('school.cashier') }}</dt><dd id="school-receipt-cashier">{{ $receipt->cashier_name ?? __('school.not_recorded') }}</dd></dl>
<div class="total">PKR {{ number_format($receipt->amount_paisa / 100, 2) }}</div>
<p class="muted">{{ __('pos.school_no_tax_inference') }}</p>
<div class="actions no-print"><button type="button" onclick="window.print()">{{ __('pos.school_print_reprint') }}</button><a href="/fbr-pos/school-fees">{{ __('pos.school_new_collection') }}</a></div>
</article>
<script>
(() => {
    const storageKey = @json('taxnest.school.pending.'.$company->id.'.'.($receipt->branch_id ?? 0).'.'.auth('fbrpos')->id());
    try {
        if (sessionStorage.getItem(storageKey) === @json($receipt->request_key)) sessionStorage.removeItem(storageKey);
    } catch (_) { /* Storage failure never modifies the saved receipt. */ }
})();
</script>
@endsection
