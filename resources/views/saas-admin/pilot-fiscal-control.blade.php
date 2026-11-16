<x-admin-layout>
<section id="pilot-fiscal-control" class="p-4 sm:p-6 max-w-3xl mx-auto space-y-4">
<h1 class="text-2xl font-bold">{{ ucfirst($state['category']) }} fiscal control</h1>
<p>{{ $company->name }} (#{{ $company->id }})</p>
<p data-pilot-fiscal-status class="font-bold">OFF — blocked: official category reporting route and sandbox verification are unavailable.</p>
@if($rejected ?? false)<p role="alert">ON was rejected. Nothing is armed for later submission.</p>@endif
<p>This is not retail FBR POS or PRA approval. No bill will be submitted through automatic dispatch, manual retry or background queues. Existing receipts, payments, saved closings and acknowledgements are unchanged.</p>
<h2 class="text-lg font-bold">Required before functional ON can be implemented</h2>
<ul class="list-disc pl-6">@foreach($state['required_evidence'] as $requirement)<li>{{ $requirement }}</li>@endforeach</ul>
<details data-pilot-fiscal-readiness>
<summary class="font-semibold cursor-pointer">{{ ucfirst($state['readiness']['category']) }} verification checklist</summary>
<p>IMS and DI are independent candidates. Verification for another route, category or company cannot activate this company.</p>
@foreach($state['readiness']['routes'] as $candidate)
<p>{{ strtoupper($candidate['route']) }}: blocked; adapter and company/category sandbox acceptance unverified.</p>
@endforeach
<p>This checklist reports missing evidence. It cannot approve or enable an integration.</p>
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
<option value="off" selected>OFF — local billing only</option>
<option value="ims" disabled>IMS — unverified and unavailable</option>
<option value="di" disabled>DI — unverified and unavailable</option>
</select>
<button id="pilot-fiscal-off" class="p-3 rounded bg-slate-700 text-white" type="submit">Confirm OFF for this company</button>
</form>
<button id="pilot-fiscal-on" class="p-3 rounded border opacity-60" type="button" disabled aria-describedby="pilot-fiscal-blocker">ON unavailable</button>
<p id="pilot-fiscal-blocker">The server also rejects direct ON requests. No requested-ON preference is stored.</p>
</section>
</x-admin-layout>
