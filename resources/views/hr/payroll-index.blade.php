@extends('hr.layout')
@section('title', 'Payroll')
@section('content')
    <h1>Payroll</h1>
    <p><a href="{{ route('hr.organizations.show', $workspace->id) }}">{{ $workspace->name }}</a></p>
    @if ($canEdit)
        <section aria-labelledby="payroll-draft">
            <h2 id="payroll-draft">Open a payroll draft</h2>
            <form action="{{ route('hr.payroll.store', $workspace->id) }}" method="POST" class="filters">
                @csrf
                <label>Pay month <input type="month" name="month" value="{{ old('month', now($workspace->timezone)->format('Y-m')) }}" required></label>
                <button type="submit">Open period</button>
            </form>
        </section>
    @endif
    <section aria-labelledby="payroll-periods">
        <h2 id="payroll-periods">Payroll periods</h2>
        <div class="table">
            <table>
                <thead><tr><th scope="col">Month</th><th scope="col">Employees</th><th scope="col">Status</th></tr></thead>
                <tbody>
                    @forelse ($periods as $period)
                        <tr>
                            <td><a href="{{ route('hr.payroll.show', [$workspace->id, $period->id]) }}">{{ $period->month->format('F Y') }}</a></td>
                            <td>{{ $period->lines_count }}</td>
                            <td>{{ $period->isApproved() ? 'Approved' : 'Draft' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3">No payroll periods yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination">{{ $periods->links() }}</div>
    </section>
@endsection
