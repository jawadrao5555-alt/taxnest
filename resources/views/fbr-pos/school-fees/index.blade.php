@extends('fbr-pos.school-fees.layout')
@section('content')
<h1>{{ __('pos.school_fee_desk') }}</h1>
<p class="muted">{{ __('pos.school_pilot_scope') }}</p>
<div class="notice">{{ __('pos.fbr_missing_school_route') }} {{ __('pos.school_separate_ledger') }}</div>
<section class="card">
    <h2>{{ __('pos.school_collect_cash') }}</h2>
    @if($errors->any())<div role="alert" class="error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="post" action="/fbr-pos/school-fees" id="school-collection">
        @csrf
        <input type="hidden" name="request_key" value="{{ old('request_key', $requestKey) }}">
        <input type="hidden" name="payment_method" value="cash">
        <div class="grid">
            <div><label for="student_reference">{{ __('pos.school_student_reference') }}</label><input id="student_reference" name="student_reference" value="{{ old('student_reference') }}" maxlength="100" required autocomplete="off"><div class="muted">{{ __('pos.school_reference_hint') }}</div></div>
            <div><label for="fee_head">{{ __('pos.school_fee_head') }}</label><select id="fee_head" name="fee_head" required>@foreach(['tuition','admission','exam'] as $head)<option value="{{ $head }}" @selected(old('fee_head', 'tuition') === $head)>{{ __('pos.school_head_'.$head) }}</option>@endforeach</select></div>
            <div><label for="amount">{{ __('pos.school_amount') }} (PKR)</label><input id="amount" name="amount" type="number" min="0.01" max="99999999.99" step="0.01" value="{{ old('amount') }}" required inputmode="decimal"></div>
        </div>
        <div class="actions"><button type="submit">{{ __('pos.school_collect_cash') }}</button><span class="muted">{{ __('pos.school_cash_only') }}</span></div>
    </form>
</section>
<section class="card"><h2>{{ __('pos.school_receipt_history') }}</h2><div class="scroll">
<table><thead><tr><th>{{ __('pos.school_receipt') }}</th><th>{{ __('pos.school_student_reference') }}</th><th>{{ __('pos.school_fee_head') }}</th><th>PKR</th><th>{{ __('pos.school_collected_at') }}</th></tr></thead><tbody>
@forelse($receipts as $receipt)
<tr><td><a href="/fbr-pos/school-fees/{{ $receipt->id }}/receipt">SF-{{ $receipt->id }}</a></td><td>{{ $receipt->student_reference }}</td><td>{{ __('pos.school_head_'.$receipt->fee_head) }}</td><td>{{ number_format($receipt->amount_paisa / 100, 2) }}</td><td>{{ $receipt->collected_at }}</td></tr>
@empty<tr><td colspan="5">{{ __('pos.school_no_receipts') }}</td></tr>@endforelse
</tbody></table></div>{{ $receipts->links() }}</section>
<script>
const form = document.getElementById('school-collection');
form.addEventListener('submit', () => { form.querySelector('button').disabled = true; });
window.addEventListener('pageshow', () => { form.querySelector('button').disabled = false; });
</script>
@endsection
