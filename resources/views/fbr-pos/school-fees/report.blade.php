@extends('fbr-pos.school-fees.layout')
@section('content')
<h1>{{ __($dayClose ? 'pos.school_nav_close' : 'pos.school_nav_receipts') }}</h1>
<div class="notice">{{ __('pos.school_closing_policy') }} {{ __('pos.fbr_missing_school_route') }}</div>
<section class="card">
<form method="get" class="no-print"><label for="date">{{ __('pos.school_calendar_date') }}</label><input type="date" id="date" name="date" value="{{ $date }}" required><div class="actions"><button type="submit">{{ __('pos.school_view_report') }}</button></div></form>
<dl><dt>{{ __('pos.school_receipt_count') }}</dt><dd id="school-receipt-count">{{ $receiptCount }}</dd><dt>{{ __('pos.school_cash_total') }}</dt><dd id="school-cash-total" data-paisa="{{ $cashAmountPaisa }}">PKR {{ number_format($cashAmountPaisa / 100, 2) }}</dd></dl>
@if($dayClose)
@if($closedReport)
<p id="school-close-status">{{ __('pos.school_closed') }}</p><p>SC-{{ $closedReport->id }} · {{ $closedReport->closed_at }}</p>
<button class="no-print" type="button" onclick="window.print()">{{ __('pos.school_print_reprint') }}</button>
@else
<p id="school-close-status">{{ __('pos.school_open') }}</p>
@if($errors->any())<div role="alert" class="error">{{ $errors->first() }}</div>@endif
<form id="school-close-form" method="post" action="/fbr-pos/day-close" class="no-print">
@csrf
<input type="hidden" name="date" value="{{ $date }}"><input type="hidden" name="expected_cash_paisa" value="{{ $cashAmountPaisa }}"><input type="hidden" name="expected_receipt_count" value="{{ $receiptCount }}">
<label><input style="width:auto" type="checkbox" name="confirm" value="1" required> {{ __('pos.school_confirm_close') }}</label>
<button type="submit">{{ __('pos.school_confirm_close') }}</button>
</form>
@endif
@endif
<p class="muted">{{ __('pos.school_calendar_preview') }}</p>
</section>
<section class="card"><div class="scroll"><table><thead><tr><th>{{ __('pos.school_receipt') }}</th><th>{{ __('pos.school_student_reference') }}</th><th>{{ __('pos.school_fee_head') }}</th><th>{{ __('pos.school_payment') }}</th><th>PKR</th></tr></thead><tbody>
@forelse($receipts as $receipt)<tr><td><a href="/fbr-pos/school-fees/{{ $receipt->id }}/receipt">SF-{{ $receipt->id }}</a></td><td>{{ $receipt->student_reference }}</td><td>{{ __('pos.school_head_'.$receipt->fee_head) }}</td><td>{{ __('pos.school_cash') }}</td><td>{{ number_format($receipt->amount_paisa / 100, 2) }}</td></tr>
@empty<tr><td colspan="5">{{ __('pos.school_no_receipts') }}</td></tr>@endforelse
</tbody></table></div>{{ $receipts->links() }}</section>
@endsection
