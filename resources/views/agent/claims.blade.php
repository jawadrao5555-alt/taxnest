<x-agent-layout>
<h1 class="text-2xl font-bold mb-6">Offline Sale Claims</h1>
<form method="POST" action="{{ route('agent.claims.store') }}" class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-5 grid sm:grid-cols-4 gap-3 mb-6">@csrf
    <select name="identifier_type" class="bg-gray-100 dark:bg-gray-800 rounded-lg px-3 py-2"><option value="ntn">Company NTN</option><option value="email">Company Email</option></select>
    <input name="identifier" required placeholder="NTN or email" class="bg-gray-100 dark:bg-gray-800 rounded-lg px-3 py-2">
    <input name="note" maxlength="2000" placeholder="Sale note (optional)" class="bg-gray-100 dark:bg-gray-800 rounded-lg px-3 py-2">
    <button class="bg-indigo-600 text-white rounded-lg px-4 py-2 font-semibold">Submit Claim</button>
</form>
<div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl overflow-x-auto"><table class="w-full text-sm"><thead class="bg-gray-100 dark:bg-gray-800 text-left"><tr><th class="p-3">Submitted</th><th class="p-3">Identifier</th><th class="p-3">Company</th><th class="p-3">Status</th><th class="p-3">Admin Note</th></tr></thead><tbody>
@forelse($claims as $claim)<tr class="border-t border-gray-200 dark:border-gray-800"><td class="p-3">{{ $claim->created_at->format('d M Y') }}</td><td class="p-3">{{ $claim->identifier }}</td><td class="p-3">{{ $claim->company?->name ?? '—' }}</td><td class="p-3">{{ ucfirst($claim->status) }}</td><td class="p-3">{{ $claim->admin_note ?? '—' }}</td></tr>
@empty<tr><td colspan="5" class="p-8 text-center text-gray-500">No claims submitted.</td></tr>@endforelse</tbody></table></div><div class="mt-4">{{ $claims->links() }}</div>
</x-agent-layout>