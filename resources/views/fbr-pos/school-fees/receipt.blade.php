@extends('fbr-pos.school-fees.layout')
@section('content')
<article class="card">
<h1>{{ $company->name }}</h1>
<h2>{{ __('pos.school_receipt') }} · SF-{{ $receipt->id }}</h2>
<div class="notice">{{ __('pos.school_local_receipt') }}</div>
<dl><dt>{{ __('pos.school_student_reference') }}</dt><dd>{{ $receipt->student_reference }}</dd><dt>{{ __('pos.school_fee_head') }}</dt><dd>{{ __('pos.school_head_'.$receipt->fee_head) }}</dd><dt>{{ __('pos.school_collected_at') }}</dt><dd>{{ $receipt->collected_at }}</dd><dt>{{ __('pos.school_payment') }}</dt><dd>{{ __('pos.school_cash') }}</dd></dl>
<div class="total">PKR {{ number_format($receipt->amount_paisa / 100, 2) }}</div>
<p class="muted">{{ __('pos.school_no_tax_inference') }}</p>
<div class="actions no-print"><button type="button" onclick="window.print()">{{ __('pos.school_print_reprint') }}</button><a href="/fbr-pos/school-fees">{{ __('pos.school_new_collection') }}</a></div>
</article>
@endsection
