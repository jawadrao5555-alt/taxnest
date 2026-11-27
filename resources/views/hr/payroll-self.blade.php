@extends('hr.layout')
@section('title', 'My payslips')
@section('content')
    <h1>My payslips</h1>
    <p><a href="{{ route('hr.organizations.show', $workspace->id) }}">{{ $workspace->name }}</a></p>
    <section aria-label="Approved payslips">
        @forelse ($payslips as $payslip)
            <p><a href="{{ route('hr.payroll.own.show', [$workspace->id, $payslip->period_id, $payslip->id]) }}">{{ $payslip->period->month->format('F Y') }} payslip</a></p>
        @empty
            <p>No approved payslips yet.</p>
        @endforelse
        <div class="pagination">{{ $payslips->links() }}</div>
    </section>
@endsection
