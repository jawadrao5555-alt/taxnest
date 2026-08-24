<x-admin-layout>
<div class="p-4 sm:p-6 max-w-7xl mx-auto">
<h1 class="text-2xl font-bold text-white mb-6">Agent Sale Claims</h1>
<div class="bg-gray-900 border border-gray-800 rounded-xl overflow-x-auto"><table class="w-full text-sm"><thead class="bg-gray-800 text-left text-gray-400"><tr><th class="p-3">Agent</th><th class="p-3">Identifier</th><th class="p-3">Note</th><th class="p-3">Status</th><th class="p-3">Review</th></tr></thead><tbody>
@forelse($claims as $claim)<tr class="border-t border-gray-800 text-gray-300"><td class="p-3">{{ $claim->agent?->name }}</td><td class="p-3">{{ strtoupper($claim->identifier_type) }}: {{ $claim->identifier }}</td><td class="p-3">{{ $claim->note ?? '—' }}</td><td class="p-3">{{ ucfirst($claim->status) }}</td><td class="p-3">
@if($claim->status === 'pending')<form method="POST" action="{{ route('saas.admin.agent-claims.review', $claim) }}" class="flex gap-2">@csrf<input name="admin_note" placeholder="Review note" class="bg-gray-800 border border-gray-700 rounded px-2 py-1"><button name="decision" value="approve" class="text-emerald-400">Approve</button><button name="decision" value="reject" class="text-red-400">Reject</button></form>@else {{ $claim->admin_note ?? '—' }} @endif
</td></tr>@empty<tr><td colspan="5" class="p-8 text-center text-gray-500">No claims.</td></tr>@endforelse</tbody></table></div><div class="mt-4">{{ $claims->links() }}</div>
</div>
</x-admin-layout>