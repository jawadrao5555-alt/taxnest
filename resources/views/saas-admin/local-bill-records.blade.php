<x-admin-layout>
<div class="max-w-6xl mx-auto p-6 space-y-5">
    <a class="text-teal-500 underline" href="{{ route('saas.admin.companies.show', $company->id) }}">Back to company</a>
    <h1 class="text-xl font-bold">{{ $company->name }} — SaaS local bill records</h1>
    <p class="text-sm text-gray-500">Bills retained before company cleanup. Current bills remain in the company record. Only a SaaS super admin can change retention or delete these copies. Company cleanup does not remove them. Older deleted bills cannot be recovered here.</p>
    @if(session('success'))<p role="status" class="text-teal-600">{{ session('success') }}</p>@endif
    <form method="POST" action="{{ route('saas.admin.local-bill-records.update', $company->id) }}" class="flex flex-wrap items-center gap-3">
        @csrf @method('PUT')
        <label>Future cleanup copies
            <select name="retain" class="rounded text-gray-900"><option value="1" @selected($retain)>Keep in SaaS</option><option value="0" @selected(!$retain)>Do not keep new copies</option></select>
        </label>
        <button class="rounded bg-teal-700 text-white px-4 py-2">Save</button>
    </form>
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead><tr class="text-left"><th class="p-2">Business date</th><th class="p-2">Bill</th><th class="p-2">Source ID</th><th class="p-2">Amount</th><th class="p-2">Actions</th></tr></thead>
        <tbody>@forelse($records as $record)<tr class="border-t">
            <td class="p-2">{{ $record->business_date }}</td><td class="p-2">{{ $record->reference }}</td><td class="p-2">{{ $record->source_id }}</td><td class="p-2">{{ number_format($record->total_amount, 2) }}</td>
            <td class="p-2"><a class="underline text-teal-600" href="{{ route('saas.admin.local-bill-records.download', [$company->id, $record->id]) }}">Download details</a>
                <form class="inline ml-3" method="POST" action="{{ route('saas.admin.local-bill-records.destroy', [$company->id, $record->id]) }}" onsubmit="return confirm('Delete this SaaS copy permanently? This does not remove financial summaries or independent backups.')">@csrf @method('DELETE')<button class="text-red-600 underline">Delete</button></form>
            </td>
        </tr>@empty<tr><td colspan="5" class="p-4">No cleanup copies yet.</td></tr>@endforelse</tbody>
    </table></div>
    {{ $records->links() }}
</div>
</x-admin-layout>
