@extends('hr.layout')
@section('title', $period->month->format('F Y').' payroll')
@section('content')
    <h1>{{ $period->month->format('F Y') }} payroll</h1>
    <p><a href="{{ route('hr.payroll.index', $workspace->id) }}">{{ $workspace->name }} payroll</a></p>
    @if ($canEdit && $employees->isNotEmpty())
        <section aria-labelledby="payroll-entry">
            <h2 id="payroll-entry">Salary inputs</h2>
            <p>Enter the approved earnings and deductions for this month in PKR. Saving an employee replaces their draft line.</p>
            <form action="{{ route('hr.payroll.lines.store', [$workspace->id, $period->id]) }}" method="POST">
                @csrf
                <input type="hidden" name="version" value="{{ $period->version }}">
                <div class="fields">
                    <label>Employee
                        <select name="employee_id" required>
                            <option value="">Choose employee</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}" @selected(old('employee_id') == $employee->id)>
                                    {{ $employee->full_name }} ({{ $employee->employee_number }}){{ $employee->status === 'inactive' ? ' · Inactive' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    @include('hr.payroll-inputs', ['line' => null])
                </div>
                <button type="submit">Save payroll line</button>
            </form>
        </section>
    @endif

    <section aria-labelledby="payroll-lines">
        <h2 id="payroll-lines">{{ $period->isApproved() ? 'Approved payroll' : 'Draft payroll' }}</h2>
        <div class="table">
            <table>
                <thead><tr>
                    <th scope="col">Employee</th>
                    @foreach (\App\Models\HrPayrollLine::INPUTS as $label)
                        <th scope="col">{{ $label }}</th>
                    @endforeach
                    <th scope="col">Net pay (PKR)</th>
                    @if ($canEdit || $period->isApproved()) <th scope="col">Actions</th> @endif
                </tr></thead>
                <tbody>
                    @forelse ($lines as $line)
                        <tr>
                            <th scope="row">{{ $line->employee_name }}<br><small>{{ $line->employee_number }}</small></th>
                            @foreach (array_keys(\App\Models\HrPayrollLine::INPUTS) as $field)
                                <td>{{ \App\Models\HrPayrollLine::amount($line->{$field.'_minor'}) }}</td>
                            @endforeach
                            <td>{{ \App\Models\HrPayrollLine::amount($line->net_minor) }}</td>
                            @if ($canEdit)
                                <td>
                                    <details>
                                        <summary>Edit</summary>
                                        <form action="{{ route('hr.payroll.lines.store', [$workspace->id, $period->id]) }}" method="POST">
                                            @csrf
                                            <input type="hidden" name="version" value="{{ $period->version }}">
                                            <input type="hidden" name="employee_id" value="{{ $line->employee_id }}">
                                            @include('hr.payroll-inputs', compact('line'))
                                            <button type="submit">Save changes</button>
                                        </form>
                                    </details>
                                    <form action="{{ route('hr.payroll.lines.remove', [$workspace->id, $period->id, $line->id]) }}" method="POST">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="version" value="{{ $period->version }}">
                                        <button type="submit">Remove draft line</button>
                                    </form>
                                </td>
                            @elseif ($period->isApproved())
                                <td><a href="{{ route('hr.payroll.payslip', [$workspace->id, $period->id, $line->id]) }}">Payslip</a></td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canEdit || $period->isApproved() ? 8 : 7 }}">No payroll lines yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p><strong>Total net pay (PKR)</strong> {{ \App\Models\HrPayrollLine::amount($lines->sum('net_minor')) }}</p>
        @if ($period->isApproved())
            <p>Approved {{ \Carbon\CarbonImmutable::parse($period->approved_at_utc, 'UTC')->setTimezone($workspace->timezone)->format('j M Y, H:i T') }}</p>
            <a href="{{ route('hr.payroll.export', [$workspace->id, $period->id]) }}">Download approved payroll CSV</a>
        @elseif ($canApprove && $lines->isNotEmpty())
            <form action="{{ route('hr.payroll.approve', [$workspace->id, $period->id]) }}" method="POST">
                @csrf
                <input type="hidden" name="version" value="{{ $period->version }}">
                <button type="submit">Approve and lock this payroll</button>
            </form>
        @endif
    </section>
@endsection
