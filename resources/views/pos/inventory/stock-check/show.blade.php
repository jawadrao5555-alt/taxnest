<x-pos-layout>
@php
    $isOpen = $check->isOpen();
    $net = $check->netValue();
    $pending = max(0, (int) $check->total_lines - (int) $check->counted_lines);
@endphp
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    @include('pos.partials.back-link')

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-4">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-purple-500 to-purple-700 flex items-center justify-center shadow-lg flex-shrink-0">
                    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"/></svg>
                </div>
                {{ $check->code }}
            </h1>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1.5 ms-11">
                {{ __('pos.stock_check_scope_' . $check->scope) }}
                @if($branchLabel) · {{ $branchLabel }} @endif
                @if($check->started_at) · {{ $check->started_at->format('d M Y, h:i A') }} @endif
            </p>
        </div>
        <div class="flex-shrink-0">
            @php
                $badge = match($check->status) {
                    'completed' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400',
                    'cancelled' => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
                    default => 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400',
                };
            @endphp
            <span class="px-3 py-1.5 rounded-full text-xs font-bold {{ $badge }}">{{ __('pos.stock_check_status_' . $check->status) }}</span>
        </div>
    </div>

    @include('pos.inventory.stock-check.partials.flash')

    {{-- Summary strip: the four numbers the owner actually looks at. --}}
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-sm p-4">
            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">{{ __('pos.stock_check_items') }}</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ $check->total_lines }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-sm p-4">
            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">{{ __('pos.stock_check_counted') }}</p>
            <p class="text-2xl font-bold text-teal-600 dark:text-teal-400 mt-1">{{ $check->counted_lines }}</p>
            @if($pending > 0)<p class="text-[11px] text-amber-600 dark:text-amber-400 font-semibold">{{ __('pos.stock_check_pending_n', ['count' => $pending]) }}</p>@endif
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-sm p-4">
            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">{{ __('pos.stock_check_gaps') }}</p>
            <p class="text-2xl font-bold {{ $check->variance_lines > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-white' }} mt-1">{{ $check->variance_lines }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-sm p-4">
            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">{{ __('pos.stock_check_short_value') }}</p>
            <p class="text-2xl font-bold text-red-600 dark:text-red-400 mt-1">{{ number_format($check->short_value, 0) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-sm p-4">
            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">{{ __('pos.stock_check_excess_value') }}</p>
            <p class="text-2xl font-bold text-emerald-600 dark:text-emerald-400 mt-1">{{ number_format($check->excess_value, 0) }}</p>
        </div>
    </div>

    {{-- Excel round-trip + closing actions --}}
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-lg p-5 mb-6">
        <div class="flex flex-col lg:flex-row lg:items-center gap-3">
            <a href="{{ route('pos.inventory.stock-check.sheet', $check->id) }}"
               class="inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold rounded-xl transition shadow-sm">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                {{ __('pos.stock_check_download_sheet') }}
            </a>

            @if($isOpen)
            <form method="POST" action="{{ route('pos.inventory.stock-check.import', $check->id) }}" enctype="multipart/form-data"
                  class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 flex-1">
                @csrf
                <input type="file" name="sheet" required accept=".xlsx,.xls,.csv"
                       class="text-xs text-gray-600 dark:text-gray-400 file:me-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-purple-50 file:text-purple-700 dark:file:bg-purple-900/30 dark:file:text-purple-300 cursor-pointer">
                <button type="submit" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-purple-600 hover:bg-purple-700 text-white text-xs font-bold rounded-xl transition shadow-sm whitespace-nowrap">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                    {{ __('pos.stock_check_upload_sheet') }}
                </button>
            </form>
            @endif

            @if(!$isOpen)
            <a href="{{ route('pos.inventory.stock-check.pdf', $check->id) }}" target="_blank"
               class="inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-gray-700 hover:bg-gray-800 text-white text-xs font-bold rounded-xl transition shadow-sm">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                {{ __('pos.stock_check_variance_report') }}
            </a>
            @endif
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400 mt-3 leading-relaxed">{{ __('pos.stock_check_excel_hint') }}</p>
    </div>

    {{-- Filters --}}
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-lg p-5 mb-6">
        <form method="GET" action="{{ route('pos.inventory.stock-check.show', $check->id) }}" class="flex flex-col lg:flex-row gap-3">
            <div class="flex-1 relative">
                <input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __('pos.stock_check_search_placeholder') }}"
                       class="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-4 py-2.5 focus:ring-2 focus:ring-purple-500">
            </div>
            <select name="filter" onchange="this.form.submit()" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-4 py-2.5 focus:ring-2 focus:ring-purple-500">
                <option value="">{{ __('pos.stock_check_filter_all') }}</option>
                <option value="pending" {{ $filter === 'pending' ? 'selected' : '' }}>{{ __('pos.stock_check_filter_pending') }}</option>
                <option value="variance" {{ $filter === 'variance' ? 'selected' : '' }}>{{ __('pos.stock_check_filter_variance') }}</option>
                <option value="short" {{ $filter === 'short' ? 'selected' : '' }}>{{ __('pos.stock_check_filter_short') }}</option>
                <option value="excess" {{ $filter === 'excess' ? 'selected' : '' }}>{{ __('pos.stock_check_filter_excess') }}</option>
            </select>
            <button type="submit" class="px-5 py-2.5 bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-xs font-bold rounded-xl transition">{{ __('pos.filter_btn') }}</button>
        </form>
    </div>

    {{-- The count sheet --}}
    <form method="POST" action="{{ route('pos.inventory.stock-check.counts', $check->id) }}">
        @csrf
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-lg overflow-hidden">
            @if($lines->isEmpty())
            <div class="p-12 text-center">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('pos.stock_check_no_rows') }}</p>
            </div>
            @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800/60 text-[11px] uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-3 text-start font-bold">{{ __('pos.item_word') }}</th>
                            <th class="px-4 py-3 text-end font-bold w-32">{{ __('pos.stock_check_expected') }}</th>
                            <th class="px-4 py-3 text-center font-bold w-36">{{ __('pos.stock_check_physical') }}</th>
                            <th class="px-4 py-3 text-end font-bold w-32">{{ __('pos.stock_check_difference') }}</th>
                            @if($isOpen)<th class="px-4 py-3 text-start font-bold w-40">{{ __('pos.stock_check_reason') }}</th>@endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($lines as $line)
                        @php
                            $expected = (float) $line->expected_quantity;
                            $countedVal = $line->counted_quantity === null ? '' : rtrim(rtrim(number_format((float) $line->counted_quantity, 4, '.', ''), '0'), '.');
                            $expectedLabel = rtrim(rtrim(number_format($expected, 4, '.', ''), '0'), '.');
                        @endphp
                        <tr class="hover:bg-gray-50/80 dark:hover:bg-gray-800/30 transition"
                            x-data="{ expected: {{ $expected }}, counted: '{{ $countedVal }}',
                                      get diff() { return this.counted === '' ? null : Math.round((parseFloat(this.counted) - this.expected) * 10000) / 10000; } }">
                            <td class="px-4 py-3">
                                <div class="font-semibold text-gray-900 dark:text-white">{{ $line->item_name }}</div>
                                <div class="text-[11px] text-gray-500 dark:text-gray-400 flex items-center gap-1.5 mt-0.5">
                                    @if($line->item_type === 'ingredient')
                                    <span class="px-1.5 py-0.5 rounded bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 font-bold">{{ __('pos.stock_check_raw_material') }}</span>
                                    @endif
                                    @if($line->item_code)<span>{{ $line->item_code }}</span>@endif
                                    @if($line->unit)<span>· {{ $line->unit }}</span>@endif
                                </div>
                            </td>
                            <td class="px-4 py-3 text-end font-mono text-gray-700 dark:text-gray-300">{{ $expectedLabel }}</td>
                            <td class="px-4 py-3 text-center">
                                @if($isOpen)
                                <input type="number" step="0.0001" min="0" inputmode="decimal"
                                       name="lines[{{ $line->id }}][counted]"
                                       x-model="counted"
                                       class="w-28 text-center rounded-xl border-2 {{ $line->counted_quantity === null ? 'border-amber-200 dark:border-amber-800' : 'border-teal-300 dark:border-teal-700' }} bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 font-bold px-2 py-2 focus:ring-2 focus:ring-purple-500">
                                @else
                                <span class="font-mono font-bold text-gray-900 dark:text-white">{{ $countedVal === '' ? '—' : $countedVal }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-end">
                                <template x-if="diff === null">
                                    <span class="text-gray-400">—</span>
                                </template>
                                <template x-if="diff !== null">
                                    <span class="font-mono font-bold"
                                          :class="diff < 0 ? 'text-red-600 dark:text-red-400' : (diff > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-400')"
                                          x-text="diff === 0 ? '0' : (diff > 0 ? '+' : '') + diff"></span>
                                </template>
                            </td>
                            @if($isOpen)
                            <td class="px-4 py-3">
                                <select name="lines[{{ $line->id }}][reason]"
                                        class="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-xs px-2 py-2 focus:ring-2 focus:ring-purple-500">
                                    <option value="">—</option>
                                    @foreach(\App\Models\StockCheckLine::REASONS as $reason)
                                    <option value="{{ $reason }}" {{ $line->reason === $reason ? 'selected' : '' }}>{{ __('pos.stock_check_reason_' . $reason) }}</option>
                                    @endforeach
                                </select>
                            </td>
                            @endif
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>

        @if($isOpen && $lines->isNotEmpty())
        <div class="sticky bottom-0 mt-4 bg-white/95 dark:bg-gray-900/95 backdrop-blur rounded-2xl border border-gray-100 dark:border-gray-700 shadow-lg p-4 flex flex-col sm:flex-row gap-3">
            <button type="submit" class="flex-1 inline-flex items-center justify-center gap-2 px-6 py-3 bg-purple-600 hover:bg-purple-700 text-white text-sm font-bold rounded-xl transition shadow-sm">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>
                {{ __('pos.stock_check_save_counts') }}
            </button>
        </div>
        @endif
    </form>

    <div class="mt-5">{{ $lines->links() }}</div>

    @if($isOpen)
    {{-- Closing the check is a stock-changing act, so it lives in its own form
         with a confirmation — never a stray click next to Save. --}}
    <div class="mt-6 bg-white dark:bg-gray-900 rounded-2xl border-2 border-purple-100 dark:border-purple-900/40 shadow-lg p-5">
        <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-1">{{ __('pos.stock_check_finish_title') }}</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4 leading-relaxed">{{ __('pos.stock_check_finish_hint') }}</p>
        <div class="flex flex-col sm:flex-row gap-3">
            <form method="POST" action="{{ route('pos.inventory.stock-check.post', $check->id) }}" class="flex-1"
                  onsubmit="return confirm('{{ __('pos.stock_check_post_confirm') }}');">
                @csrf
                <button type="submit" class="w-full inline-flex items-center justify-center gap-2 px-6 py-3 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold rounded-xl transition shadow-sm">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    {{ __('pos.stock_check_post') }}
                </button>
            </form>
            <form method="POST" action="{{ route('pos.inventory.stock-check.cancel', $check->id) }}"
                  onsubmit="return confirm('{{ __('pos.stock_check_cancel_confirm') }}');">
                @csrf
                <button type="submit" class="w-full sm:w-auto inline-flex items-center justify-center px-6 py-3 bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-sm font-bold rounded-xl transition">
                    {{ __('pos.stock_check_cancel_check') }}
                </button>
            </form>
        </div>
    </div>
    @endif

    @if($check->status === 'completed')
    <div class="mt-6 rounded-2xl border border-emerald-100 dark:border-emerald-900/40 bg-emerald-50/60 dark:bg-emerald-900/10 px-5 py-4">
        <p class="text-sm text-emerald-900 dark:text-emerald-300">
            {{ __('pos.stock_check_completed_note', ['when' => optional($check->posted_at)->format('d M Y, h:i A') ?? '—']) }}
        </p>
    </div>
    @endif
</div>
</x-pos-layout>
