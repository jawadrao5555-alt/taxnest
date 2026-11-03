@extends('pos.di-sandbox.layout')
@section('title', 'One sale. Two reporting records.')
@section('content')
<div class="columns">
    <section class="card"><h2>Prepare a test bill / live bill</h2><p><strong>DI environment: {{ strtoupper($environment) }}</strong></p><p>{{ $company->name }}</p>
        <p class="muted">Select a completed POS bill for separate DI reporting evidence. Cash, card and credit follow the same flow. Returns keep their original sale link. Capturing a bill does not send it to either authority.</p>
        @if($canReport)
        <form method="post" action="{{ route('pos.di-sandbox.capture') }}">@csrf
            <input type="hidden" name="environment" value="{{ $environment }}">
            <label for="transaction">Completed POS bill</label>
            <select id="transaction" name="transaction_id" required><option value="">Select a bill</option>@foreach($sales as $sale)<option value="{{ $sale->id }}">{{ $sale->invoice_number }} · {{ $sale->payment_method }} · {{ $sale->transaction_type ?? 'sale' }} · Rs {{ number_format($sale->total_amount, 2) }}</option>@endforeach</select>
            @if($environment === 'production')
            <label><input type="checkbox" name="confirm_production_copy" value="1" required>This is a real completed bill, not test data. Prepare a new Production record; do not copy sandbox payloads.</label>
            @elseif($hosted)
            <label><input type="checkbox" name="sandbox_copy" value="1" required>I authorize a sandbox copy of this bill for this company's DI testing. This does not report the sale to FBR production.</label>
            @else
            <label><input type="checkbox" name="fictional_data" value="1" required>I confirm this company and bill contain fictional test data.</label>
            @endif
            <button type="submit">Prepare {{ ucfirst($environment) }} record</button>
        </form>
        @else<p>DI submission is disabled. <a href="{{ route('pos.di-sandbox.setup') }}">Open Connection setup</a>. Existing history remains available.</p>@endif
    </section>
    <section class="card"><h2>Review before sending</h2><ol><li>Review the frozen POS amounts and PRA status.</li><li>Review classification, buyer details, rate and tax amounts. Sandbox also requires the applicable scenario ID.</li><li>Save and inspect the exact payload.</li><li>Explicitly submit to the displayed environment, then inspect the acknowledgement and combined receipt.</li></ol><p class="muted">Your production POS, normal receipts, stock, sales totals, customer balance and PRA reporting settings are not changed by this workbench.</p></section>
</div>
<section class="card"><h2>DI history · both environments</h2><div class="scroll"><table><thead><tr><th>POS bill</th><th>Payment</th><th>PRA snapshot</th><th>Environment</th><th>FBR result</th><th>Review</th></tr></thead><tbody>
@forelse($records as $record)<tr><td>{{ $record->snapshot['invoice_number'] }}</td><td>{{ $record->snapshot['payment_method'] }}</td><td>{{ $record->snapshot['pra_status'] ?: 'Not reported' }}</td><td>{{ strtoupper($record->reportingEnvironment()) }}</td><td>{{ $record->state }}</td><td><a href="{{ route('pos.di-sandbox.show', $record->id) }}">Open record</a></td></tr>@empty<tr><td colspan="6">No DI records yet.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
