@extends('fbr-pos.school-fees.layout')
@section('content')
<h1>School Collection & IMS Reports</h1>
<p class="muted">Daily, monthly, half-yearly, yearly, two-year and unrestricted custom date reports. Exports contain the complete filtered range.</p>
<section class="card no-print">
<form method="get" id="school-report-filters">
<div class="grid">
<label>Period<select name="preset" id="report-preset">@foreach(['daily'=>'Today','monthly'=>'Current month','half_yearly'=>'Current half-year','yearly'=>'Current year','two_year'=>'Two years','custom'=>'Custom range','all'=>'All records'] as $value=>$label)<option value="{{ $value }}" @selected($filters['preset']===$value)>{{ $label }}</option>@endforeach</select></label>
<label>From<input type="date" name="from" value="{{ $filters['from'] }}"></label><label>To<input type="date" name="to" value="{{ $filters['to'] }}"></label>
@if($owner)<label>Campus<select name="campus"><option value="all" @selected(request('campus')==='all')>All accessible campuses</option><option value="unassigned" @selected(request('campus')==='unassigned')>Unassigned historical records</option>@foreach($campuses as $campus)<option value="{{ $campus->id }}" @selected((string)request('campus')===(string)$campus->id || (!request()->has('campus') && (int)$active===(int)$campus->id))>{{ $campus->name }}</option>@endforeach</select></label>@endif
<label>Payment<select name="payment_method"><option value="">All methods</option>@foreach(['cash','card','bank'] as $method)<option value="{{ $method }}" @selected(request('payment_method')===$method)>{{ ucfirst($method) }}</option>@endforeach</select></label>
<label>Fee head<select name="fee_head"><option value="">All fee heads</option>@foreach($feeHeads as $head)<option value="{{ $head->code }}" @selected(request('fee_head')===$head->code)>{{ $head->label }}</option>@endforeach</select></label>
<label>IMS status<select name="fbr_status"><option value="">All IMS statuses</option>@foreach(['accepted','pending','failed','off'] as $status)<option value="{{ $status }}" @selected(request('fbr_status')===$status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
<label>Cashier<input name="cashier" maxlength="100" value="{{ request('cashier') }}"></label><label>Fiscal number<input name="fiscal_number" maxlength="100" value="{{ request('fiscal_number') }}"></label><label>Student / receipt<input name="search" maxlength="100" value="{{ request('search') }}"></label>
</div>
<div class="actions"><button type="submit">Apply filters</button><a href="{{ route('fbrpos.school-fees.reports') }}">Reset</a><a class="button" href="{{ route('fbrpos.school-fees.reports.csv', request()->query()) }}">Download CSV</a><a class="button" href="{{ route('fbrpos.school-fees.reports.pdf', request()->query()) }}">Download PDF</a></div>
</form>
</section>
<div class="metrics">
<section class="card"><h2>Total collected</h2><div class="metric-value">PKR {{ number_format($summary['total_paisa']/100,2) }}</div></section>
<section class="card"><h2>Receipts</h2><div class="metric-value">{{ number_format($summary['count']) }}</div></section>
<section class="card"><h2>Cash</h2><div class="metric-value">PKR {{ number_format($summary['cash_paisa']/100,2) }}</div></section>
<section class="card"><h2>Card / Bank</h2><div class="metric-value">PKR {{ number_format(($summary['card_paisa']+$summary['bank_paisa'])/100,2) }}</div></section>
<section class="card"><h2>IMS accepted</h2><div class="metric-value">{{ number_format($summary['accepted']) }}</div></section>
<section class="card"><h2>IMS pending / failed</h2><div class="metric-value">{{ number_format($summary['pending']) }} / {{ number_format($summary['failed']) }}</div></section>
</div>
<section class="card"><div class="scroll"><table><thead><tr><th>Receipt</th><th>Date</th><th>Student</th><th>Period</th><th>Fee</th><th>Payment</th><th>Amount</th><th>Campus</th><th>Cashier</th><th>IMS</th><th>Fiscal no.</th></tr></thead><tbody>
@forelse($receipts as $row)<tr><td><a href="{{ route('fbrpos.school-fees.receipt',$row->id) }}">SF-{{ $row->id }}</a></td><td>{{ $row->collected_at }}</td><td>{{ $row->student_name ?: $row->student_reference }}<br><span class="muted">{{ $row->student_reference }}</span></td><td>{{ $row->fee_period ?: '—' }}</td><td>{{ $row->fee_head_label ?: $row->fee_head }}</td><td>{{ ucfirst($row->payment_method) }}<br><span class="muted">{{ $row->payment_reference ?: '—' }}</span></td><td>PKR {{ number_format($row->amount_paisa/100,2) }}</td><td>{{ $row->campus_name ?: '—' }}</td><td>{{ $row->cashier_name ?: '—' }}</td><td>{{ $row->fbr_status ?: 'Off' }}</td><td>{{ $row->fbr_invoice_number ?: '—' }}</td></tr>
@empty<tr><td colspan="11">No matching receipts.</td></tr>@endforelse
</tbody></table></div>{{ $receipts->links() }}</section>
@endsection
