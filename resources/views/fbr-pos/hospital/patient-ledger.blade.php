@extends('fbr-pos.hospital.layout')
@section('content')
<h1>Patient Ledger</h1>
<form class="card no-print" method="get"><label for="patient-ledger-search">Patient reference or name</label><div class="actions"><input id="patient-ledger-search" name="search" value="{{ $search }}" placeholder="e.g. PAT-001"><button>Search</button></div></form>
<section class="metrics">
<div class="card"><div class="muted">Total charges</div><div class="metric-value">PKR {{ number_format($summary['charged_paisa']/100,2) }}</div></div>
<div class="card"><div class="muted">Payments received</div><div class="metric-value">PKR {{ number_format($summary['paid_paisa']/100,2) }}</div></div>
<div class="card"><div class="muted">Outstanding</div><div class="metric-value">PKR {{ number_format($summary['balance_paisa']/100,2) }}</div></div>
</section>
<div class="card scroll"><table><thead><tr><th>Patient</th><th>Invoice</th><th>Charge</th><th>Paid</th><th>Balance</th><th></th></tr></thead><tbody>
@forelse($invoices as $invoice)<tr><td>{{ $invoice->patient_reference }}@if($invoice->patient_name)<br><span class="muted">{{ $invoice->patient_name }}</span>@endif</td><td>HI-{{ $invoice->id }}</td><td>PKR {{ number_format($invoice->total_paisa/100,2) }}</td><td>PKR {{ number_format($invoice->paid_paisa/100,2) }}</td><td>PKR {{ number_format($invoice->balance_paisa/100,2) }}</td><td><a href="/fbr-pos/hospital/invoices/{{ $invoice->id }}{{ !empty($hospitalUnassigned)?'?unassigned=1':'' }}">Open</a></td></tr>
@empty<tr><td colspan="6">No matching patient billing record.</td></tr>@endforelse
</tbody></table></div>{{ $invoices->links() }}
@endsection
