@extends('fbr-pos.school-fees.layout')
@section('content')
@if(!$dayClose)<p class="no-print"><a href="/fbr-pos/school-fees/legacy-retail">{{ __('pos.school_legacy_title') }}</a></p>@endif
@if($isOwner)<p class="no-print"><a href="{{ $dayClose ? '/fbr-pos/day-close' : '/fbr-pos/transactions' }}{{ $unassigned ? '' : '?unassigned=1' }}">{{ __($unassigned ? 'school.current_scope' : 'school.unassigned_history') }}</a></p>@endif
@if($unassigned)<p class="notice">{{ __('school.unassigned_readonly') }}</p>@endif
<h1>{{ __($dayClose ? 'pos.school_nav_close' : 'pos.school_nav_receipts') }}</h1>
@if($dayClose)<div class="notice">{{ __('pos.school_closing_policy') }} {{ __('school_audit.cash_only') }}</div>@endif
<section class="card">
<form method="get" class="no-print">@if($unassigned)<input type="hidden" name="unassigned" value="1">@endif<label for="date">{{ __('pos.school_calendar_date') }}</label><input type="date" id="date" name="date" value="{{ $date }}" @required($dayClose)>@if(!$dayClose)<label for="school-search">{{ __('school.search') }}</label><input id="school-search" name="search" maxlength="100" value="{{ request('search') }}"><p class="muted">{{ __('school.search_help') }}</p><label for="fee-period-filter">{{ __('school.period') }}</label><input type="month" id="fee-period-filter" name="fee_period" value="{{ request('fee_period') }}"><div class="grid"><label>{{ __('school_ledger.from') }}<input type="date" name="from" value="{{ request('from') }}"></label><label>{{ __('school_ledger.to') }}<input type="date" name="to" value="{{ request('to') }}"></label><label>{{ __('pos.school_payment') }}<select name="payment_method"><option value="">{{ __('school_audit.all') }}</option>@foreach(['cash','card','bank'] as $method)<option value="{{ $method }}" @selected(request('payment_method') === $method)>{{ __('school_print.'.$method) }}</option>@endforeach</select></label><label>{{ __('pos.school_fee_head') }}<select name="fee_head"><option value="">{{ __('school_audit.all') }}</option>@foreach($feeHeads as $head)<option value="{{ $head['code'] }}" @selected(request('fee_head') === $head['code'])>{{ $head['label'] }}</option>@endforeach</select></label><label>{{ __('school.cashier') }}<input name="cashier" maxlength="100" value="{{ request('cashier') }}"></label></div>@endif<div class="actions"><button type="submit">{{ __('pos.school_view_report') }}</button></div></form>
<dl><dt>{{ __('pos.school_receipt_count') }}</dt><dd id="school-receipt-count">{{ $receiptCount }}</dd><dt>{{ __('pos.school_cash_total') }}</dt><dd id="school-cash-total" data-paisa="{{ $cashAmountPaisa }}">PKR {{ number_format($cashAmountPaisa / 100, 2) }}</dd></dl>
@if($dayClose)
@if($closedReport)
<p id="school-close-status">{{ __('pos.school_closed') }}</p><p>SC-{{ $closedReport->id }} · {{ $closedReport->closed_at }}</p>
<dl><dt>{{ __('school.counted_total') }}</dt><dd id="school-counted-total" data-paisa="{{ $closedReport->counted_cash_paisa }}">{{ $closedReport->counted_cash_paisa === null ? __('school.not_recorded') : 'PKR '.number_format($closedReport->counted_cash_paisa / 100, 2) }}</dd><dt>{{ __('school.variance') }}</dt><dd id="school-variance" data-paisa="{{ $closedReport->variance_paisa }}">{{ $closedReport->variance_paisa === null ? __('school.not_recorded') : 'PKR '.number_format($closedReport->variance_paisa / 100, 2) }}</dd></dl>
<dl><dt>{{ __('school_ledger.variance_reason') }}</dt><dd>{{ $closedReport->variance_reason ?? __('school.not_recorded') }}</dd><dt>{{ __('school_ledger.handover_to') }}</dt><dd>{{ $closedReport->handover_to ?? __('school.not_recorded') }}</dd><dt>{{ __('school_ledger.handover_note') }}</dt><dd>{{ $closedReport->handover_note ?? __('school.not_recorded') }}</dd></dl>
<button class="no-print" type="button" onclick="window.print()">{{ __('pos.school_print_reprint') }}</button>
@else
<p id="school-close-status">{{ __('pos.school_open') }}</p>
@if($errors->any())<div role="alert" class="error">{{ $errors->first() }}</div>@endif
<form id="school-close-form" method="post" action="/fbr-pos/day-close" class="no-print">
@csrf
@if($unassigned)<input type="hidden" name="unassigned" value="1">@endif
<input type="hidden" name="expected_branch_key" value="{{ $unassigned ? 0 : ($schoolCampusId ?? 0) }}">
<input type="hidden" name="date" value="{{ $date }}"><input type="hidden" name="expected_cash_paisa" value="{{ $cashAmountPaisa }}"><input type="hidden" name="expected_receipt_count" value="{{ $receiptCount }}">
<label for="school-counted-cash">{{ __('school.counted_cash') }}</label><input id="school-counted-cash" name="counted_cash" type="number" min="0" max="99999999.99" step="0.01" inputmode="decimal" value="{{ old('counted_cash') }}" required><p class="muted">{{ __('school.count_help') }}</p>
<label>{{ __('school_ledger.variance_reason') }}<input name="variance_reason" maxlength="500" value="{{ old('variance_reason') }}"></label><label>{{ __('school_ledger.handover_to') }}<input name="handover_to" maxlength="100" value="{{ old('handover_to') }}"></label><label>{{ __('school_ledger.handover_note') }}<input name="handover_note" maxlength="500" value="{{ old('handover_note') }}"></label>
<label><input style="width:auto" type="checkbox" name="confirm" value="1" required> {{ __('pos.school_confirm_close') }}</label>
<button type="submit">{{ __('pos.school_confirm_close') }}</button>
</form>
@endif
@endif
<p class="muted">{{ __('pos.school_calendar_preview') }}</p>
</section>
<section class="card"><div class="scroll"><table><thead><tr><th>{{ __('pos.school_receipt') }}</th><th>{{ __('pos.school_student_reference') }}</th><th>{{ __('pos.school_fee_head') }}</th><th>{{ __('pos.school_payment') }}</th><th>{{ __('school_audit.reference') }}</th><th>PKR</th><th>{{ __('pos.school_collected_at') }}</th><th>{{ __('school.period') }}</th><th>{{ __('school.campus') }}</th><th>{{ __('school.cashier') }}</th></tr></thead><tbody>
@forelse($receipts as $receipt)<tr><td><a href="/fbr-pos/school-fees/{{ $receipt->id }}/receipt{{ $unassigned ? '?unassigned=1' : '' }}">SF-{{ $receipt->id }}</a></td><td>{{ $receipt->student_reference }}</td><td>{{ $receipt->fee_head_label ?? __('pos.school_head_'.$receipt->fee_head) }}</td><td>{{ ($receipt->payment_method ?? 'cash') === 'cash' ? __('pos.school_cash') : __('school_ledger.'.$receipt->payment_method) }}</td><td>{{ $receipt->payment_reference ?? __('school.not_recorded') }}</td><td>{{ number_format($receipt->amount_paisa / 100, 2) }}</td><td>{{ $receipt->collected_at }}</td><td>{{ $receipt->fee_period }}</td><td>{{ $receipt->campus_name }}</td><td>{{ $receipt->cashier_name }}</td></tr>
@empty<tr><td colspan="10">{{ __('pos.school_no_receipts') }}</td></tr>@endforelse
</tbody></table></div>{{ $receipts->links() }}</section>
@endsection
