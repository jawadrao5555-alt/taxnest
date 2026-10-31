@php $optional = \App\Support\PosReceiptOptions::saved($company, $stream); @endphp
<details class="rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 p-4" data-receipt-options="{{ $stream }}">
    <summary class="font-semibold cursor-pointer">{{ $stream === 'pra' ? 'Fiscal' : 'Local' }} receipt: optional details and layout</summary>
    <p class="text-sm text-gray-700 dark:text-gray-200 my-3">Keep current preserves your existing design. Changes apply only after Save. Invoice identity, fiscal QR, amounts, payment settlement and KOT routing stay intact.</p>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
    @foreach(\App\Support\PosReceiptOptions::VISIBILITY as $key => $label)
        <label class="block text-sm text-gray-900 dark:text-gray-100">{{ $label }}
            <select name="receipt_options_{{ $stream }}[{{ $key }}]" class="mt-1 w-full rounded-lg border-gray-400 bg-white text-gray-900 dark:bg-gray-800 dark:text-white">
                @foreach(['inherit' => 'Keep current', 'show' => 'Show', 'hide' => 'Hide'] as $value => $text)
                <option value="{{ $value }}" @selected(old('receipt_options_'.$stream.'.'.$key, $optional[$key] ?? 'inherit') === $value)>{{ $text }}</option>
                @endforeach
            </select>
        </label>
    @endforeach
    @foreach(\App\Support\PosReceiptOptions::CHOICES as $key => $choices)
        <label class="block text-sm text-gray-900 dark:text-gray-100">{{ \App\Support\PosReceiptOptions::LABELS[$key] }}
            <select name="receipt_options_{{ $stream }}[{{ $key }}]" class="mt-1 w-full rounded-lg border-gray-400 bg-white text-gray-900 dark:bg-gray-800 dark:text-white">
                @foreach($choices as $value => $text)
                <option value="{{ $value }}" @selected(old('receipt_options_'.$stream.'.'.$key, $optional[$key] ?? 'inherit') === $value)>{{ $text }}</option>
                @endforeach
            </select>
        </label>
    @endforeach
    </div>
    <label class="block text-sm text-gray-900 dark:text-gray-100 mt-3">Extra footer / complaint contact (optional)
        <input name="receipt_options_{{ $stream }}[extra_note]" maxlength="150" value="{{ old('receipt_options_'.$stream.'.extra_note', $optional['extra_note'] ?? '') }}" class="mt-1 w-full rounded-lg border-gray-400 bg-white text-gray-900 dark:bg-gray-800 dark:text-white">
    </label>
    <div class="flex flex-wrap gap-2 mt-3">
        <button type="button" data-print-design-preview="receipt" data-stream="{{ $stream }}" class="rounded-lg border border-gray-400 px-3 py-2 font-semibold text-sm">Preview {{ $stream === 'pra' ? 'Fiscal' : 'Local' }} receipt</button>
        <button type="button" data-print-design-preview="kot" data-stream="{{ $stream }}" class="rounded-lg border border-gray-400 px-3 py-2 font-semibold text-sm">Preview {{ $stream === 'pra' ? 'Fiscal' : 'Local' }} kitchen ticket</button>
    </div>
    <p class="text-sm mt-2">Preview never saves or prints. Existing font, logo and QR choices apply to every receipt design. Only Save applies your selected design.</p>
    <p class="text-sm text-gray-700 dark:text-gray-200 mt-3">Paper size, print alignment and left margin use the existing printer controls below. Extra QR size does not resize the fiscal verification QR. Floor is shown only with a table. Item row numbers are separate from invoice serials.</p>
</details>
