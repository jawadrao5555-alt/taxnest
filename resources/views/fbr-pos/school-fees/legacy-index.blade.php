@extends('fbr-pos.school-fees.layout')
@section('content')
<h1>{{ __('pos.school_legacy_title') }}</h1>
<p class="notice">{{ __('pos.school_legacy_notice') }}</p>
<p><a href="/fbr-pos/transactions">{{ __('pos.school_nav_receipts') }}</a></p>
@if($isOwner)<p class="no-print"><a href="/fbr-pos/school-fees/legacy-retail{{ $unassigned ? '' : '?unassigned=1' }}">{{ __($unassigned ? 'pos.school_legacy_current' : 'pos.school_legacy_unassigned') }}</a></p>@endif
@if($unassigned)<p>{{ __('pos.school_legacy_unassigned') }}</p>@endif
@if(!$localAllowed)
<section class="card no-print"><p>{{ __('pos.school_legacy_locked') }}</p>
@if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
<form method="post" action="/fbr-pos/school-fees/legacy-retail/unlock">@csrf<label for="legacy-pin">{{ __('pos.school_legacy_pin') }}</label><input type="password" id="legacy-pin" name="pin" maxlength="32" required autocomplete="off"><button type="submit">{{ __('pos.school_legacy_unlock') }}</button></form></section>
@endif
<section class="card"><form method="get" class="no-print"><input type="hidden" name="unassigned" value="{{ $unassigned ? 1 : 0 }}"><label for="legacy-search">{{ __('pos.school_legacy_search') }}</label><input id="legacy-search" name="search" maxlength="100" value="{{ request('search') }}"><label for="legacy-from">{{ __('school_ledger.from') }}</label><input type="date" id="legacy-from" name="from" value="{{ request('from') }}"><label for="legacy-to">{{ __('school_ledger.to') }}</label><input type="date" id="legacy-to" name="to" value="{{ request('to') }}"><button type="submit">{{ __('pos.search') }}</button></form>
<section id="school-retail-archive-summary"><h2>{{ __('school_ledger.archive_summary') }}</h2><p>{{ __('school_ledger.archive_summary_help') }}</p><dl><dt>{{ __('pos.school_receipt_count') }}</dt><dd>{{ $archiveSummary['count'] }}</dd><dt>{{ __('school_ledger.stored_total') }}</dt><dd>PKR {{ number_format($archiveSummary['total'],2) }}</dd><dt>{{ __('school_ledger.stored_tax') }}</dt><dd>PKR {{ number_format($archiveSummary['tax'],2) }}</dd></dl></section><div class="scroll"><table><thead><tr><th>{{ __('pos.school_legacy_invoice') }}</th><th>{{ __('pos.school_calendar_date') }}</th><th>{{ __('pos.school_legacy_mode') }}</th><th>PKR</th></tr></thead><tbody>
@forelse($transactions as $transaction)
<tr><td><a href="/fbr-pos/school-fees/legacy-retail/{{ $transaction->id }}{{ $unassigned ? '?unassigned=1' : '' }}">{{ $transaction->invoice_number }}</a></td><td>{{ $transaction->created_at }}</td><td>{{ $transaction->invoice_mode }}</td><td>{{ $transaction->total_amount }}</td></tr>
@empty<tr><td colspan="4">{{ __('pos.school_legacy_empty') }}</td></tr>@endforelse
</tbody></table></div>{{ $transactions->links() }}</section>
@endsection
