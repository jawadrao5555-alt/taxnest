<section class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-5">
    <h2 class="font-semibold text-gray-900 dark:text-white">{{ __('pos.receipt_designs_basic') }}</h2>
    @foreach(['pra', 'local'] as $stream)
    @php($designPrefs = \App\Support\PosReceiptOptions::saved($company, $stream))
    <div class="mt-4">
        <h3 class="text-sm font-semibold">{{ $stream === 'pra' ? __('pos.pra_receipt') : __('pos.local_receipt') }}</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2">
        @foreach(['design' => 'receipt', 'kot_design' => 'kot'] as $key => $kind)
            <div>
                <label class="block text-sm">{{ \App\Support\PosReceiptOptions::LABELS[$key] }}
                    <select name="receipt_options_{{ $stream }}[{{ $key }}]" class="mt-1 w-full rounded-lg border-gray-300 bg-white text-gray-900 dark:bg-gray-800 dark:text-white">
                        @foreach(\App\Support\PosReceiptOptions::CHOICES[$key] as $value => $label)
                        <option value="{{ $value }}" @selected(old('receipt_options_'.$stream.'.'.$key, $designPrefs[$key] ?? 'inherit') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <button type="button" data-print-design-preview="{{ $kind }}" data-stream="{{ $stream }}" class="mt-2 rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold">{{ __('pos.preview') }}</button>
            </div>
        @endforeach
        </div>
    </div>
    @endforeach
</section>
