@extends('fbr-pos.school-fees.layout')
@section('content')
<h1>{{ __('school_audit.student_ledger') }}</h1>
<form class="card" method="get" action="{{ route('fbrpos.school-fees.student-ledger') }}">
    <label for="ledger-reference">{{ __('school_audit.reference_search') }}</label>
    <input id="ledger-reference" name="student_reference" maxlength="100" value="{{ $reference }}" required>
    <div class="actions"><button type="submit">{{ __('pos.search') }}</button></div>
</form>
@if($accounts !== null)
    <div class="metrics">
        <section class="card"><h2>{{ __('school_audit.current_due') }}</h2><p class="metric-value">PKR {{ number_format($currentDue / 100, 2) }}</p></section>
        <section class="card"><h2>{{ __('school_audit.past_due') }}</h2><p class="metric-value">PKR {{ number_format($pastDue / 100, 2) }}</p></section>
    </div>
    <section class="card scroll">
        <h2>{{ __('school_audit.student_ledger') }}</h2>
        <table>
            <thead><tr><th scope="col">{{ __('school.period') }}</th><th scope="col">{{ __('school_ledger.payable') }}</th><th scope="col">{{ __('school_ledger.paid') }}</th><th scope="col">{{ __('school_ledger.balance') }}</th></tr></thead>
            <tbody>
                @forelse($accounts as $account)
                    <tr><td>{{ $account->fee_period }}</td><td>{{ number_format($account->payable_paisa / 100, 2) }}</td><td>{{ number_format($account->paid_paisa / 100, 2) }}</td><td>{{ number_format(($account->payable_paisa - $account->paid_paisa) / 100, 2) }}</td></tr>
                @empty
                    <tr><td colspan="4">{{ __('school_audit.no_accounts') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $accounts->links() }}
    </section>
    <section class="card scroll">
        <h2>{{ __('school_audit.payment_history') }}</h2>
        <table>
            <thead><tr><th scope="col">{{ __('pos.school_collected_at') }}</th><th scope="col">{{ __('school.period') }}</th><th scope="col">{{ __('school_ledger.paid') }}</th><th scope="col">{{ __('pos.school_payment') }}</th><th scope="col">{{ __('school_audit.reference') }}</th><th scope="col">{{ __('pos.school_nav_receipts') }}</th></tr></thead>
            <tbody>
                @forelse($payments as $payment)
                    <tr><td>{{ $payment->collected_at }}</td><td>{{ $payment->fee_period ?? '—' }}</td><td>{{ number_format($payment->amount_paisa / 100, 2) }}</td><td>{{ __('school_print.'.($payment->payment_method ?? 'cash')) }}</td><td>{{ $payment->payment_reference ?? '—' }}</td><td><a href="{{ route('fbrpos.school-fees.receipt', $payment->id) }}">SF-{{ $payment->id }}</a></td></tr>
                @empty
                    <tr><td colspan="6">{{ __('school_audit.no_payments') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $payments->links() }}
    </section>
@endif
@endsection
