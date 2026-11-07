<x-admin-layout>
<div class="p-4 sm:p-6 max-w-6xl mx-auto">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
        <div>
            <h1 class="text-2xl font-bold text-white">Company Support Cases</h1>
            <p class="mt-1 text-xs text-gray-500">Durable ownership and resolution history; mailbox availability does not change these records.</p>
        </div>
        <a href="{{ route('saas.admin.support-inbox') }}" class="px-4 py-2 rounded-lg bg-gray-800 text-sm text-gray-300 hover:bg-gray-700">Open mailbox</a>
    </div>

    <nav class="flex flex-wrap gap-2 mb-4" aria-label="Support case status">
        @foreach([['value' => null, 'label' => 'All'], ['value' => 'open', 'label' => 'Open'], ['value' => 'reopened', 'label' => 'Reopened'], ['value' => 'resolved', 'label' => 'Resolved']] as $filter)
            @php($value = $filter['value'])
            <a href="{{ route('saas.admin.support-cases', $value ? ['status' => $value] : []) }}"
               class="px-3 py-1.5 rounded-lg text-sm {{ $status === $value ? 'bg-emerald-600 text-white font-semibold' : 'bg-gray-800 text-gray-400 hover:text-gray-200' }}">{{ $filter['label'] }}</a>
        @endforeach
    </nav>

    <div class="overflow-x-auto rounded-xl border border-gray-800 bg-gray-900">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-800/70 text-xs uppercase tracking-wide text-gray-400">
                <tr><th class="px-4 py-3 text-left">Case</th><th class="px-4 py-3 text-left">Company</th><th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-left">Owner</th><th class="px-4 py-3 text-left">Updated</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                @forelse($cases as $case)
                    <tr>
                        <td class="px-4 py-3"><a class="font-semibold text-emerald-300 hover:text-emerald-200" href="{{ route('saas.admin.support-cases.show', $case) }}">#{{ $case->id }} · {{ $case->subject }}</a></td>
                        <td class="px-4 py-3 text-gray-300">{{ $case->company?->account_code ? $case->company->account_code.' · ' : '' }}{{ $case->company?->name ?? 'Deleted company record' }}</td>
                        <td class="px-4 py-3"><span class="rounded-full border border-gray-700 px-2 py-1 text-xs font-bold text-gray-300">{{ strtoupper($case->status) }}</span></td>
                        <td class="px-4 py-3 text-gray-300">{{ $case->assignedAdmin?->name ?? 'Unassigned' }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $case->updated_at?->format('d M Y, h:i A') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-gray-500">No support cases in this view.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $cases->links() }}</div>
</div>
</x-admin-layout>
