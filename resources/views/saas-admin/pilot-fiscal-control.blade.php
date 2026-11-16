<x-admin-layout>
<section id="pilot-fiscal-control" class="p-4 sm:p-6 max-w-3xl mx-auto space-y-4">
<h1 class="text-2xl font-bold">{{ ucfirst($state['category']) }} fiscal control</h1>
<p>{{ $company->name }} (#{{ $company->id }})</p>
<p data-pilot-fiscal-status class="font-bold">{{ $state['enabled'] ? 'FBR IMS ON — new finalized receipts only' : 'Fiscal OFF — local billing only' }}</p>
@if($rejected ?? false)<p role="alert">IMS ON was not saved because this company’s POS ID or Desktop Agent setup is incomplete.</p>@endif
<p>School/Hospital internal dues and balances remain local. When ON, only a newly finalized received-payment receipt is queued through the existing standalone FBR POS Desktop Agent IMS flow. Historical OFF receipts are never backfilled.</p>
<h2 class="text-lg font-bold">Setup / acceptance position</h2>
<ul class="list-disc pl-6">@foreach($state['required_evidence'] as $requirement)<li>{{ $requirement }}</li>@endforeach</ul>
<details data-pilot-fiscal-readiness>
<summary class="font-semibold cursor-pointer">{{ ucfirst($state['readiness']['category']) }} verification checklist</summary>
<p>IMS is the active candidate. DI remains a separate future option. Authorized sandbox acceptance is evidence outside synthetic CI.</p>
@foreach($state['readiness']['routes'] as $candidate)
<p>{{ strtoupper($candidate['route']) }}: {{ $candidate['route']==='ims' ? 'standalone Agent transport available; company acceptance still to be tested' : 'not part of this release' }}.</p>
@endforeach
<p>This checklist reports evidence; it does not claim a real FBR acknowledgement before an authorized test receipt succeeds.</p>
<dl class="space-y-3">
@foreach($state['readiness']['requirements'] as $requirement)
<div><dt class="font-semibold">{{ $requirement['id'] }}: {{ $requirement['status'] }}</dt><dd>{{ $requirement['description'] }}</dd></div>
@endforeach
</dl>
<p>{{ $state['readiness']['credential_instruction'] }}</p>
</details>
<p>Historical local bills must not be promoted by enabling a future verified route. Only newly eligible bills may qualify after a separately reviewed implementation.</p>
<form method="post" action="{{ route('saas.admin.pilot-fiscal-control.update',$company->id) }}">@csrf
<label for="pilot-fiscal-route">Reporting route</label>
<select id="pilot-fiscal-route" name="route" class="block w-full rounded border p-2" aria-describedby="pilot-fiscal-blocker">
<option value="off" @selected(!$state['enabled'])>OFF — local billing only</option>
<option value="ims" @selected($state['enabled'])>IMS — standalone FBR POS Desktop Agent</option>
<option value="di" disabled>DI — future option</option>
</select>
<button id="pilot-fiscal-off" class="p-3 rounded bg-slate-700 text-white" type="submit">Save fiscal route</button>
</form>
<p id="pilot-fiscal-blocker">IMS ON requires this company’s FBR POS ID and enabled Desktop Agent. Production submissions remain owner-controlled.</p>
</section>
</x-admin-layout>
