<x-admin-layout>
<div class="max-w-5xl mx-auto p-4 text-white space-y-4">
<h1 class="text-xl font-bold">Reviewed fiscal-device evidence: {{ $company->name }}</h1>
<p>Without reviewed Production evidence, a number-only response stays verification pending. A heartbeat or an agent's own claim is not independent proof. The device must already be registered to this company. School and Hospital evidence must match the fiscal device, POS ID and environment configured in the workspace. Record only evidence you personally reviewed for this exact company, device fingerprint, POS ID and environment. This does not enable reporting, authorize School/Hospital routes, or prove central FBR verification.</p>
<a href="{{ route('saas.admin.companies.show',$company->id) }}">Back to company</a>
@if(session('success'))<p role="status">{{ session('success') }}</p>@endif
@if($errors->any())<div role="alert">{{ $errors->first() }}</div>@endif
<p>Review the registered device against your IMS setup and the matching Live FBR portal record. Once Production evidence is recorded, future matching number-only responses can update School and Hospital receipts automatically. Existing pending receipts require their own invoice reconciliation.</p>
<form method="POST"  action="{{ route('saas.admin.device-proofs.store',$company->id) }}" class="space-y-3">
@csrf
<label class="block">Device fingerprint
<select name="device_uid" required class="block w-full bg-gray-800 rounded p-2">
<option value="">Select registered Agent device</option>
@foreach(($devices ?? collect()) as $device)
<option value="{{ $device }}" @selected(old('device_uid') === $device)>{{ $device }}</option>
@endforeach
</select></label>
<label class="block">POS ID<input name="pos_id" required maxlength="64" value="{{ old('pos_id', $company->fbr_pos_id) }}" class="block w-full bg-gray-800 rounded p-2"></label>
@foreach(['evidence_reference'=>'Non-secret evidence reference','evidence_sha256'=>'Evidence SHA-256'] as $name=>$label)
<label class="block">{{ $label }}<input name="{{ $name }}" required value="{{ old($name) }}" class="block w-full bg-gray-800 rounded p-2" maxlength="{{ $name==='evidence_reference'?300:($name==='device_uid'?128:64) }}"></label>
@endforeach
<label for="reviewed-proof-environment" class="block">Environment</label><select id="reviewed-proof-environment" name="environment" class="block bg-gray-800 p-2"><option value="sandbox" @selected(old('environment', $company->fbr_pos_environment) === 'sandbox')>Sandbox</option><option value="production" @selected(old('environment', $company->fbr_pos_environment) === 'production')>Production</option></select>
<label class="block">Review findings<textarea name="review_note" required minlength="10" maxlength="2000" class="block w-full bg-gray-800 p-2"></textarea></label>
<label class="block"><input type="checkbox" name="independent_evidence_reviewed" value="1" required> I reviewed independent device/POS/environment evidence. No passwords, tokens or payment credentials are included.</label>
<button class="border rounded p-2">Record reviewed proof</button>
</form>
@foreach($proofs as $proof)
@php $currentProof = \App\Services\ReviewedDeviceProof::reviewedProof((int)$company->id,$proof->device_uid,$proof->pos_id,$proof->environment); @endphp
<article class="border rounded p-3 break-words" data-proof-id="{{ $proof->id }}">
<p data-proof-current-state>{{ $currentProof ? 'Reviewed evidence recorded; not central verification' : 'HOLD: no current reviewed evidence' }}</p>
<p>#{{ $proof->id }} · {{ $proof->action }} · {{ $proof->device_uid }} · POS {{ $proof->pos_id }} · {{ $proof->environment }}</p>
<p>Reviewer #{{ $proof->actor_id }} · {{ $proof->created_at }}</p>
<p>{{ $proof->evidence_reference }} · {{ $proof->evidence_sha256 }}</p><p>{{ $proof->review_note }}</p>
@if($proof->action==='approve' && $currentProof && $currentProof->id===$proof->id)
<form method="POST" action="{{ route('saas.admin.device-proofs.revoke',[$company->id,$proof->id]) }}">@csrf
<label>Revocation reason<input name="reason" required minlength="10" maxlength="2000" class="block bg-gray-800 p-2 w-full"></label>
<button class="border rounded p-2">Revoke proof</button></form>
@endif
</article>
@endforeach
{{ $proofs->links() }}
</div>
</x-admin-layout>
