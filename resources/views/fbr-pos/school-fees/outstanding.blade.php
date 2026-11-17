@extends('fbr-pos.school-fees.layout')
@section('content')
<h1>{{ __('school_audit.outstanding_fees') }}</h1>
<form class="card" method="get" action="{{ route('fbrpos.school-fees.outstanding') }}">
    <label for="outstanding-search">{{ __('school_audit.reference_search') }}</label>
    <input id="outstanding-search" name="search" value="{{ $search }}" placeholder="Student name or ID">
    <div class="actions"><button type="submit">{{ __('pos.search') }}</button></div>
</form>
<section class="card scroll">
    <div class="metrics"><div><span class="muted">Visible outstanding</span><div class="metric-value">PKR {{ number_format($totalOutstanding/100,2) }}</div></div></div>
    <table><thead><tr><th>Student</th><th>{{ __('school.period') }}</th><th>{{ __('school_ledger.payable') }}</th><th>{{ __('school_ledger.paid') }}</th><th>{{ __('school_ledger.balance') }}</th><th></th></tr></thead>
    <tbody>@forelse($accounts as $account)<tr><td>{{ $account->student_name ?: $account->student_reference }}<br><span class="muted">{{ $account->student_reference }}</span></td><td>{{ $account->fee_period }}</td><td>{{ number_format($account->payable_paisa/100,2) }}</td><td>{{ number_format($account->paid_paisa/100,2) }}</td><td>{{ number_format(($account->payable_paisa-$account->paid_paisa)/100,2) }}</td><td><a href="{{ route('fbrpos.school-fees.student-ledger',['student_reference'=>$account->student_reference]) }}">Ledger</a></td></tr>@empty<tr><td colspan="6">{{ __('school_audit.no_accounts') }}</td></tr>@endforelse</tbody></table>
    {{ $accounts->links() }}
</section>
@endsection
