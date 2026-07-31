<x-fbr-pos-layout>
<div class="max-w-3xl mx-auto">
    <div class="flex items-center justify-between mb-5">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.shift_report_hash', ['id' => $shift->id]) }}</h1>
            <p class="text-sm text-gray-500">{{ $shift->status === 'closed' ? __('pos.z_report_closed') : __('pos.x_report_live') }}</p>
        </div>
        <a href="{{ route('fbrpos.phase2.shifts') }}" class="text-sm text-blue-600 hover:underline">← {{ __('pos.back_word') }}</a>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 space-y-5">
        <div class="grid sm:grid-cols-2 gap-3 text-sm">
            <div><div class="text-xs text-gray-500">{{ __('pos.th_opened') }}</div><div class="font-semibold dark:text-white">{{ $shift->opened_at?->format('d M Y, h:i A') }}</div></div>
            <div><div class="text-xs text-gray-500">{{ __('pos.th_closed') }}</div><div class="font-semibold dark:text-white">{{ $shift->closed_at?->format('d M Y, h:i A') ?? __('pos.open_caps') }}</div></div>
        </div>

        <div class="border-t pt-4">
            <h3 class="font-bold mb-3 dark:text-white">{{ __('pos.sales_summary') }}</h3>
            <table class="w-full text-sm table-cards">
                <tr><td class="py-1 dark:text-gray-300">{{ __('pos.sales_count') }}</td><td class="text-right font-semibold dark:text-white">{{ $shift->sales_count }}</td></tr>
                <tr><td class="py-1 dark:text-gray-300">{{ __('pos.returns_count') }}</td><td class="text-right font-semibold dark:text-white">{{ $shift->returns_count }}</td></tr>
                <tr><td class="py-1 dark:text-gray-300">{{ __('pos.total_sales') }}</td><td class="text-right font-semibold dark:text-white">Rs {{ number_format($shift->total_sales, 2) }}</td></tr>
                <tr><td class="py-1 dark:text-gray-300">{{ __('pos.total_returns') }}</td><td class="text-right font-semibold text-red-600">Rs {{ number_format($shift->total_returns, 2) }}</td></tr>
                <tr class="border-t"><td class="py-2 font-bold dark:text-white">{{ __('pos.net_sales') }}</td><td class="text-right font-bold dark:text-white">Rs {{ number_format($shift->total_sales - $shift->total_returns, 2) }}</td></tr>
            </table>
        </div>

        <div class="border-t pt-4">
            <h3 class="font-bold mb-3 dark:text-white">{{ __('pos.payment_breakdown') }}</h3>
            <table class="w-full text-sm table-cards">
                <tr><td class="py-1 dark:text-gray-300">{{ __('pos.th_cash') }}</td><td class="text-right font-semibold dark:text-white">Rs {{ number_format($shift->total_cash, 2) }}</td></tr>
                <tr><td class="py-1 dark:text-gray-300">{{ __('pos.card_word') }}</td><td class="text-right font-semibold dark:text-white">Rs {{ number_format($shift->total_card, 2) }}</td></tr>
                <tr><td class="py-1 dark:text-gray-300">{{ __('pos.other_word') }}</td><td class="text-right font-semibold dark:text-white">Rs {{ number_format($shift->total_other, 2) }}</td></tr>
            </table>
        </div>

        @if($shift->status === 'closed')
        <div class="border-t pt-4">
            <h3 class="font-bold mb-3 dark:text-white">{{ __('pos.cash_reconciliation') }}</h3>
            <table class="w-full text-sm table-cards">
                <tr><td class="py-1 dark:text-gray-300">{{ __('pos.opening_cash') }}</td><td class="text-right dark:text-white">Rs {{ number_format($shift->opening_cash, 2) }}</td></tr>
                <tr><td class="py-1 dark:text-gray-300">{{ __('pos.plus_cash_sales') }}</td><td class="text-right dark:text-white">Rs {{ number_format($shift->total_cash, 2) }}</td></tr>
                <tr><td class="py-1 dark:text-gray-300">{{ __('pos.expected_in_drawer') }}</td><td class="text-right font-semibold dark:text-white">Rs {{ number_format($shift->expected_cash, 2) }}</td></tr>
                <tr><td class="py-1 dark:text-gray-300">{{ __('pos.counted_closing') }}</td><td class="text-right font-semibold dark:text-white">Rs {{ number_format($shift->closing_cash, 2) }}</td></tr>
                <tr class="border-t"><td class="py-2 font-bold dark:text-white">{{ __('pos.th_variance') }}</td><td class="text-right font-bold {{ $shift->variance == 0 ? 'text-emerald-600' : 'text-red-600' }}">Rs {{ number_format($shift->variance, 2) }}</td></tr>
            </table>
        </div>
        @endif

        @if($movements->count() > 0)
        <div class="border-t pt-4">
            <h3 class="font-bold mb-3 dark:text-white">{{ __('pos.cash_movements') }}</h3>
            <table class="w-full text-sm table-cards">
                <thead class="text-xs text-gray-500"><tr><th class="text-left py-1">{{ __('pos.th_time') }}</th><th class="text-left">{{ __('pos.th_type') }}</th><th class="text-left">{{ __('pos.th_reason') }}</th><th class="text-right">{{ __('pos.th_amount') }}</th></tr></thead>
                <tbody>
                @foreach($movements as $m)
                    <tr class="border-t dark:border-gray-700"><td class="py-1.5 dark:text-gray-300">{{ $m->created_at->format('h:i A') }}</td><td class="dark:text-gray-300">{{ $m->type }}</td><td class="dark:text-gray-300">{{ $m->reason }}</td><td class="text-right font-semibold {{ in_array($m->type, ['cash_in','float']) ? 'text-emerald-600' : 'text-red-600' }}">{{ in_array($m->type, ['cash_in','float']) ? '+' : '-' }}Rs {{ number_format($m->amount, 2) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif

        @if($shift->notes)<div class="border-t pt-4 text-sm dark:text-gray-300"><strong>{{ __('pos.notes_colon') }}</strong> {{ $shift->notes }}</div>@endif

        <div class="border-t pt-4 flex gap-2">
            <button onclick="window.print()" class="bg-gray-700 text-white rounded px-4 py-2 text-sm hover:bg-gray-800">{{ __('pos.print') }}</button>
        </div>
    </div>
</div>
</x-fbr-pos-layout>
