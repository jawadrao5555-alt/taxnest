@extends('pos.di-sandbox.layout')
@section('title', 'PRA + Digital Invoice record')
@section('content')
@php($s = $record->snapshot)
@php($env = $record->reportingEnvironment())
<p class="notice"><strong>FBR environment: {{ strtoupper($env) }}</strong> · This record's environment cannot be changed.</p>
@if(!$canSubmit)<p class="notice">This record is read-only while its environment is inactive. <a href="{{ route('pos.di-sandbox.setup') }}">Open Connection setup</a> to select the matching environment.</p>@endif
<div class="columns">
    <section class="card"><h2>Original POS snapshot · {{ $s['invoice_number'] }}</h2>
        <dl><dt>Document</dt><dd>{{ $s['transaction_type'] }}</dd><dt>Payment</dt><dd>{{ $s['payment_method'] }}</dd><dt>PRA sales tax</dt><dd>Rs {{ number_format($s['pra_tax'], 2) }}</dd><dt>Customer total</dt><dd><strong>Rs {{ number_format($s['total'], 2) }}</strong></dd><dt>PRA status</dt><dd>{{ $s['pra_status'] ?: 'Not reported' }}</dd><dt>PRA fiscal number</dt><dd>{{ $s['pra_number'] ?: 'None' }}</dd></dl>
        <table><thead><tr><th>Item</th><th>Qty</th><th>Net base</th></tr></thead><tbody>@foreach($s['lines'] as $line)<tr><td>{{ $line['name'] }}</td><td>{{ $line['quantity'] }}</td><td>{{ number_format($line['base'], 2) }}</td></tr>@endforeach</tbody></table>
        <p class="muted">These amounts and this payment method are a frozen snapshot. Later debt recovery does not create a second sale. Original bills remain in the POS.</p>
        <h2>FBR {{ ucfirst($env) }} result</h2><span class="badge" data-sandbox-state="{{ $record->state }}">{{ str_replace('_', ' ', strtoupper($record->state)) }}</span>
        @if($record->fiscalReference())<p><strong>{{ ucfirst($env) }} reference:</strong> {{ $record->fiscalReference() }}</p>@endif
        @if($record->payload)
        <dl><dt>FBR payload base</dt><dd>Rs {{ number_format(array_sum(array_column($record->payload['items'], 'valueSalesExcludingST')), 2) }}</dd><dt>FBR reported tax</dt><dd>Rs {{ number_format(array_sum(array_column($record->payload['items'], 'salesTaxApplicable')), 2) }}</dd><dt>FBR payload total</dt><dd>Rs {{ number_format(array_sum(array_column($record->payload['items'], 'totalValues')), 2) }}</dd></dl><p class="muted">These are the values to report to FBR. Review them separately from the original PRA bill. They do not create another sale or change the customer balance.</p>
        @endif
        @if(in_array($record->state, ['verification_required', 'submitting']))<p class="error">The submission may have reached FBR. Do not submit another copy. Reconcile with FBR for this environment; replay is blocked.</p>@endif
        @if($record->state === 'rejected')<p class="error">Explicit FBR rejection. Review the mapping before preparing another attempt. Codes: {{ implode(', ', collect($record->attempts)->last()['error_codes'] ?? []) ?: 'not supplied' }}</p>@endif
        <a class="button" href="{{ route('pos.di-sandbox.receipt', ['id' => $record->id, 'width' => 80]) }}">80mm receipt preview</a><a class="button" href="{{ route('pos.di-sandbox.receipt', ['id' => $record->id, 'width' => 58]) }}">58mm receipt preview</a>
        <h2>Submission history</h2><ul>@forelse($record->attempts ?? [] as $attempt)<li>{{ $attempt['at'] }} · {{ $attempt['state'] }}</li>@empty<li>No submission attempted.</li>@endforelse</ul>
    </section>
    <section class="card"><h2>FBR {{ ucfirst($env) }} mapping</h2>
        <p class="muted">Resolve classification and tax fields explicitly; PRA tax is not copied into federal sales tax. Confirm provincial-tax treatment with PRAL. Production requires reviewed live buyer and tax details. Sandbox scenario IDs do not establish an exemption.</p>
        @if($s['transaction_type'] === 'return')<p class="notice">Use the original sale's accepted FBR reference from the same environment as invoiceRefNo. Sandbox references cannot adjust production invoices.</p>@endif
        <form method="post" action="{{ route('pos.di-sandbox.prepare', $record->id) }}">@csrf
            <label for="payload">Exact {{ ucfirst($env) }} JSON payload</label>
            <textarea id="payload" name="payload_json" spellcheck="false" @readonly(!$canSubmit || !in_array($record->state, ['draft','rejected']))>{{ old('payload_json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) }}</textarea>
            @if($canSubmit && in_array($record->state, ['draft','rejected']))<label><input type="checkbox" name="mapping_reviewed" value="1" required>I reviewed these buyer details, classification, tax and totals for {{ strtoupper($env) }} submission.</label><button type="submit">Save payload for review</button>@endif
        </form>
        @if($canSubmit && $record->state === 'draft' && $record->payload)
        <p class="muted">Payload saved. Sending uses this company's {{ $env }} token and the fixed FBR {{ $env }} endpoint.</p>
        <form method="post" action="{{ route('pos.di-sandbox.submit', $record->id) }}">@csrf<label><input type="checkbox" name="{{ $env === 'production' ? 'confirm_production' : 'confirm_sandbox' }}" value="1" required>{{ $env === 'production' ? 'Report this real invoice to FBR PRODUCTION now. This is a live fiscal submission.' : 'Send this reviewed test copy to the FBR sandbox.' }}</label><button type="submit">Submit to FBR {{ strtoupper($env) }}</button></form>
        @endif
    </section>
</div>
@endsection
