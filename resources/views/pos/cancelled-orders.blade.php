@extends('layouts.pos-app')

@section('title', __('pos.cancelled_orders'))

@section('content')
<div class="max-w-6xl mx-auto px-4 py-6">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div>
            <h1 class="text-xl font-black text-gray-900 dark:text-white">{{ __('pos.cancelled_orders') }}</h1>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ $from }} → {{ $to }}</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('pos.restaurant.cancelled-orders.csv', ['from' => $from, 'to' => $to]) }}" class="px-3 py-2 rounded-lg text-xs font-bold bg-emerald-600 text-white hover:bg-emerald-700 transition">CSV ⬇</a>
            <a href="{{ route('pos.restaurant.cancelled-orders.pdf', ['from' => $from, 'to' => $to]) }}" class="px-3 py-2 rounded-lg text-xs font-bold bg-red-600 text-white hover:bg-red-700 transition">PDF ⬇</a>
        </div>
    </div>

    {{-- Filters --}}
    <form method="GET" class="flex flex-wrap items-end gap-3 mb-5 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4">
        <div>
            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-400 mb-1">{{ __('pos.from_date') }}</label>
            <input type="date" name="from" value="{{ $from }}" class="px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm text-gray-900 dark:text-white">
        </div>
        <div>
            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-400 mb-1">{{ __('pos.to_date') }}</label>
            <input type="date" name="to" value="{{ $to }}" class="px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm text-gray-900 dark:text-white">
        </div>
        <button class="px-4 py-2 rounded-lg text-xs font-bold bg-gray-900 dark:bg-white text-white dark:text-gray-900 hover:opacity-90 transition">{{ __('pos.apply_filter') }}</button>
        @foreach ([['t' => __('pos.today_word'), 'f' => now()->toDateString(), 'e' => now()->toDateString()], ['t' => '7 ' . (__('pos.days_word')), 'f' => now()->subDays(6)->toDateString(), 'e' => now()->toDateString()], ['t' => '30 ' . (__('pos.days_word')), 'f' => now()->subDays(29)->toDateString(), 'e' => now()->toDateString()]] as $q)
            <a href="{{ route('pos.restaurant.cancelled-orders', ['from' => $q['f'], 'to' => $q['e']]) }}" class="px-3 py-2 rounded-lg text-xs font-semibold bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-200 transition">{{ $q['t'] }}</a>
        @endforeach
    </form>

    {{-- Summary --}}
    <div class="grid grid-cols-2 gap-3 mb-5 max-w-md">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4">
            <p class="text-[10px] font-bold uppercase tracking-wide text-gray-400">{{ __('pos.total_cancelled') }}</p>
            <p class="text-2xl font-black text-red-600 mt-1">{{ number_format($summary['count']) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 p-4">
            <p class="text-[10px] font-bold uppercase tracking-wide text-gray-400">{{ __('pos.cancelled_value') }}</p>
            <p class="text-2xl font-black text-gray-900 dark:text-white mt-1">Rs {{ number_format($summary['value']) }}</p>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-[11px] uppercase tracking-wide text-gray-400 border-b border-gray-100 dark:border-gray-800">
                    <th class="px-4 py-3">{{ __('pos.order_number') }}</th>
                    <th class="px-4 py-3 hidden md:table-cell">{{ __('pos.date_word') }}</th>
                    <th class="px-4 py-3">Table</th>
                    <th class="px-4 py-3 hidden lg:table-cell">Items</th>
                    <th class="px-4 py-3 text-right">Rs</th>
                    <th class="px-4 py-3 hidden sm:table-cell">KOT</th>
                    <th class="px-4 py-3 hidden md:table-cell">{{ __('pos.punched_by') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($orders as $o)
                <tr class="border-b border-gray-100 dark:border-gray-800 {{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/20' : '' }}">
                    <td class="px-4 py-3 font-semibold text-gray-900 dark:text-white">{{ $o->order_number }}</td>
                    <td class="px-4 py-3 text-gray-500 hidden md:table-cell">{{ optional($o->cancelled_at ?? $o->updated_at)->format('d M, h:i A') }}</td>
                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $o->table?->table_number ? 'T-' . $o->table->table_number : '—' }}</td>
                    <td class="px-4 py-3 text-xs text-gray-500 hidden lg:table-cell max-w-xs truncate">{{ $o->items->map(fn ($i) => $i->quantity . '× ' . $i->item_name)->implode(', ') }}</td>
                    <td class="px-4 py-3 text-right font-bold text-gray-900 dark:text-white">{{ number_format($o->total_amount) }}</td>
                    <td class="px-4 py-3 hidden sm:table-cell">
                        @if ($o->kot_sent_at)
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300">{{ __('pos.kot_was_sent') }}</span>
                        @else
                            <span class="text-gray-300">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-gray-500 hidden md:table-cell">{{ $o->creator?->name ?? '—' }}</td>
                </tr>
                @empty
                <tr><td colspan="7" class="px-4 py-10 text-center text-sm text-gray-400">{{ __('pos.no_cancelled_orders') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $orders->links() }}</div>
</div>
@endsection
