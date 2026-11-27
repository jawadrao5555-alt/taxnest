@extends('hr.layout')
@section('title', 'Payslip')
@section('content')
    <h1>{{ $period->month->format('F Y') }} payslip</h1>
    <p>{{ $workspace->name }}</p>
    <section aria-labelledby="payslip-employee">
        <h2 id="payslip-employee">{{ $line->employee_name }}</h2>
        <p>Employee {{ $line->employee_number }}</p>
        <table>
            <thead><tr><th scope="col">Payroll item</th><th scope="col">Amount (PKR)</th></tr></thead>
            <tbody>
                @foreach (\App\Models\HrPayrollLine::INPUTS as $field => $label)
                    <tr><th scope="row">{{ $label }}</th><td>{{ \App\Models\HrPayrollLine::amount($line->{$field.'_minor'}) }}</td></tr>
                @endforeach
                <tr><th scope="row">Net pay</th><td>{{ \App\Models\HrPayrollLine::amount($line->net_minor) }}</td></tr>
            </tbody>
        </table>
    </section>
    <a href="{{ $selfService ? route('hr.payroll.own', $workspace->id) : route('hr.payroll.show', [$workspace->id, $period->id]) }}">Back to {{ $selfService ? 'my payslips' : 'payroll period' }}</a>
@endsection
