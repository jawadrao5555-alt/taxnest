@extends('pos.di-sandbox.layout')
@section('title', 'Combined invoice rehearsal')
@section('content')
@php($s = $record->snapshot)
<div class="columns">
    <section class="card"><h2>Original POS snapshot · {{ $s['invoice_number'] }}</h2>
        <dl><dt>Document</dt><dd>{{ $s['transaction_type'] }}</dd><dt>Payment</dt><dd>{{ $s['payment_method'] }}</dd><dt>PRA sales tax</dt><dd>Rs {{ number_format($s['pra_tax'], 2) }}</dd><dt>Customer total</dt><dd><strong>Rs {{ number_format($s['total'], 2) }}</strong></dd><dt>PRA status</dt><dd>{{ $s['pra_status'] ?: 'Not reported' }}</dd><dt>PRA fiscal number</dt><dd>{{ $s['pra_number'] ?: 'None' }}</dd></dl>
        <table><thead><tr><th>Item</th><th>Qty</th><th>Net base</th></tr></thead><tbody>@foreach($s['lines'] as $line)<tr><td>{{ $line['name'] }}</td><td>{{ $line['quantity'] }}</td><td>{{ number_format($line['base'], 2) }}</td></tr>@endforeach</tbody></table>
        <p class="muted">These amounts and this payment method are a frozen snapshot. Later debt recovery does not create a second sale. Original bills remain in the POS.</p>
        <h2>FBR sandbox result</h2><span class="badge" data-sandbox-state="{{ $record->state }}">{{ str_replace('_', ' ', strtoupper($record->state)) }}</span>
        @if($record->sandbox_invoice_number)<p><strong>Sandbox reference:</strong> {{ $record->sandbox_invoice_number }}</p>@endif
        @if($record->payload)
        <dl><dt>FBR payload base</dt><dd>Rs {{ number_format(array_sum(array_column($record->payload['items'], 'valueSalesExcludingST')), 2) }}</dd><dt>FBR sandbox tax</dt><dd>Rs {{ number_format(array_sum(array_column($record->payload['items'], 'salesTaxApplicable')), 2) }}</dd><dt>FBR payload total</dt><dd>Rs {{ number_format(array_sum(array_column($record->payload['items'], 'totalValues')), 2) }}</dd></dl><p class="muted">Payload amounts are test mapping values. They do not change the POS bill or establish a tax liability.</p>
        @endif
        @if(in_array($record->state, ['verification_required', 'submitting']))<p class="error">The submission may have reached FBR. Do not submit another copy. Reconcile with the sandbox operator; replay is blocked.</p>@endif
        @if($record->state === 'rejected')<p class="error">Explicit sandbox rejection. Review the mapping before preparing another attempt. Codes: {{ implode(', ', collect($record->attempts)->last()['error_codes'] ?? []) ?: 'not supplied' }}</p>@endif
        <a class="button" href="{{ route('pos.di-sandbox.receipt', ['id' => $record->id, 'width' => 80]) }}">80mm test preview</a><a class="button" href="{{ route('pos.di-sandbox.receipt', ['id' => $record->id, 'width' => 58]) }}">58mm test preview</a>
        <h2>Submission history</h2><ul>@forelse($record->attempts ?? [] as $attempt)<li>{{ $attempt['at'] }} · {{ $attempt['state'] }}</li>@empty<li>No submission attempted.</li>@endforelse</ul>
    </section>
    <section class="card"><h2>FBR sandbox mapping</h2>
        <p class="muted">Technical review only. Blank classification and tax fields must be resolved explicitly; PRA tax is not copied into federal sales tax. Confirm how PRA tax belongs in totalValues with PRAL. SN019 is a test scenario, not an exemption. No production-ready mapping is implied.</p>
        @if($s['transaction_type'] === 'return')<p class="notice">Use the original sale's accepted FBR sandbox number as invoiceRefNo. This rehearsal never adjusts a production invoice.</p>@endif
        <form method="post" action="{{ route('pos.di-sandbox.prepare', $record->id) }}">@csrf
            <label for="payload">Exact sandbox JSON payload</label>
            <textarea id="payload" name="payload_json" spellcheck="false" @readonly(!in_array($record->state, ['draft','rejected']))>{{ old('payload_json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) }}</textarea>
            @if(in_array($record->state, ['draft','rejected']))<label><input type="checkbox" name="mapping_reviewed" value="1" required>I reviewed this sandbox mapping. It is not approved for production.</label><button type="submit">Save payload for review</button>@endif
        </form>
        @if($record->state === 'draft' && $record->payload)
        <p class="muted">Payload saved. Sending uses only this company's sandbox token and the fixed FBR sandbox URL.</p>
        <form method="post" action="{{ route('pos.di-sandbox.submit', $record->id) }}">@csrf<label><input type="checkbox" name="confirm_sandbox" value="1" required>Send this reviewed test copy to the FBR sandbox.</label><button type="submit">Submit to FBR sandbox</button></form>
        @endif
    </section>
</div>
@endsection
