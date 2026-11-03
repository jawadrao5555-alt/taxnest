@extends('pos.di-sandbox.layout')
@section('title', 'One sale. Two reporting records.')
@section('content')
<div class="columns">
    <section class="card"><h2>Prepare a test bill</h2><p>{{ $company->name }}</p>
        <p class="muted">Select a completed POS bill for a separate sandbox copy. Cash, card and credit follow the same flow. Returns keep their original sale link. Capturing a bill does not send it to either authority.</p>
        <form method="post" action="{{ route('pos.di-sandbox.capture') }}">@csrf
            <label for="transaction">Completed POS bill</label>
            <select id="transaction" name="transaction_id" required><option value="">Select a bill</option>@foreach($sales as $sale)<option value="{{ $sale->id }}">{{ $sale->invoice_number }} · {{ $sale->payment_method }} · {{ $sale->transaction_type ?? 'sale' }} · Rs {{ number_format($sale->total_amount, 2) }}</option>@endforeach</select>
            @if($hosted)
            <label><input type="checkbox" name="sandbox_copy" value="1" required>I authorize a sandbox copy of this bill for this company's DI testing. This does not report the sale to FBR production.</label>
            @else
            <label><input type="checkbox" name="fictional_data" value="1" required>I confirm this company and bill contain fictional test data.</label>
            @endif
            <button type="submit">Prepare sandbox copy</button>
        </form>
    </section>
    <section class="card"><h2>Review before sending</h2><ol><li>Review the frozen POS amounts and PRA status.</li><li>Enter the sandbox scenario, service classification, rate and tax amounts using the current FBR reference data.</li><li>Save and inspect the exact payload.</li><li>Explicitly submit to the FBR sandbox, then preview the combined test receipt.</li></ol><p class="muted">Your production POS, normal receipts, stock, sales totals, customer balance and PRA reporting settings are not changed by this workbench.</p></section>
</div>
<section class="card"><h2>Sandbox history</h2><div class="scroll"><table><thead><tr><th>POS bill</th><th>Payment</th><th>PRA snapshot</th><th>FBR sandbox</th><th>Review</th></tr></thead><tbody>
@forelse($records as $record)<tr><td>{{ $record->snapshot['invoice_number'] }}</td><td>{{ $record->snapshot['payment_method'] }}</td><td>{{ $record->snapshot['pra_status'] ?: 'Not reported' }}</td><td>{{ $record->state }}</td><td><a href="{{ route('pos.di-sandbox.show', $record->id) }}">Open test record</a></td></tr>@empty<tr><td colspan="5">No test copies yet.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
