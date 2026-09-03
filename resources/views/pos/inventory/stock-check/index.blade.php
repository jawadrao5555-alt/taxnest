<x-pos-layout>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    @include('pos.partials.back-link')

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-purple-500 to-purple-700 flex items-center justify-center shadow-lg">
                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg>
            </div>
            {{ __('pos.stock_check') }}
            <x-new-badge feature="stock_check" />
        </h1>
        @if(!($openCheck ?? null))
        <a href="{{ route('pos.inventory.stock-check.create') }}" class="mt-2 sm:mt-0 inline-flex items-center px-5 py-2.5 bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold rounded-xl transition shadow-sm">
            <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            {{ __('pos.stock_check_new') }}
        </a>
        @endif
    </div>

    @include('pos.inventory.stock-check.partials.tabs')
    @include('pos.inventory.partials.branch-bar')
    @include('pos.inventory.stock-check.partials.flash')

    <div class="mb-6 rounded-2xl border border-teal-200 dark:border-teal-800 bg-teal-50/60 dark:bg-teal-900/10 p-5">
        <p class="text-xs font-bold uppercase tracking-widest text-teal-700 dark:text-teal-300">{{ __('pos.stock_check_eyebrow') }}</p>
        <h2 class="mt-1 text-base font-bold text-gray-900 dark:text-white">{{ __('pos.stock_check_how_title') }}</h2>
        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300 leading-relaxed">{{ __('pos.stock_check_how_body') }}</p>
        @php
            $stockCheckSteps = [
                __('pos.stock_check_step_open'),
                __('pos.stock_check_step_count'),
                __('pos.stock_check_step_post'),
            ];
        @endphp
        <ol class="mt-4 grid gap-3 sm:grid-cols-3">
            @foreach($stockCheckSteps as $step)
            <li class="flex gap-3 rounded-xl bg-white/80 dark:bg-gray-900/50 p-3 text-xs leading-relaxed text-gray-700 dark:text-gray-300">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-teal-600 text-xs font-black text-white">{{ $loop->iteration }}</span>
                {{ $step }}
            </li>
            @endforeach
        </ol>
        <p class="mt-4 text-xs font-medium text-teal-800 dark:text-teal-200">{{ __('pos.stock_check_safety_note') }}</p>
    </div>

    @if($openCheck ?? null)
    <div class="mb-6 rounded-2xl border-2 border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 p-5">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs font-bold uppercase tracking-widest text-amber-700 dark:text-amber-400">{{ __('pos.stock_check_open_now') }}</p>
                <p class="text-lg font-bold text-gray-900 dark:text-white mt-1">{{ $openCheck->code }}</p>
                <p class="text-xs text-gray-600 dark:text-gray-400 mt-0.5">
                    {{ $openCheck->counted_lines }} / {{ $openCheck->total_lines }} {{ __('pos.stock_check_counted_of') }}
                    @if($openCheck->started_at) · {{ $openCheck->started_at->format('d M Y, h:i A') }} @endif
                </p>
            </div>
            <a href="{{ route('pos.inventory.stock-check.show', $openCheck->id) }}" class="flex-shrink-0 inline-flex items-center px-5 py-2.5 bg-amber-600 hover:bg-amber-700 text-white text-sm font-semibold rounded-xl transition shadow-sm">
                {{ __('pos.stock_check_continue') }}
            </a>
        </div>
    </div>
    @endif

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-lg p-5 mb-6">
        <form method="GET" action="{{ route('pos.inventory.stock-check.index') }}" class="flex flex-wrap gap-2">
            @php $st = request('status'); @endphp
            <a href="{{ route('pos.inventory.stock-check.index') }}" class="px-4 py-2 text-xs font-semibold rounded-xl transition {{ !$st ? 'bg-purple-600 text-white' : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300' }}">{{ __('pos.all_word') }}</a>
            <a href="{{ route('pos.inventory.stock-check.index', ['status' => 'counting']) }}" class="px-4 py-2 text-xs font-semibold rounded-xl transition {{ $st === 'counting' ? 'bg-purple-600 text-white' : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300' }}">{{ __('pos.stock_check_status_counting') }}</a>
            <a href="{{ route('pos.inventory.stock-check.index', ['status' => 'completed']) }}" class="px-4 py-2 text-xs font-semibold rounded-xl transition {{ $st === 'completed' ? 'bg-purple-600 text-white' : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300' }}">{{ __('pos.stock_check_status_completed') }}</a>
            <a href="{{ route('pos.inventory.stock-check.index', ['status' => 'cancelled']) }}" class="px-4 py-2 text-xs font-semibold rounded-xl transition {{ $st === 'cancelled' ? 'bg-purple-600 text-white' : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300' }}">{{ __('pos.stock_check_status_cancelled') }}</a>
        </form>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-lg overflow-hidden">
        @if($checks->isEmpty())
        <div class="p-12 text-center">
            <svg class="w-12 h-12 mx-auto text-gray-300 dark:text-gray-600 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('pos.stock_check_none_yet') }}</p>
        </div>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800/60 text-[11px] uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="px-5 py-3 text-start font-bold">{{ __('pos.stock_check_code') }}</th>
                        <th class="px-5 py-3 text-start font-bold">{{ __('pos.date_word') }}</th>
                        <th class="px-5 py-3 text-start font-bold">{{ __('pos.stock_check_scope') }}</th>
                        <th class="px-5 py-3 text-center font-bold">{{ __('pos.stock_check_items') }}</th>
                        <th class="px-5 py-3 text-center font-bold">{{ __('pos.stock_check_gaps') }}</th>
                        <th class="px-5 py-3 text-end font-bold">{{ __('pos.stock_check_net_value') }}</th>
                        <th class="px-5 py-3 text-center font-bold">{{ __('pos.status_word') }}</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach($checks as $c)
                    @php
                        $net = $c->netValue();
                        $badge = match($c->status) {
                            'completed' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400',
                            'cancelled' => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
                            default => 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400',
                        };
                    @endphp
                    <tr class="hover:bg-gray-50/80 dark:hover:bg-gray-800/30 transition">
                        <td class="px-5 py-4 font-bold text-gray-900 dark:text-white">{{ $c->code }}</td>
                        <td class="px-5 py-4 text-gray-600 dark:text-gray-400 text-xs">
                            {{ optional($c->started_at)->format('d M Y') }}
                            <span class="block text-gray-400">{{ optional($c->started_at)->format('h:i A') }}</span>
                            @if($c->branch)<span class="block text-purple-600 dark:text-purple-400 font-semibold">{{ $c->branch->name }}</span>@endif
                        </td>
                        <td class="px-5 py-4 text-xs text-gray-600 dark:text-gray-400">
                            {{ __('pos.stock_check_scope_' . $c->scope) }}
                        </td>
                        <td class="px-5 py-4 text-center text-gray-700 dark:text-gray-300">{{ $c->counted_lines }} / {{ $c->total_lines }}</td>
                        <td class="px-5 py-4 text-center">
                            @if($c->variance_lines > 0)
                            <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400">{{ $c->variance_lines }}</span>
                            @else
                            <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-5 py-4 text-end font-bold {{ $net < 0 ? 'text-red-600 dark:text-red-400' : ($net > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-500') }}">
                            {{ $net == 0 ? '—' : ($net < 0 ? '-' : '+') . number_format(abs($net), 0) }}
                        </td>
                        <td class="px-5 py-4 text-center">
                            <span class="px-2.5 py-1 rounded-full text-[11px] font-bold {{ $badge }}">{{ __('pos.stock_check_status_' . $c->status) }}</span>
                        </td>
                        <td class="px-5 py-4 text-end">
                            <a href="{{ route('pos.inventory.stock-check.show', $c->id) }}" class="text-purple-600 dark:text-purple-400 text-xs font-bold hover:underline">{{ __('pos.view_word') }}</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    <div class="mt-5">{{ $checks->links() }}</div>
</div>
</x-pos-layout>
