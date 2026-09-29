<x-admin-layout>
<div class="p-4 sm:p-6 max-w-7xl mx-auto text-white">
    <h1 class="text-2xl font-bold mb-2">{{ $franchise->name }} — commission statement</h1>
    <p class="text-sm text-gray-400 mb-5">Only verified package payments earn commission. Rates and attribution are frozen on each entry.</p>
    <div class="flex gap-6 mb-5"><p>Balance: PKR {{ number_format($totals['pending'], 2) }}</p><p>Paid: PKR {{ number_format($totals['paid'], 2) }}</p></div>
    @if(auth('admin')->user()?->isSuperAdmin())
    <form method="POST" action="{{ route('saas.admin.franchises.reconcile', $franchise->id) }}" class="mb-5">@csrf<button class="text-indigo-400 text-sm">Reconcile verified receipts</button></form>
    @if($totals['pending'] > 0)
        <form method="POST" action="{{ route('saas.admin.franchises.paid', $franchise->id) }}" class="mb-5 flex gap-2 items-end">@csrf
            <label class="text-sm">Payout reference <input name="reference" required maxlength="255" class="block text-black rounded p-2"></label>
            <button class="bg-emerald-700 px-4 py-2 rounded">Record net payout of PKR {{ number_format($totals['pending'], 2) }}</button>
    </form>
    @endif
    @endif
    @if(auth('admin')->user()?->isSuperAdmin())
    <form method="POST" action="{{ route('saas.admin.franchises.update', $franchise->id) }}" class="bg-gray-900 border border-gray-800 rounded p-4 mb-5 flex flex-wrap gap-2 items-end">
        @csrf @method('PUT')
        <input type="hidden" name="name" value="{{ $franchise->name }}"><input type="hidden" name="email" value="{{ $franchise->email }}"><input type="hidden" name="phone" value="{{ $franchise->phone }}">
        <label class="text-sm">Future commission rate % <input name="commission_rate" type="number" step="0.01" min="0" max="100" value="{{ $franchise->commission_rate }}" class="block bg-gray-800 border border-gray-700 rounded p-2"></label>
        <button class="bg-indigo-600 px-4 py-2 rounded">Update rate</button>
    </form>
    @endif
    <div class="overflow-x-auto bg-gray-900 rounded border border-gray-800"><table class="w-full text-sm"><thead><tr class="border-b border-gray-700"><th class="p-3 text-left">Date</th><th class="p-3 text-left">Company</th><th class="p-3">Proof</th><th class="p-3">Base</th><th class="p-3">Rate</th><th class="p-3">Amount</th><th class="p-3">Status</th><th class="p-3">Action</th></tr></thead><tbody>
        @forelse($lines as $line)<tr class="border-b border-gray-800"><td class="p-3">{{ $line->earned_at?->format('d M Y') }}</td><td class="p-3">{{ $line->company_name }}</td><td class="p-3">{{ $line->payment_proof_id ?? 'Adjustment #'.$line->source_commission_id }}</td><td class="p-3 text-right">{{ number_format($line->base_amount, 2) }}</td><td class="p-3 text-right">{{ $line->rate_percent }}%</td><td class="p-3 text-right">{{ number_format($line->amount, 2) }}</td><td class="p-3">{{ $line->status }} @if($line->paid_at)({{ $line->payout_reference }})@endif</td><td class="p-3">
            @if(auth('admin')->user()?->isSuperAdmin() && $line->type === 'earned' && $line->amount > 0)
                <form method="POST" action="{{ route('saas.admin.franchises.adjust', [$franchise->id, $line->id]) }}" class="flex gap-1">@csrf<input name="amount" type="number" min="0.01" step="0.01" required placeholder="Commission reversal" class="w-28 text-black rounded p-1"><input name="reason" required maxlength="255" placeholder="Refund reason" class="text-black rounded p-1"><button class="text-amber-400">Adjust</button></form>
            @endif
        </td></tr>@empty<tr><td colspan="8" class="p-8 text-center text-gray-400">No verified package payments.</td></tr>@endforelse
        </tbody></table></div><div class="mt-4">{{ $lines->links() }}</div>
</div>
</x-admin-layout>
