<x-fbr-pos-layout>
<div class="max-w-3xl mx-auto">
    @include('fbr-pos.partials.back-link')
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-1">{{ __('pos.loyalty_program') }}</h1>
    <p class="text-sm text-gray-500 mb-6">{{ __('pos.loyalty_program_desc') }}</p>

    @if(session('success'))<div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded mb-4">{{ session('success') }}</div>@endif

    <form method="POST" action="{{ route('fbrpos.phase2.loyalty') }}" class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 space-y-5">
        @csrf
        <label class="flex items-center gap-3">
            <input type="checkbox" name="is_enabled" value="1" {{ $settings->is_enabled ? 'checked' : '' }} class="w-5 h-5 rounded">
            <span class="font-semibold dark:text-white">{{ __('pos.enable_loyalty_program') }}</span>
        </label>

        <div class="grid sm:grid-cols-2 gap-4">
            <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.earn_1_point_per_rs') }}</label>
                <input type="number" name="rs_per_point" value="{{ $settings->rs_per_point }}" required step="1" min="1" class="w-full border rounded-lg px-3 py-2 dark:bg-gray-700 dark:text-white">
                <p class="text-xs text-gray-500 mt-1">{{ __('pos.earn_1_point_per_rs_hint') }}</p></div>
            <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.one_point_equals_rs') }}</label>
                <input type="number" name="point_value" value="{{ $settings->point_value }}" required step="0.01" min="0.01" class="w-full border rounded-lg px-3 py-2 dark:bg-gray-700 dark:text-white">
                <p class="text-xs text-gray-500 mt-1">{{ __('pos.one_point_value_hint') }}</p></div>
            <div><label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.min_points_to_redeem') }}</label>
                <input type="number" name="min_redeem_points" value="{{ $settings->min_redeem_points }}" required min="1" class="w-full border rounded-lg px-3 py-2 dark:bg-gray-700 dark:text-white"></div>
        </div>

        <div class="bg-blue-50 dark:bg-blue-900/30 p-4 rounded-lg text-sm text-blue-900 dark:text-blue-200">
            <strong>{{ __('pos.example_colon') }}</strong> {{ __('pos.loyalty_example_line', [
                'points' => floor(5000 / max($settings->rs_per_point, 1)),
                'min' => $settings->min_redeem_points,
                'amount' => number_format($settings->min_redeem_points * $settings->point_value, 2),
            ]) }}
        </div>

        <button class="bg-blue-600 text-white rounded-lg px-5 py-2.5 font-semibold hover:bg-blue-700">{{ __('pos.save_settings') }}</button>
    </form>
</div>
</x-fbr-pos-layout>
