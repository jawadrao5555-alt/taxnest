<x-pos-layout>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    @include('pos.partials.back-link')
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
            {{ __('pos.stock_in') }}
            <span class="text-sm font-semibold text-gray-500">{{ $batch->reference }}</span>
        </h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $batch->original_filename }} · {{ __('pos.stock_in_status') }}: {{ $batch->status }}</p>
    </div>

    @include('pos.inventory.partials.nav-tabs', ['active' => 'stock-in'])

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-400 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 text-sm">{{ session('error') }}</div>
    @endif
    @if($errors->any())
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 text-sm">{{ $errors->first() }}</div>
    @endif

    <div class="flex flex-wrap gap-2 mb-4">
        @if($batch->status === \App\Models\PosStockInBatch::STATUS_OPEN)
        <form method="POST" action="{{ route('pos.inventory.stock-in.rematch', $batch->id) }}">
            @csrf
            <button type="submit" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700">{{ __('pos.stock_in_rematch') }}</button>
        </form>
        <form method="POST" action="{{ route('pos.inventory.stock-in.cancel', $batch->id) }}" onsubmit="return confirm(@json(__('pos.stock_in_cancel_confirm')))">
            @csrf
            <button type="submit" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 border border-red-200 text-red-700">{{ __('pos.stock_in_cancel') }}</button>
        </form>
        @endif
        <a href="{{ route('pos.inventory.stock-in.index') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700">{{ __('pos.stock_in_back_list') }}</a>
    </div>

    @if($batch->status === \App\Models\PosStockInBatch::STATUS_OPEN)
    <form id="stock-in-post" method="POST" action="{{ route('pos.inventory.stock-in.post', $batch->id) }}" class="mb-4 flex flex-wrap items-center gap-4 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-4">
        @csrf
        <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400">
            <input type="checkbox" name="update_cost" value="1" @checked($batch->update_cost) class="rounded border-gray-300">
            {{ __('pos.stock_in_update_cost') }}
        </label>
        <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400">
            <input type="checkbox" name="save_supplier_code" value="1" @checked($batch->save_supplier_code) class="rounded border-gray-300">
            {{ __('pos.stock_in_save_supplier_code') }}
        </label>
        <button type="submit" class="ml-auto inline-flex items-center px-5 py-2.5 bg-teal-600 hover:bg-teal-700 text-white text-sm font-semibold rounded-xl" {{ $readyCount < 1 ? 'disabled' : '' }}>
            {{ __('pos.stock_in_post') }} ({{ $readyCount }})
        </button>
    </form>
    @endif

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-lg overflow-x-auto">
        <table class="min-w-full text-xs">
            <thead class="bg-gray-50 dark:bg-gray-800 text-gray-500 uppercase">
                <tr>
                    <th class="px-3 py-2"></th>
                    <th class="px-3 py-2 text-left">#</th>
                    <th class="px-3 py-2 text-left">{{ __('pos.stock_in_line_type') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('pos.stock_in_code') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('pos.stock_in_name') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('pos.stock_in_qty') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('pos.stock_in_unit') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('pos.stock_in_match') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('pos.stock_in_reason') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($batch->lines as $line)
                @php
                    $badge = [
                        'MATCHED' => 'bg-emerald-100 text-emerald-800',
                        'NEEDS_CLEARANCE' => 'bg-amber-100 text-amber-800',
                        'INVALID' => 'bg-red-100 text-red-800',
                        'SKIPPED_GATE' => 'bg-gray-200 text-gray-700',
                    ][$line->match_status] ?? 'bg-gray-100 text-gray-700';
                @endphp
                <tr class="border-t border-gray-100 dark:border-gray-800 {{ $line->posted_at ? 'opacity-60' : '' }}">
                    <td class="px-3 py-2">
                        @if($batch->status === \App\Models\PosStockInBatch::STATUS_OPEN && $line->isPostable())
                        <input type="checkbox" form="stock-in-post" name="selected[]" value="{{ $line->id }}" @checked($line->selected)>
                        @endif
                    </td>
                    <td class="px-3 py-2">{{ $line->source_row_no }}</td>
                    <td class="px-3 py-2 font-semibold">{{ $line->line_type }}</td>
                    <td class="px-3 py-2">{{ $line->nestpos_code_raw ?: $line->supplier_item_code }}</td>
                    <td class="px-3 py-2">{{ $line->supplier_item_name }}</td>
                    <td class="px-3 py-2 text-right">{{ $line->qty }}</td>
                    <td class="px-3 py-2">{{ $line->unit }}</td>
                    <td class="px-3 py-2"><span class="px-2 py-0.5 rounded-full font-semibold {{ $badge }}">{{ $line->match_status }}</span></td>
                    <td class="px-3 py-2 text-gray-600 dark:text-gray-400">
                        {{ $line->invalid_reason }}
                        @if($line->posted_at)
                            <span class="block text-emerald-700">{{ __('pos.stock_in_line_posted') }}</span>
                        @endif
                        @if($batch->status === \App\Models\PosStockInBatch::STATUS_OPEN && $line->match_status === \App\Models\PosStockInLine::NEEDS_CLEARANCE && !$line->posted_at)
                        <form method="POST" action="{{ route('pos.inventory.stock-in.map', [$batch->id, $line->id]) }}" class="mt-2 flex flex-wrap gap-1">
                            @csrf
                            @if($line->line_type === \App\Models\PosStockInLine::TYPE_INGREDIENT)
                            <select name="ingredient_id" class="text-xs rounded border-gray-300 dark:bg-gray-900">
                                <option value="">{{ __('pos.stock_in_pick_ingredient') }}</option>
                                @foreach($ingredients as $ing)
                                <option value="{{ $ing->id }}">{{ $ing->name }} ({{ $ing->unit }}) {{ $ing->code }}</option>
                                @endforeach
                            </select>
                            @else
                            <select name="product_id" class="text-xs rounded border-gray-300 dark:bg-gray-900">
                                <option value="">{{ __('pos.stock_in_pick_item') }}</option>
                                @foreach($products as $p)
                                <option value="{{ $p->id }}">{{ $p->name }} {{ $p->sku ?: $p->barcode }}</option>
                                @endforeach
                            </select>
                            @endif
                            <button type="submit" class="px-2 py-1 rounded bg-amber-600 text-white text-[10px] font-semibold">{{ __('pos.stock_in_map') }}</button>
                        </form>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
</x-pos-layout>
