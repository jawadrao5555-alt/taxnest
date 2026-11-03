@extends('pos.di-sandbox.layout')
@section('title', 'PRA + FBR POS · IMS configuration')
@section('content')
<h2>{{ $company->name }}</h2>
<p>IMS reporting: <strong>{{ $profile?->enabled ? 'Enabled' : 'Disabled' }}</strong> · Selected environment: <strong>{{ strtoupper($profile?->environment ?? 'sandbox') }}</strong></p>
<section class="card"><form method="post" action="{{ route('pos.tax-integrations.ims.save') }}">@csrf
    <label for="enabled">Enable FBR POS alongside PRA</label><select id="enabled" name="enabled"><option value="0" @selected(!old('enabled',$profile?->enabled))>Disabled</option><option value="1" @selected(old('enabled',$profile?->enabled))>Enabled · select IMS as federal reporting channel</option></select>
    <label for="environment">Active environment</label><select id="environment" name="environment">@foreach(['sandbox','production'] as $env)<option value="{{ $env }}" @selected(old('environment',$profile?->environment ?? 'sandbox')===$env)>{{ ucfirst($env) }}</option>@endforeach</select>
    <label for="mode">IMS connection</label><select id="mode" name="connection_mode"><option value="cloud" @selected(old('connection_mode',$profile?->connection_mode ?? 'cloud')==='cloud')>Cloud API · supported older POS registrations</option><option value="fiscal_device" @selected(old('connection_mode',$profile?->connection_mode)==='fiscal_device')>Local FBR IMS · dedicated Desktop Agent device</option></select>
    <div class="columns">@foreach(['sandbox','production'] as $env)<section class="card"><h2>{{ ucfirst($env) }} configuration</h2>
        <label for="{{ $env }}-id">{{ ucfirst($env) }} POS ID</label><input id="{{ $env }}-id" name="{{ $env }}_pos_id" type="text" value="{{ old($env.'_pos_id',$profile?->{$env.'_pos_id'}) }}">
        <label for="{{ $env }}-url">{{ ucfirst($env) }} API endpoint</label><input id="{{ $env }}-url" name="{{ $env }}_url" type="url" value="{{ old($env.'_url',$profile?->{$env.'_url'} ?: \App\Services\PosTaxIntegrationEndpoints::url('ims',$env)) }}" required>
        <label for="{{ $env }}-token">{{ ucfirst($env) }} token · {{ $profile?->{$env.'_token'} ? 'Saved' : 'Not configured' }}</label><input id="{{ $env }}-token" name="{{ $env }}_token" type="password" autocomplete="new-password" maxlength="8192">
    </section>@endforeach</div>
    <p class="muted">Official cloud endpoints only. Blank tokens keep saved values. Local IMS uses its own installed credentials; no cloud token is sent to the local component.</p>
    <h2>Local FBR IMS device</h2><p>Use the FBR component on the designated shop PC. Its URL must identify FBR IMS, not PRA IMS. Both components need separate listeners when installed on one PC. Install an Agent build supporting “FBR alongside PRA”; older Agents continue PRA work and cannot dispatch this queue.</p>
    <label for="device">Dedicated FBR device UID</label><input id="device" name="device_uid" type="text" value="{{ old('device_uid',$profile?->device_uid) }}">
    <label for="local-url">FBR IMS local URL</label><input id="local-url" name="local_ims_url" type="url" placeholder="http://127.0.0.1:8525/api/IMSFiscal/GetInvoiceNumberByModel" value="{{ old('local_ims_url',$profile?->local_ims_url) }}">
    <label><input name="confirm_setup" type="checkbox" value="1">I reviewed the IMS connection, POS ID and federal tax mapping. When enabled, select IMS for new federal submissions and retain DI history.</label>
    <label><input name="confirm_production" type="checkbox" value="1">For Production: this company's FBR POS registration and credentials are live; the selected local component (if used) belongs to this POS and environment. Enable live reporting.</label>
    <button>Save FBR POS setup</button>
</form></section>
<section class="card"><h2>Prepare one completed bill</h2><p>Capture never sends or creates a second sale. Review the federal mapping before explicitly submitting.</p>
@if($profile?->enabled)<form method="post" action="{{ route('pos.tax-integrations.ims.capture') }}">@csrf<input type="hidden" name="environment" value="{{ $profile->environment }}">
    <label for="bill">Completed POS bill</label><select id="bill" name="transaction_id" required><option value="">Select a bill</option>@foreach($sales as $sale)<option value="{{ $sale->id }}">{{ $sale->invoice_number }} · {{ $sale->payment_method }} · Rs {{ number_format($sale->total_amount,2) }}</option>@endforeach</select>
    <label><input type="checkbox" name="confirm_copy" value="1" required>I authorize this bill for {{ strtoupper($profile->environment) }} IMS preparation. {{ $profile->environment==='production' ? 'This is a real completed sale.' : 'This is a sandbox test copy.' }}</label><button>Prepare IMS record</button>
</form>@else<p>Enable IMS above before preparing a bill.</p>@endif</section>
<section class="card"><h2>IMS history · both environments</h2><table><thead><tr><th>Bill</th><th>Environment</th><th>State</th><th>Review</th></tr></thead><tbody>@forelse($records as $r)<tr><td>{{ $r->snapshot['invoice_number'] }}</td><td>{{ $r->environment }}</td><td>{{ $r->state }}</td><td><a href="{{ route('pos.tax-integrations.ims.show',$r->id) }}">Open</a></td></tr>@empty<tr><td colspan="4">No IMS records yet.</td></tr>@endforelse</tbody></table></section>
@endsection
