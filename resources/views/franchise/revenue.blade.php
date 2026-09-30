<x-franchise-layout>
<div class="p-6 max-w-7xl mx-auto">
    <h1 class="text-2xl font-bold mb-2">Commission statement</h1>
    <p class="text-sm text-gray-500 mb-6">Commission is recorded when TaxNest verifies a package payment. Shop sales do not affect this statement.</p>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl p-5">Earned<br><strong>PKR {{ number_format($totals['earned'], 2) }}</strong></div>
        <div class="bg-white dark:bg-gray-900 rounded-xl p-5">Balance including adjustments<br><strong>PKR {{ number_format($totals['balance'], 2) }}</strong></div>
        <div class="bg-white dark:bg-gray-900 rounded-xl p-5">Paid<br><strong>PKR {{ number_format($totals['paid'], 2) }}</strong></div>
    </div>
    <div class="overflow-x-auto bg-white dark:bg-gray-900 rounded-xl">
        <table class="w-full text-sm"><thead><tr class="border-b"><th class="p-3 text-left">Date</th><th class="p-3 text-left">Company</th><th class="p-3 text-left">Entry</th><th class="p-3 text-right">Received</th><th class="p-3 text-right">Rate</th><th class="p-3 text-right">Commission</th><th class="p-3 text-left">Status</th></tr></thead>
            <tbody>@forelse($commissions as $line)
            <tr class="border-b"><td class="p-3">{{ $line->earned_at?->format('d M Y') }}</td><td class="p-3">{{ $line->company_name }}</td><td class="p-3">{{ $line->type === 'adjustment' ? 'Refund adjustment' : 'Package payment' }}</td><td class="p-3 text-right">{{ number_format($line->base_amount, 2) }}</td><td class="p-3 text-right">{{ $line->rate_percent }}%</td><td class="p-3 text-right">{{ number_format($line->amount, 2) }}</td><td class="p-3">{{ str_replace('_', ' ', ucfirst($line->status)) }}</td></tr>
            @empty<tr><td colspan="7" class="p-8 text-center">No verified package payments yet.</td></tr>@endforelse</tbody>
        </table>
    </div>
    <div class="mt-4">{{ $commissions->links() }}</div>
</div>
</x-franchise-layout>
