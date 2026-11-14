@extends('fbr-pos.school-fees.layout')
@section('content')
<h1>{{ __($dayClose ? 'pos.school_cash_summary' : 'pos.school_sales_history') }}</h1>
<div class="notice">{{ __('pos.school_close_blocked') }} {{ __('pos.fbr_missing_school_route') }}</div>
<section class="card">
<form method="get"><label for="date">{{ __('pos.school_calendar_date') }}</label><input type="date" id="date" name="date" value="{{ $date }}" required><div class="actions"><button type="submit">{{ __('pos.school_view_report') }}</button></div></form>
<dl><dt>{{ __('pos.school_receipt_count') }}</dt><dd id="school-receipt-count">{{ $receiptCount }}</dd><dt>{{ __('pos.school_cash_total') }}</dt><dd id="school-cash-total" data-paisa="{{ $cashAmountPaisa }}">PKR {{ number_format($cashAmountPaisa / 100, 2) }}</dd></dl>
<p class="muted">{{ __('pos.school_calendar_preview') }}</p>
</section>
<section class="card"><div class="scroll"><table><thead><tr><th>{{ __('pos.school_receipt') }}</th><th>{{ __('pos.school_student_reference') }}</th><th>{{ __('pos.school_fee_head') }}</th><th>{{ __('pos.school_payment') }}</th><th>PKR</th></tr></thead><tbody>
@forelse($receipts as $receipt)<tr><td><a href="/fbr-pos/school-fees/{{ $receipt->id }}/receipt">SF-{{ $receipt->id }}</a></td><td>{{ $receipt->student_reference }}</td><td>{{ __('pos.school_head_'.$receipt->fee_head) }}</td><td>{{ __('pos.school_cash') }}</td><td>{{ number_format($receipt->amount_paisa / 100, 2) }}</td></tr>
@empty<tr><td colspan="5">{{ __('pos.school_no_receipts') }}</td></tr>@endforelse
</tbody></table></div>{{ $receipts->links() }}</section>
@endsection
