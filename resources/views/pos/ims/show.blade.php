@extends('pos.di-sandbox.layout')
@section('title', 'PRA + FBR POS · IMS record')
@section('content')
@php($s=$record->snapshot)
<p class="notice"><strong>{{ strtoupper($record->environment) }} · {{ $record->connection_mode }}</strong> · The record's environment, POS ID and original sale remain fixed.</p>
<div class="columns"><section class="card"><h2>{{ $s['invoice_number'] }}</h2><dl><dt>Payment</dt><dd>{{ $s['payment_method'] }}</dd><dt>Original PRA tax</dt><dd>{{ number_format($s['pra_tax'],2) }}</dd><dt>Original customer total</dt><dd>{{ number_format($s['total'],2) }}</dd><dt>PRA status</dt><dd>{{ $s['pra_status'] ?: 'Not reported' }}</dd><dt>PRA number</dt><dd>{{ $s['pra_number'] ?: 'None' }}</dd><dt>FBR POS result</dt><dd>{{ $record->state }}</dd><dt>IMS reference</dt><dd>{{ $record->fiscal_number ?: 'None' }}</dd></dl>
@if($record->payload)<p>FBR reported tax: {{ number_format($record->payload['TotalTaxCharged'],2) }} · FBR reported total: {{ number_format($record->payload['TotalBillAmount'],2) }}</p>@endif
@if(in_array($record->state,['submitting','verification_required']))<p class="error">Dispatch may have reached FBR IMS. Replay is blocked. Check the IMS component/FBR evidence before creating another invoice.</p>@endif
@if($record->state==='queued_agent')<p>Waiting for the designated Desktop Agent. This queue never changes the existing PRA queue.</p>@endif
@if($record->state==='local_accepted')<p class="notice">Local FBR IMS acknowledged this invoice. Central FBR synchronization has not been independently verified.</p>@endif
<a class="button" href="{{ route('pos.tax-integrations.ims.receipt',['id'=>$record->id,'width'=>80]) }}">80mm receipt preview</a><a class="button" href="{{ route('pos.tax-integrations.ims.receipt',['id'=>$record->id,'width'=>58]) }}">58mm receipt preview</a>
<h2>Attempts</h2><ul>@forelse($record->attempts ?? [] as $a)<li>{{ $a['at'] }} · {{ $a['state'] }} · {{ implode(', ',$a['error_codes'] ?? []) }}</li>@empty<li>No submission.</li>@endforelse</ul></section>
<section class="card"><h2>Review IMS mapping</h2><p>Enter the reviewed PCT codes, rates, federal tax and totals. PRA tax is never automatically copied into federal tax. A return requires its original accepted IMS reference from this environment.</p>
<form method="post" action="{{ route('pos.tax-integrations.ims.prepare',$record->id) }}">@csrf<label for="payload">Exact IMS JSON payload</label><textarea id="payload" name="payload_json" spellcheck="false" @readonly(!in_array($record->state,['draft','rejected']))>{{ old('payload_json',json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION)) }}</textarea>
@if(in_array($record->state,['draft','rejected']))<label><input type="checkbox" name="mapping_reviewed" value="1" required>I reviewed federal tax treatment, amounts, buyer information and the IMS environment.</label><button>Save payload for review</button>@endif</form>
@if($record->state==='draft' && $record->payload)<form method="post" action="{{ route('pos.tax-integrations.ims.submit',$record->id) }}">@csrf<label><input type="checkbox" name="confirm_submit" value="1" required>Submit to {{ strtoupper($record->environment) }} FBR POS {{ $record->connection_mode==='fiscal_device' ? 'through the designated local IMS component' : 'cloud API' }}. {{ $record->environment==='production' ? 'This reports a real invoice.' : 'This is a sandbox test.' }}</label><button>Submit reviewed IMS invoice</button></form>@endif
</section></div>
@endsection
