<x-fbr-pos-layout>
<div class="max-w-3xl mx-auto">
    <div class="flex items-center justify-between mb-5">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Shift Report — #{{ $shift->id }}</h1>
            <p class="text-sm text-gray-500">{{ $shift->status === 'closed' ? 'Z Report (Closed)' : 'X Report (Live)' }}</p>
        </div>
        <a href="{{ route('fbrpos.phase2.shifts') }}" class="text-sm text-blue-600 hover:underline">← Back</a>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 space-y-5">
        <div class="grid sm:grid-cols-2 gap-3 text-sm">
            <div><div class="text-xs text-gray-500">Opened</div><div class="font-semibold dark:text-white">{{ $shift->opened_at?->format('d M Y, h:i A') }}</div></div>
            <div><div class="text-xs text-gray-500">Closed</div><div class="font-semibold dark:text-white">{{ $shift->closed_at?->format('d M Y, h:i A') ?? 'OPEN' }}</div></div>
        </div>

        <div class="border-t pt-4">
            <h3 class="font-bold mb-3 dark:text-white">Sales Summary</h3>
            <table class="w-full text-sm">
                <tr><td class="py-1 dark:text-gray-300">Sales Count</td><td class="text-right font-semibold dark:text-white">{{ $shift->sales_count }}</td></tr>
                <tr><td class="py-1 dark:text-gray-300">Returns Count</td><td class="text-right font-semibold dark:text-white">{{ $shift->returns_count }}</td></tr>
                <tr><td class="py-1 dark:text-gray-300">Total Sales</td><td class="text-right font-semibold dark:text-white">Rs {{ number_format($shift->total_sales, 2) }}</td></tr>
                <tr><td class="py-1 dark:text-gray-300">Total Returns</td><td class="text-right font-semibold text-red-600">Rs {{ number_format($shift->total_returns, 2) }}</td></tr>
                <tr class="border-t"><td class="py-2 font-bold dark:text-white">Net Sales</td><td class="text-right font-bold dark:text-white">Rs {{ number_format($shift->total_sales - $shift->total_returns, 2) }}</td></tr>
            </table>
        </div>

        <div class="border-t pt-4">
            <h3 class="font-bold mb-3 dark:text-white">Payment Breakdown</h3>
            <table class="w-full text-sm">
                <tr><td class="py-1 dark:text-gray-300">Cash</td><td class="text-right font-semibold dark:text-white">Rs {{ number_format($shift->total_cash, 2) }}</td></tr>
                <tr><td class="py-1 dark:text-gray-300">Card</td><td class="text-right font-semibold dark:text-white">Rs {{ number_format($shift->total_card, 2) }}</td></tr>
                <tr><td class="py-1 dark:text-gray-300">Other</td><td class="text-right font-semibold dark:text-white">Rs {{ number_format($shift->total_other, 2) }}</td></tr>
            </table>
        </div>

        @if($shift->status === 'closed')
        <div class="border-t pt-4">
            <h3 class="font-bold mb-3 dark:text-white">Cash Reconciliation</h3>
            <table class="w-full text-sm">
                <tr><td class="py-1 dark:text-gray-300">Opening Cash</td><td class="text-right dark:text-white">Rs {{ number_format($shift->opening_cash, 2) }}</td></tr>
                <tr><td class="py-1 dark:text-gray-300">+ Cash Sales</td><td class="text-right dark:text-white">Rs {{ number_format($shift->total_cash, 2) }}</td></tr>
                <tr><td class="py-1 dark:text-gray-300">Expected in Drawer</td><td class="text-right font-semibold dark:text-white">Rs {{ number_format($shift->expected_cash, 2) }}</td></tr>
                <tr><td class="py-1 dark:text-gray-300">Counted (Closing)</td><td class="text-right font-semibold dark:text-white">Rs {{ number_format($shift->closing_cash, 2) }}</td></tr>
                <tr class="border-t"><td class="py-2 font-bold dark:text-white">Variance</td><td class="text-right font-bold {{ $shift->variance == 0 ? 'text-emerald-600' : 'text-red-600' }}">Rs {{ number_format($shift->variance, 2) }}</td></tr>
            </table>
        </div>
        @endif

        @if($movements->count() > 0)
        <div class="border-t pt-4">
            <h3 class="font-bold mb-3 dark:text-white">Cash Movements</h3>
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500"><tr><th class="text-left py-1">Time</th><th class="text-left">Type</th><th class="text-left">Reason</th><th class="text-right">Amount</th></tr></thead>
                <tbody>
                @foreach($movements as $m)
                    <tr class="border-t dark:border-gray-700"><td class="py-1.5 dark:text-gray-300">{{ $m->created_at->format('h:i A') }}</td><td class="dark:text-gray-300">{{ $m->type }}</td><td class="dark:text-gray-300">{{ $m->reason }}</td><td class="text-right font-semibold {{ in_array($m->type, ['cash_in','float']) ? 'text-emerald-600' : 'text-red-600' }}">{{ in_array($m->type, ['cash_in','float']) ? '+' : '-' }}Rs {{ number_format($m->amount, 2) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif

        @if($shift->notes)<div class="border-t pt-4 text-sm dark:text-gray-300"><strong>Notes:</strong> {{ $shift->notes }}</div>@endif

        <div class="border-t pt-4 flex gap-2">
            <button onclick="window.print()" class="bg-gray-700 text-white rounded px-4 py-2 text-sm hover:bg-gray-800">Print</button>
        </div>
    </div>
</div>
</x-fbr-pos-layout>
