<x-agent-layout>
<h1 class="text-2xl font-bold mb-6">Commission Ledger</h1>
<div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl overflow-x-auto">
<table class="w-full text-sm"><thead class="bg-gray-100 dark:bg-gray-800 text-left"><tr><th class="p-3">Date</th><th class="p-3">Company</th><th class="p-3">Type</th><th class="p-3 text-right">Amount</th><th class="p-3">Status</th><th class="p-3 text-right">Running Total</th></tr></thead>
<tbody>@php($balance = $running) @forelse($commissions as $line) @php($balance += (float) $line->amount)
<tr class="border-t border-gray-200 dark:border-gray-800"><td class="p-3">{{ optional($line->created_at)->format('d M Y') }}</td><td class="p-3">{{ $line->company_name ?: $line->company?->name }}</td><td class="p-3">{{ ucfirst($line->type) }}</td><td class="p-3 text-right">Rs {{ number_format((float)$line->amount, 2) }}</td><td class="p-3">{{ ucfirst($line->status) }}</td><td class="p-3 text-right font-semibold">Rs {{ number_format($balance, 2) }}</td></tr>
@empty<tr><td colspan="6" class="p-8 text-center text-gray-500">No commission entries yet.</td></tr>@endforelse</tbody></table>
</div><div class="mt-4">{{ $commissions->links() }}</div>
</x-agent-layout>