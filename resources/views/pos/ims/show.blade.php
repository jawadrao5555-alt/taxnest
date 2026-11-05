@extends('pos.di-sandbox.layout')
@section('title', 'PRA + FBR POS · IMS record')
@section('content')
@php($s=$record->snapshot)
@if(isset($s['recovery']))<p class="notice">Original bill amounts were recovered from its stored line and payment facts, checked against the PRA-accepted bill. The original sale was not rewritten. Review this recovered snapshot before submission.</p>@endif
<p class="notice"><strong>{{ strtoupper($record->environment) }} · {{ $record->connection_mode }}</strong> · The record's environment, POS ID and original sale remain fixed.</p>
<div class="columns"><section class="card"><h2>{{ $s['invoice_number'] }}</h2><dl><dt>Payment</dt><dd>{{ $s['payment_method'] }}</dd><dt>Original PRA tax</dt><dd>{{ number_format($s['pra_tax'],2) }}</dd><dt>Original customer total</dt><dd>{{ number_format($s['total'],2) }}</dd><dt>PRA status</dt><dd>{{ $s['pra_status'] ?: 'Not reported' }}</dd><dt>PRA number</dt><dd>{{ $s['pra_number'] ?: 'None' }}</dd><dt>FBR POS result</dt><dd>{{ $record->state }}</dd><dt>IMS reference</dt><dd>{{ $record->fiscal_number ?: 'None' }}</dd></dl>
@if($record->payload)<p>IMS payload tax: {{ number_format($record->payload['TotalTaxCharged'],2) }} · IMS payload total: {{ number_format($record->payload['TotalBillAmount'],2) }}</p>@endif
@if(in_array($record->state,['submitting','verification_required']))<p class="error">Dispatch may have reached FBR IMS. Replay is blocked. Check the IMS component/FBR evidence before creating another invoice.</p>@endif
@if($record->state==='queued_agent')<p>Waiting for the designated Desktop Agent. This queue never changes the existing PRA queue.</p>@endif
@if($record->state==='local_accepted')<p class="notice">Local FBR IMS acknowledged this invoice. Central FBR synchronization has not been independently verified.</p>@endif
<a class="button" href="{{ route('pos.tax-integrations.ims.receipt',['id'=>$record->id,'width'=>80]) }}">80mm receipt preview</a><a class="button" href="{{ route('pos.tax-integrations.ims.receipt',['id'=>$record->id,'width'=>58]) }}">58mm receipt preview</a>
@if(in_array($record->state,['draft','rejected','queued_agent']))
<form method="post" action="{{ route('pos.tax-integrations.ims.connection',$record->id) }}">@csrf
<label><input type="checkbox" name="confirm_connection" value="1" required>Use the current IMS connection for this {{ $record->environment }} bill, cancel any undispatched queue entry and clear the mapping for fresh review. Keep the original sale amounts.</label><button>Refresh connection and review again</button></form>
@endif
@if(in_array($record->state,['verification_required','local_accepted']) && $record->payload)
<h2>Recover an invoice already visible in FBR</h2>
<p>This saves your independently checked FBR portal evidence. It sends nothing to IMS or FBR. Enter the portal values yourself; all must match this bill. Never use another bill's reference.</p>
<form method="post" action="{{ route('pos.tax-integrations.ims.reconcile',$record->id) }}">@csrf
<label for="portal-number">FBR invoice number from portal</label><input id="portal-number" name="fiscal_number" required maxlength="100">
<label for="portal-environment">Portal environment</label><select id="portal-environment" name="environment" required><option value="">Select</option><option value="sandbox">Sandbox</option><option value="production">Production / Live</option></select>
<label for="portal-pos">Portal POS ID</label><input id="portal-pos" name="pos_id" type="number" min="1" required>
<label for="portal-usin">Portal USIN / original bill number</label><input id="portal-usin" name="usin" required maxlength="100">
<label for="portal-date">Portal invoice date</label><input id="portal-date" name="invoice_date" type="date" required>
<label for="portal-type">Portal invoice type</label><select id="portal-type" name="invoice_type" required><option value="">Select</option><option value="1">Sale</option><option value="3">Return</option></select>
<label for="portal-sale">Portal sale value</label><input id="portal-sale" name="sale_value" type="number" min="0" step="0.01" required>
<label for="portal-tax">Portal tax charged</label><input id="portal-tax" name="tax_charged" type="number" min="0" step="0.01" required>
<label for="portal-total">Portal total amount</label><input id="portal-total" name="total_amount" type="number" min="0" step="0.01" required>
<label for="portal-quantity">Portal total quantity</label><input id="portal-quantity" name="quantity" type="number" min="0" step="0.001" required>
<label for="portal-note">Evidence note (report date and how you checked)</label><input id="portal-note" name="evidence_note" minlength="20" maxlength="1000" required>
<p>Do not enter tokens, passwords or customer identity details in the evidence note.</p>
<label><input type="checkbox" name="confirm_portal" value="1" required>I checked this exact invoice in the selected FBR portal, including its POS ID, USIN, date, type, quantity and all amounts.</label>
<button>Save verified portal evidence — do not resend</button></form>
@endif
<h2>Attempts</h2><ul>@forelse($record->attempts ?? [] as $a)<li>{{ $a['at'] }} · {{ $a['state'] }} · {{ implode(', ',$a['error_codes'] ?? []) }} @foreach(($a['acknowledgement'] ?? []) as $field=>$value) · {{ $field }}: {{ $value }} @endforeach @if(!empty($a['response_fields'])) · Reply fields: {{ implode(', ',$a['response_fields']) }} @endif @if(isset($a['diagnostic'])) · {{ $a['diagnostic'] }} @endif @if(($a['source'] ?? '')==='owner_verified_fbr_portal') · Owner-verified FBR portal evidence · {{ $a['fiscal_number'] }} @endif</li>@empty<li>No submission.</li>@endforelse</ul></section>
<section class="card"><h2>Review IMS mapping</h2><p>Enter the reviewed PCT codes, rates, federal tax and totals. A reviewed automatic policy can report original bill tax amounts; this does not add tax to the customer bill or establish a federal exemption. A return requires its original accepted IMS reference from this environment.</p>
<form method="post" action="{{ route('pos.tax-integrations.ims.prepare',$record->id) }}">@csrf<label for="payload">Exact IMS JSON payload</label><textarea id="payload" name="payload_json" spellcheck="false" @readonly(!in_array($record->state,['draft','rejected']))>{{ old('payload_json',json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION)) }}</textarea>
@if(in_array($record->state,['draft','rejected']))<label><input type="checkbox" name="mapping_reviewed" value="1" required>I reviewed federal tax treatment, amounts, buyer information and the IMS environment.</label><button>Save payload for review</button>@endif</form>
@if($record->state==='draft' && $record->payload)<form method="post" action="{{ route('pos.tax-integrations.ims.submit',$record->id) }}">@csrf<label><input type="checkbox" name="confirm_submit" value="1" required>Submit to {{ strtoupper($record->environment) }} FBR POS {{ $record->connection_mode==='fiscal_device' ? 'through the designated local IMS component' : 'cloud API' }}. {{ $record->environment==='production' ? 'This reports a real invoice.' : 'This is a sandbox test.' }}</label><button>Submit reviewed IMS invoice</button></form>@endif
</section></div>
@endsection
