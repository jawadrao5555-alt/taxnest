<x-pos-layout>
    <div class="max-w-4xl mx-auto p-6">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">POS Features</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Choose your business category, then enable or disable individual features. Universal POS uses one screen for all categories.</p>
        </div>

        @if(session('success'))
            <div class="mb-4 p-3 rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-sm text-green-800 dark:text-green-300">{{ session('success') }}</div>
        @endif

        <form method="POST" action="{{ route('pos.features.update') }}" class="space-y-6">
            @csrf

            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-6">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Mode</h2>
                <div class="grid sm:grid-cols-2 gap-4">
                    <label class="flex items-start gap-3 p-3 rounded-xl border border-gray-200 dark:border-gray-700 cursor-pointer hover:border-purple-400">
                        <input type="checkbox" name="use_universal_pos" value="1" {{ $company->use_universal_pos ? 'checked' : '' }} class="mt-1 w-4 h-4 text-purple-600 rounded">
                        <div>
                            <div class="text-sm font-bold text-gray-900 dark:text-white">Use Universal POS (v2)</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">When on, /pos/invoice/create renders the universal POS using your feature flags below. When off, the legacy POS view is used.</div>
                        </div>
                    </label>
                    <div class="p-3 rounded-xl border border-gray-200 dark:border-gray-700">
                        <label class="text-xs font-semibold text-gray-700 dark:text-gray-300 block mb-2">UI Density</label>
                        <select name="pos_ui_density" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-sm px-3 py-2">
                            @foreach(['simple','standard','premium'] as $d)
                                <option value="{{ $d }}" @selected(($company->pos_ui_density ?? 'standard') === $d)>{{ ucfirst($d) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-6">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Business Category</h2>
                <div class="flex items-end gap-3">
                    <select name="business_category" class="flex-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-sm px-3 py-2">
                        @foreach($categories as $cat)
                            <option value="{{ $cat }}" @selected($company->business_category === $cat)>{{ ucfirst($cat) }}</option>
                        @endforeach
                    </select>
                    <button type="submit" formaction="{{ route('pos.features.reset') }}" class="px-4 py-2 text-xs font-semibold rounded-lg border border-orange-300 text-orange-700 hover:bg-orange-50">Reset Flags to Category Defaults</button>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">Category sets defaults only. Your individual feature toggles below always win.</p>
            </div>

            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-6">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Features</h2>
                <div class="grid sm:grid-cols-2 gap-3">
                    @foreach($allFlags as $flag)
                        <label class="flex items-center gap-3 p-3 rounded-xl border border-gray-200 dark:border-gray-700 hover:border-purple-400 cursor-pointer">
                            <input type="checkbox" name="feature_flags[{{ $flag }}]" value="1" {{ $features->{$flag} ? 'checked' : '' }} class="w-4 h-4 text-purple-600 rounded">
                            <div class="flex-1">
                                <div class="text-sm font-semibold text-gray-900 dark:text-white">{{ str_replace('_', ' ', ucwords($flag, '_')) }}</div>
                                @if(in_array($flag, ['kot','recipes','delivery','prescription','customer_loyalty']))
                                    <div class="text-[10px] text-amber-600 dark:text-amber-400 mt-0.5">Requires:
                                        @switch($flag)
                                            @case('kot') kitchen @break
                                            @case('recipes') inventory @break
                                            @case('delivery') customer_profile @break
                                            @case('prescription') customer_profile @break
                                            @case('customer_loyalty') customer_profile @break
                                        @endswitch
                                    </div>
                                @endif
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="flex justify-end gap-3">
                <a href="{{ route('pos.dashboard') }}" class="px-5 py-2.5 text-sm font-semibold rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800">Cancel</a>
                <button type="submit" class="px-6 py-2.5 text-sm font-bold rounded-lg bg-purple-600 hover:bg-purple-700 text-white">Save Settings</button>
            </div>
        </form>

        <div class="mt-6 p-4 rounded-xl bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800">
            <div class="text-sm text-blue-900 dark:text-blue-200 font-semibold mb-1">Test Universal POS (no commitment)</div>
            <div class="text-xs text-blue-800 dark:text-blue-300 mb-2">Open the universal POS in a new tab without flipping the master switch — your live POS stays untouched.</div>
            <a href="{{ route('pos.v2.invoice.create') }}" target="_blank" class="inline-block px-4 py-2 text-xs font-bold rounded-lg bg-blue-600 hover:bg-blue-700 text-white">Open /pos/v2/invoice/create →</a>
        </div>
    </div>
</x-pos-layout>
