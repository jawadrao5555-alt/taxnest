@php
$context = $promotion ? (string)$promotion->id : 'new';
$restoring = $errors->any() && old('form_context') === $context;
$value = fn($key, $default = '') => $restoring ? old($key, $default) : ($promotion?->$key ?? $default);
$zone = $value('timezone', $timezone);
$starts = $restoring ? old('starts_at') : ($promotion ? \Carbon\CarbonImmutable::parse($promotion->starts_at, 'UTC')->setTimezone($zone)->format('Y-m-d\TH:i') : '');
$ends = $restoring ? old('ends_at') : ($promotion ? \Carbon\CarbonImmutable::parse($promotion->ends_at, 'UTC')->setTimezone($zone)->format('Y-m-d\TH:i') : '');
@endphp
<form method="POST" action="{{ $promotion ? route('pos.promotions.update', $promotion->id) : route('pos.promotions.store') }}" class="mt-4 space-y-4">
    @csrf @if($promotion) @method('PUT') @endif
    <input type="hidden" name="form_context" value="{{ $context }}">
    <div class="grid sm:grid-cols-2 gap-4">
    @foreach(['title'=>'name','percent'=>'percent','priority'=>'priority','timezone'=>'timezone'] as $key=>$label)
    <label class="text-sm">{{ __('pos_promotions.'.$label) }}<input class="block w-full rounded-lg border-gray-300 dark:bg-gray-800" name="{{ $key }}" value="{{ $value($key, $key === 'priority' ? 0 : ($key === 'timezone' ? $timezone : '')) }}" required @if($key==='percent') type="number" min="0.01" max="100" step="0.01" @elseif($key==='priority') type="number" min="0" max="9999" @else type="text" maxlength="{{ $key==='title' ? 120 : 64 }}" @endif></label>
    @endforeach
    <label class="text-sm">{{ __('pos_promotions.starts') }}<input class="block w-full rounded-lg border-gray-300 dark:bg-gray-800" type="datetime-local" name="starts_at" value="{{ $starts }}" required></label>
    <label class="text-sm">{{ __('pos_promotions.ends') }}<input class="block w-full rounded-lg border-gray-300 dark:bg-gray-800" type="datetime-local" name="ends_at" value="{{ $ends }}" required></label>
    </div>
    <label class="block text-sm">{{ __('pos_promotions.scope') }}<select class="block w-full rounded-lg border-gray-300 dark:bg-gray-800" name="applies_to">@foreach(['all','selected'] as $scope)<option value="{{ $scope }}" @selected($value('applies_to','all')===$scope)>{{ __('pos_promotions.'.$scope) }}</option>@endforeach</select></label>
    <fieldset class="border rounded-lg p-3"><legend>{{ __('pos_promotions.selected') }}</legend><div class="max-h-52 overflow-auto grid sm:grid-cols-2 gap-2">@foreach($products as $product)<label class="text-sm"><input type="checkbox" name="product_ids[]" value="{{ $product->id }}" @checked(in_array($product->id, $value('product_ids', [])))> {{ $product->name }} · {{ $product->price }}</label>@endforeach</div></fieldset>
    <input type="hidden" name="is_active" value="0"><label class="block text-sm"><input type="checkbox" name="is_active" value="1" @checked($value('is_active', false))> {{ __('pos_promotions.active') }}</label>
    <button class="px-4 py-2 bg-purple-700 text-white rounded-lg" type="submit">{{ __('pos_promotions.save') }}</button>
</form>
