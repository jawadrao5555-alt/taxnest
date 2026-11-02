<x-pos-layout>
<div class="max-w-5xl mx-auto p-4 space-y-5">
    <a href="{{ route('pos.deals') }}" class="text-purple-700 underline">{{ __('pos.deals_title') }}</a>
    <h1 class="text-2xl font-bold">{{ __('pos_promotions.title') }}</h1>
    <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('pos_promotions.intro') }}</p>
    @if(session('success'))<p role="status" class="p-3 rounded-lg bg-emerald-50 text-emerald-800">{{ session('success') }}</p>@endif
    @if($errors->any())<div role="alert" class="p-3 rounded-lg bg-red-50 text-red-800">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <details class="p-4 rounded-xl border" @if($errors->any() && old('form_context') === 'new') open @endif>
        <summary class="font-semibold cursor-pointer">{{ __('pos_promotions.new') }}</summary>
        @include('pos.partials.promotion-form', ['promotion'=>null])
    </details>
    @forelse($promotions as $promotion)
    @php($state = !$promotion->is_active ? 'disabled' : (now('UTC')->format('Y-m-d H:i:s') < $promotion->starts_at ? 'scheduled' : (now('UTC')->format('Y-m-d H:i:s') >= $promotion->ends_at ? 'expired' : 'live')))
    <details class="p-4 rounded-xl border" @if($errors->any() && old('form_context') === (string)$promotion->id) open @endif>
        <summary class="font-semibold cursor-pointer">{{ $promotion->title }} · {{ $promotion->percent }}% · {{ __('pos_promotions.'.$state) }}</summary>
        <p class="text-sm my-2">{{ \Carbon\CarbonImmutable::parse($promotion->starts_at, 'UTC')->setTimezone($promotion->timezone)->format('Y-m-d H:i') }} → {{ \Carbon\CarbonImmutable::parse($promotion->ends_at, 'UTC')->setTimezone($promotion->timezone)->format('Y-m-d H:i') }} · {{ $promotion->timezone }}</p>
        @include('pos.partials.promotion-form')
    </details>
    @empty<p>{{ __('pos_promotions.empty') }}</p>@endforelse
</div>
</x-pos-layout>
