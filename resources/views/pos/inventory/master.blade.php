<x-pos-layout>
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    @include('pos.partials.back-link')
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-700 flex items-center justify-center shadow-lg">
                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
            </div>
            {{ __('pos.inventory_master') }}
        </h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.inventory_master_sub') }}</p>
    </div>

    @if($inventoryOn ?? false)
        @include('pos.inventory.partials.nav-tabs', ['active' => 'master'])
    @endif

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-400 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 text-sm">{{ session('error') }}</div>
    @endif
    @if($errors->any())
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 text-sm">{{ $errors->first() }}</div>
    @endif
    @if(session('inventory_master_errors'))
    <div class="mb-4 p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300 text-sm">
        <p class="font-semibold mb-1">{{ __('pos.inventory_master_row_issues') }}</p>
        <ul class="list-disc pl-5 space-y-1">
            @foreach(array_slice(session('inventory_master_errors'), 0, 20) as $err)
            <li>{{ $err }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <div class="mb-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-xl px-4 py-3">
        <p class="text-xs text-amber-900 dark:text-amber-200 font-semibold">{{ __('pos.inventory_master_no_stock') }}</p>
    </div>

    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-md p-5">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                <h4 class="text-xs font-bold text-gray-700 dark:text-gray-300 uppercase mb-2">{{ __('pos.inventory_master_step1') }}</h4>
                <p class="text-xs text-gray-500 mb-3">{{ __('pos.inventory_master_step1_hint') }}</p>
                <a href="{{ route('pos.inventory-master.template') }}" class="inline-flex items-center gap-1.5 bg-gradient-to-r from-purple-500 to-purple-700 text-white px-5 py-2 rounded-lg text-xs font-semibold shadow-md hover:shadow-lg transition no-underline">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    {{ __('pos.inventory_master_download') }}
                </a>
            </div>
            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                <h4 class="text-xs font-bold text-gray-700 dark:text-gray-300 uppercase mb-2">{{ __('pos.inventory_master_step2') }}</h4>
                <p class="text-xs text-gray-500 mb-3">{{ __('pos.inventory_master_step2_hint') }}</p>
                <form method="POST" action="{{ route('pos.inventory-master.import') }}" enctype="multipart/form-data" class="space-y-3">
                    @csrf
                    <input type="file" name="excel_file" accept=".xlsx,.xls" required class="block w-full text-sm text-gray-500 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100 dark:file:bg-purple-900/30 dark:file:text-purple-300">
                    <button type="submit" class="inline-flex items-center gap-1.5 bg-gradient-to-r from-emerald-500 to-emerald-700 text-white px-5 py-2 rounded-lg text-xs font-semibold shadow-md hover:shadow-lg transition">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                        {{ __('pos.inventory_master_upload') }}
                    </button>
                </form>
            </div>
        </div>
        <p class="mt-4 text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.inventory_master_columns_note') }}</p>
    </div>
</div>
</x-pos-layout>
