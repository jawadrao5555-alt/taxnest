<x-fbr-pos-layout>
<div class="max-w-6xl mx-auto" id="munafaPage">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.munafa_report') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('pos.munafa_sub') }}</p>
        </div>
        <a href="{{ route('fbrpos.stock') }}" class="text-sm text-blue-600 hover:underline">← Stock &amp; Purchase</a>
    </div>

    {{-- Date filter --}}
    <form method="GET" action="{{ route('fbrpos.munafa') }}" class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 mb-5 flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.munafa_from') }}</label>
            <input type="date" name="from" value="{{ $from }}" class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
        </div>
        <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-1">{{ __('pos.munafa_to') }}</label>
            <input type="date" name="to" value="{{ $to }}" class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600">
        </div>
        <button type="submit" class="bg-blue-600 text-white rounded-lg px-5 py-2 text-sm font-semibold hover:bg-blue-700">{{ __('pos.munafa_show_btn') }}</button>
        <div class="flex gap-2 ml-auto">
            <a href="{{ route('fbrpos.munafa', ['from' => now()->toDateString(), 'to' => now()->toDateString()]) }}" class="text-xs px-3 py-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 font-semibold hover:bg-gray-200">{{ __('pos.munafa_today') }}</a>
            <a href="{{ route('fbrpos.munafa', ['from' => now()->subDays(6)->toDateString(), 'to' => now()->toDateString()]) }}" class="text-xs px-3 py-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 font-semibold hover:bg-gray-200">{{ __('pos.munafa_7days') }}</a>
            <a href="{{ route('fbrpos.munafa', ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()]) }}" class="text-xs px-3 py-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 font-semibold hover:bg-gray-200">{{ __('pos.munafa_month') }}</a>
        </div>
    </form>

    {{-- Summary cards --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 border-l-4 border-blue-500">
            <p class="text-xs font-bold uppercase tracking-wider text-gray-400">{{ __('pos.munafa_revenue') }}</p>
            <p class="text-2xl font-extrabold text-gray-900 dark:text-white mt-1">Rs {{ number_format($revenue, 0) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 border-l-4 border-amber-500">
            <p class="text-xs font-bold uppercase tracking-wider text-gray-400">{{ __('pos.munafa_cost') }}</p>
            <p class="text-2xl font-extrabold text-gray-900 dark:text-white mt-1">Rs {{ number_format($cost, 0) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 border-l-4 border-emerald-500">
            <p class="text-xs font-bold uppercase tracking-wider text-gray-400">{{ __('pos.munafa_gross') }}</p>
            <p class="text-2xl font-extrabold {{ $grossProfit >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }} mt-1">Rs {{ number_format($grossProfit, 0) }}</p>
            <p class="text-xs text-gray-400 mt-0.5">{{ __('pos.munafa_bill_disc') }}: Rs {{ number_format($billDiscounts, 0) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 border-l-4 border-green-600">
            <p class="text-xs font-bold uppercase tracking-wider text-gray-400">{{ __('pos.munafa_net') }}</p>
            <p class="text-2xl font-extrabold {{ $netProfit >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }} mt-1">Rs {{ number_format($netProfit, 0) }}</p>
            <p class="text-xs text-gray-400 mt-0.5">{{ __('pos.munafa_net_hint') }}</p>
        </div>
    </div>

    {{-- Product table --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden">
        @if($rows->isEmpty())
            <div class="p-10 text-center text-gray-400">
                <p class="text-lg font-semibold">{{ __('pos.munafa_no_data') }}</p>
            </div>
        @else
        <div class="overflow-x-auto">
        <table class="w-full text-sm table-cards">
            <thead class="bg-gray-50 dark:bg-gray-700 text-left">
                <tr>
                    <th class="px-4 py-2.5 font-bold text-gray-600 dark:text-gray-300">{{ __('pos.munafa_col_product') }}</th>
                    <th class="px-4 py-2.5 font-bold text-gray-600 dark:text-gray-300 text-right">{{ __('pos.munafa_col_qty') }}</th>
                    <th class="px-4 py-2.5 font-bold text-gray-600 dark:text-gray-300 text-right">{{ __('pos.munafa_col_sale') }}</th>
                    <th class="px-4 py-2.5 font-bold text-gray-600 dark:text-gray-300 text-right">{{ __('pos.munafa_col_cost') }}</th>
                    <th class="px-4 py-2.5 font-bold text-gray-600 dark:text-gray-300 text-right">{{ __('pos.munafa_col_profit') }}</th>
                    <th class="px-4 py-2.5 font-bold text-gray-600 dark:text-gray-300 text-right">{{ __('pos.munafa_col_margin') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($rows as $r)
                <tr>
                    <td class="px-4 py-2.5">
                        <span class="font-semibold text-gray-900 dark:text-white">{{ $r->item_name }}</span>
                        @if($r->cost_unknown)
                            <span class="ml-1 inline-block text-[10px] font-bold px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300 align-middle">{{ __('pos.munafa_cost_unknown') }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-300">{{ rtrim(rtrim(number_format($r->qty, 3), '0'), '.') }}</td>
                    <td class="px-4 py-2.5 text-right text-gray-700 dark:text-gray-300">Rs {{ number_format($r->sale_value, 0) }}</td>
                    <td class="px-4 py-2.5 text-right text-gray-500 dark:text-gray-400">{{ $r->cost_unknown && $r->cost_value == 0 ? '—' : 'Rs ' . number_format($r->cost_value, 0) }}</td>
                    <td class="px-4 py-2.5 text-right font-bold {{ $r->profit >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">Rs {{ number_format($r->profit, 0) }}</td>
                    <td class="px-4 py-2.5 text-right text-gray-500 dark:text-gray-400">{{ $r->margin !== null ? number_format($r->margin, 1) . '%' : '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
        @endif
    </div>

    {{-- Cost-basis explainer --}}
    <div class="mt-4 text-xs text-gray-400 dark:text-gray-500 space-y-1">
        <p>ℹ️ {{ __('pos.munafa_cost_note') }}</p>
        <p>{{ __('pos.munafa_returns_note') }}</p>
        @if($unknownCount > 0)
            <p class="text-amber-500">⚠️ {{ $unknownCount }} × {{ __('pos.munafa_cost_unknown') }}</p>
        @endif
    </div>
</div>
</x-fbr-pos-layout>
