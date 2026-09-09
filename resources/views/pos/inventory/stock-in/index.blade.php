<x-pos-layout>
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    @include('pos.partials.back-link')
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-teal-500 to-emerald-700 flex items-center justify-center shadow-lg">
                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            </div>
            {{ __('pos.stock_in') }}
        </h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.stock_in_sub') }}</p>
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

    @if($multiBranch)
    <div class="mb-6 rounded-xl border-2 border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 p-5">
        <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">{{ __('pos.stock_in_multi_branch_blocked') }}</p>
    </div>
    @else
    @if($openBatch)
    <div class="mb-6 rounded-2xl border-2 border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 p-5">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <p class="text-xs font-bold uppercase tracking-widest text-amber-700 dark:text-amber-400">{{ __('pos.stock_in_open_now') }}</p>
                <p class="text-lg font-bold text-gray-900 dark:text-white mt-1">{{ $openBatch->reference }}</p>
                <p class="text-xs text-gray-600 dark:text-gray-400 mt-0.5">{{ $openBatch->line_count }} {{ __('pos.stock_in_rows') }} · {{ $openBatch->original_filename }}</p>
            </div>
            <a href="{{ route('pos.inventory.stock-in.show', $openBatch->id) }}" class="flex-shrink-0 inline-flex items-center px-5 py-2.5 bg-amber-600 hover:bg-amber-700 text-white text-sm font-semibold rounded-xl transition shadow-sm">
                {{ __('pos.stock_in_continue') }}
            </a>
        </div>
    </div>
    @endif

    <div class="mb-4 bg-teal-50 dark:bg-teal-900/20 border border-teal-200 dark:border-teal-700 rounded-xl px-4 py-3">
        <p class="text-xs text-teal-900 dark:text-teal-200 font-semibold">{{ __('pos.stock_in_not_master') }}</p>
    </div>

    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-md p-5 mb-6">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                <h4 class="text-xs font-bold text-gray-700 dark:text-gray-300 uppercase mb-2">{{ __('pos.stock_in_step1') }}</h4>
                <p class="text-xs text-gray-500 mb-3">{{ __('pos.stock_in_step1_hint') }}</p>
                <a href="{{ route('pos.inventory.stock-in.template') }}" class="inline-flex items-center gap-1.5 bg-gradient-to-r from-teal-500 to-emerald-700 text-white px-5 py-2 rounded-lg text-xs font-semibold shadow-md hover:shadow-lg transition no-underline">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    {{ __('pos.stock_in_download') }}
                </a>
            </div>
            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                <h4 class="text-xs font-bold text-gray-700 dark:text-gray-300 uppercase mb-2">{{ __('pos.stock_in_step2') }}</h4>
                <p class="text-xs text-gray-500 mb-3">{{ __('pos.stock_in_step2_hint') }}</p>
                <form method="POST" action="{{ route('pos.inventory.stock-in.store') }}" enctype="multipart/form-data" class="space-y-3">
                    @csrf
                    <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300">{{ __('pos.stock_in_reference') }}</label>
                    <input type="text" name="reference" required maxlength="100" value="{{ old('reference') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-sm" placeholder="INV-88">
                    <input type="file" name="excel_file" accept=".xlsx,.xls" required class="block w-full text-sm text-gray-500 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100 dark:file:bg-teal-900/30 dark:file:text-teal-300">
                    <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400">
                        <input type="checkbox" name="update_cost" value="1" class="rounded border-gray-300">
                        {{ __('pos.stock_in_update_cost') }}
                    </label>
                    <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400">
                        <input type="checkbox" name="save_supplier_code" value="1" class="rounded border-gray-300">
                        {{ __('pos.stock_in_save_supplier_code') }}
                    </label>
                    <button type="submit" class="inline-flex items-center gap-1.5 bg-gradient-to-r from-emerald-500 to-emerald-700 text-white px-5 py-2 rounded-lg text-xs font-semibold shadow-md hover:shadow-lg transition">
                        {{ __('pos.stock_in_upload') }}
                    </button>
                </form>
            </div>
        </div>
        <p class="mt-4 text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.stock_in_columns_note') }}</p>
    </div>
    @endif

    @if(($batches ?? collect())->isNotEmpty())
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-lg overflow-hidden">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('pos.stock_in_reference') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('pos.stock_in_status') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('pos.stock_in_rows') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($batches as $row)
                <tr class="border-t border-gray-100 dark:border-gray-800">
                    <td class="px-4 py-2 font-medium text-gray-900 dark:text-white">{{ $row->reference }}</td>
                    <td class="px-4 py-2 text-gray-600 dark:text-gray-300">{{ $row->status }}</td>
                    <td class="px-4 py-2">{{ $row->line_count }}</td>
                    <td class="px-4 py-2 text-right">
                        <a href="{{ route('pos.inventory.stock-in.show', $row->id) }}" class="text-teal-700 dark:text-teal-300 text-xs font-semibold">{{ __('pos.stock_in_open') }}</a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
</x-pos-layout>
