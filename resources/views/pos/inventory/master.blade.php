<x-pos-layout>
@include('pos.inventory.partials.family-styles')
<div class="tn-inventory-family max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    @include('pos.partials.back-link')
    <div class="tn-family-header mb-6">
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

    <div x-data="{ wizardStep: {{ session('inventory_master_preview') ? 3 : 1 }}, issueQuery: '' }" class="tn-panel p-5">
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-6" aria-label="{{ __('pos.inventory_master_steps') }}">
            @foreach([
                1 => __('pos.inventory_master_step_download'),
                2 => __('pos.inventory_master_step_upload'),
                3 => __('pos.inventory_master_step_review'),
                4 => __('pos.inventory_master_step_confirm')
            ] as $step => $label)
            <button type="button" @click="wizardStep = {{ $step }}" :aria-current="wizardStep === {{ $step }} ? 'step' : false"
                    class="text-left rounded-xl border px-3 py-2 transition"
                    :class="wizardStep === {{ $step }} ? 'border-teal-600 bg-teal-50 dark:bg-teal-900/20' : 'border-gray-200 dark:border-gray-700'">
                <span class="block text-[10px] font-bold uppercase tracking-widest text-gray-400">{{ $step }}</span>
                <span class="text-xs font-semibold text-gray-800 dark:text-gray-200">{{ $label }}</span>
            </button>
            @endforeach
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                <h4 class="text-xs font-bold text-gray-700 dark:text-gray-300 uppercase mb-2">{{ __('pos.inventory_master_step1') }}</h4>
                <p class="text-xs text-gray-500 mb-3">{{ __('pos.inventory_master_step1_hint') }}</p>
                <div class="flex flex-wrap gap-2">
                <a href="{{ route('pos.inventory-master.template') }}" class="inline-flex items-center gap-1.5 bg-gradient-to-r from-purple-500 to-purple-700 text-white px-5 py-2 rounded-lg text-xs font-semibold shadow-md hover:shadow-lg transition no-underline">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    {{ __('pos.inventory_master_download') }}
                </a>
                <a href="{{ route('pos.inventory-master.export') }}" class="inline-flex items-center gap-1.5 border border-purple-300 dark:border-purple-700 text-purple-700 dark:text-purple-300 px-5 py-2 rounded-lg text-xs font-semibold no-underline">{{ __('pos.inventory_master_export') }}</a>
                </div>
            </div>
            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                <h4 class="text-xs font-bold text-gray-700 dark:text-gray-300 uppercase mb-2">{{ __('pos.inventory_master_step2') }}</h4>
                <p class="text-xs text-gray-500 mb-3">{{ __('pos.inventory_master_step2_hint') }}</p>
                <form method="POST" action="{{ route('pos.inventory-master.preview') }}" enctype="multipart/form-data" class="space-y-3" @submit="wizardStep = 3">
                    @csrf
                    <input type="file" name="excel_file" accept=".xlsx" required class="block w-full text-sm text-gray-500 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100 dark:file:bg-purple-900/30 dark:file:text-purple-300">
                    <label class="flex items-start gap-2 text-xs text-gray-600 dark:text-gray-300">
                        <input type="checkbox" name="mode" value="update_existing" class="mt-0.5 rounded text-amber-600 focus:ring-amber-500">
                        <span><strong>{{ __('pos.inventory_master_update_existing') }}</strong><br>{{ __('pos.inventory_master_update_existing_hint') }}</span>
                    </label>
                    <button type="submit" class="inline-flex items-center gap-1.5 bg-gradient-to-r from-emerald-500 to-emerald-700 text-white px-5 py-2 rounded-lg text-xs font-semibold shadow-md hover:shadow-lg transition">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                        {{ __('pos.inventory_master_upload') }}
                    </button>
                </form>
            </div>
        </div>
        @if(session('inventory_master_preview'))
        <section class="mt-5 border-t border-gray-200 dark:border-gray-700 pt-5" x-show="wizardStep >= 3">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-3">
                <div>
                    <p class="tn-kicker">{{ __('pos.inventory_master_review_label') }}</p>
                    <h3 class="font-semibold text-gray-900 dark:text-white">{{ __('pos.inventory_master_review_title') }}</h3>
                </div>
                <input x-model="issueQuery" type="search" placeholder="{{ __('pos.inventory_master_search_issues') }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm">
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-4">
                @foreach(['rows' => 'inventory_master_rows', 'valid' => 'inventory_master_valid', 'issues' => 'inventory_master_issues', 'updates' => 'inventory_master_updates'] as $key => $label)
                <div class="tn-metric"><span class="block text-xs text-gray-500">{{ __("pos.$label") }}</span><strong class="text-lg">{{ data_get(session('inventory_master_preview'), $key, 0) }}</strong></div>
                @endforeach
            </div>
            @php($previewIssues = array_merge(data_get(session('inventory_master_preview'), 'errors', []), data_get(session('inventory_master_preview'), 'warnings', [])))
            @if($previewIssues)
            <div class="max-h-56 overflow-y-auto rounded-lg border border-amber-200 dark:border-amber-800 p-3 text-xs space-y-1">
                @foreach($previewIssues as $issue)
                @php($issueText = implode(' · ', array_filter([$issue['sheet'] ?? null, isset($issue['row']) ? 'Row '.$issue['row'] : null, $issue['field'] ?? null, $issue['message'] ?? null])))
                <p x-show="!issueQuery || @js(strtolower($issueText)).includes(issueQuery.toLowerCase())">{{ $issueText }}</p>
                @endforeach
            </div>
            @endif
            @if(data_get(session('inventory_master_preview'), 'token'))
            <form method="POST" action="{{ route('pos.inventory-master.error-report') }}" class="mt-3">
                @csrf
                <input type="hidden" name="token" value="{{ data_get(session('inventory_master_preview'), 'token') }}">
                <button type="submit" class="text-xs font-semibold text-purple-700 dark:text-purple-300 underline">{{ __('pos.inventory_master_row_issues') }}</button>
            </form>
            @endif
        </section>
        @endif
        @if(session('inventory_master_preview') && \Illuminate\Support\Facades\Route::has('pos.inventory-master.confirm'))
        <form method="POST" action="{{ route('pos.inventory-master.confirm') }}" class="mt-4 flex justify-end" x-show="wizardStep >= 3" onsubmit="return confirm(this.dataset.confirm)" data-confirm="{{ __('pos.inventory_master_confirm_exact') }}">
            @csrf
            <input type="hidden" name="token" value="{{ data_get(session('inventory_master_preview'), 'token') }}">
            <input type="hidden" name="confirmed" value="1">
            <button type="submit" class="rounded-lg bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white">{{ __('pos.inventory_master_confirm_import') }}</button>
        </form>
        @endif
        <p class="mt-4 text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.inventory_master_columns_note') }}</p>
    </div>
</div>
</x-pos-layout>
