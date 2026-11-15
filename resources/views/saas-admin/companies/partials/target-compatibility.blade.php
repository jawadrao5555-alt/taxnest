<div class="space-y-2 text-xs" data-product-compatibility="{{ $target }}">
@if(in_array($target, ['pos','fbrpos'], true))
<label class="block">Target category
<select name="{{ $categoryField }}" class="block w-full bg-gray-950 border border-gray-700 rounded p-2">
<option value="">Select category</option>
@foreach(\App\Services\PosFeatureService::categories($target === 'fbrpos' ? 'fbr' : 'pra') as $category)
<option value="{{ $category }}">{{ $category === 'clinic' ? 'Hospital / Clinic' : ($category === 'education' ? 'School / Education' : ucfirst(str_replace('_',' ', $category))) }}</option>
@endforeach
</select></label>
@endif
<label class="block">Target package (request only)
<select name="{{ $packageField }}" class="block w-full bg-gray-950 border border-gray-700 rounded p-2">
<option value="">Select package</option><option value="trial">Trial only / no paid package requested</option>
@foreach(\App\Models\PricingPlan::whereIn('product_type', \App\Support\NestErps::storedTypesFor($target))->where('is_trial', false)->get() as $candidate)
@unless(\App\Services\PlanSellabilityService::isRetired($candidate))<option value="{{ $candidate->name }}">{{ $candidate->name }}</option>@endunless
@endforeach
</select></label>
<p class="text-gray-400">No paid access or history is copied. School and Hospital fiscal reporting stays OFF. Review product, category and package before submitting.</p>
</div>
