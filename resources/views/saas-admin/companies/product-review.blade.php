<x-admin-layout>
<div class="p-4 sm:p-6 max-w-3xl mx-auto space-y-4">
<h1 class="text-2xl font-bold">Review product, category and package</h1>
<p>{{ $company->name }} · {{ \App\Support\ProductCatalog::label($company->product_type) }}</p>
<p>This page contains no saved credentials. Only a SaaS super-admin can submit. School and Hospital fiscal reporting stays OFF.</p>
@if($errors->any())<div role="alert">{{ $errors->first() }}</div>@endif
<p>{{ $cloning ? 'Create separate target accounts. Source history, team and paid access stay unchanged. Each new account starts its own trial; selected paid packages are requests only.' : 'Switch only an unused company. Existing history, campus configuration or access blocks this action; use a separate product account instead.' }}</p>
<form method="POST" action="{{ route($cloning ? 'saas.admin.companies.cloneProduct' : 'saas.admin.companies.changeType', $company->id) }}" class="space-y-4">
@csrf
@foreach($targets as $target)
<section class="border rounded p-4 space-y-3"><h2>{{ \App\Support\ProductCatalog::label($target) }}</h2>
<input type="hidden" name="{{ $cloning ? 'products[]' : 'product_type' }}" value="{{ $target }}">
@include('saas-admin.companies.partials.target-compatibility', ['categoryField'=>$cloning ? 'target_categories['.$target.']' : 'target_category', 'packageField'=>$cloning ? 'target_packages['.$target.']' : 'target_package'])
@if(\App\Support\NestErps::isProductType($target))<label>Vertical<select name="erps_vertical">@foreach(\App\Support\NestErps::verticals() as $vKey=>$vMeta)<option value="{{ $vKey }}">{{ $vMeta['label'] }}</option>@endforeach</select></label>@endif
</section>
@endforeach
<button type="submit" class="px-4 py-2 rounded bg-indigo-600 text-white">{{ $cloning ? 'Validate and create separate accounts' : 'Validate and switch unused company' }}</button>
<a href="{{ route('saas.admin.companies.show', $company->id) }}">Cancel</a>
</form>
</div>
</x-admin-layout>
