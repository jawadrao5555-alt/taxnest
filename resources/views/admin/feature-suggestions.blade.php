<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Feature Suggestions (POS)</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="mb-4 p-4 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300 rounded-lg">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="mb-4 p-4 bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300 rounded-lg">{{ session('error') }}</div>
            @endif

            {{-- Status filter tabs with counts --}}
            @php
                $tabs = [
                    null => 'All (' . ($counts->sum()) . ')',
                    'pending' => 'Pending (' . ($counts['pending'] ?? 0) . ')',
                    'planned' => 'Planned (' . ($counts['planned'] ?? 0) . ')',
                    'completed' => 'Completed (' . ($counts['completed'] ?? 0) . ')',
                    'rejected' => 'Rejected (' . ($counts['rejected'] ?? 0) . ')',
                ];
            @endphp
            <div class="mb-5 flex items-center gap-2 flex-wrap">
                @foreach($tabs as $key => $label)
                    <a href="/admin/feature-suggestions{{ $key ? '?status=' . $key : '' }}"
                       class="px-3.5 py-2 rounded-lg text-sm font-medium transition {{ $status === $key || (!$status && $key === null) ? 'bg-emerald-600 text-white' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left table-cards">
                        <thead class="bg-gray-50 dark:bg-gray-900/50 text-gray-600 dark:text-gray-400 text-xs uppercase tracking-wider">
                            <tr>
                                <th class="px-4 py-3">Date</th>
                                <th class="px-4 py-3">Company / User</th>
                                <th class="px-4 py-3">Suggestion</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3 min-w-[280px]">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($suggestions as $sugg)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/30 align-top">
                                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ $sugg->created_at->format('d M Y') }}<br><span class="text-xs">{{ $sugg->created_at->format('h:i A') }}</span></td>
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-gray-800 dark:text-gray-100">{{ $sugg->company->name ?? 'Company #' . $sugg->company_id }}</div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $sugg->user->name ?? 'User #' . $sugg->user_id }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-gray-800 dark:text-gray-100">{{ $sugg->title }}</div>
                                        @if($sugg->details)
                                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-md whitespace-pre-line">{{ $sugg->details }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @php
                                            $badgeCls = match($sugg->status) {
                                                'planned' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300',
                                                'completed' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
                                                'rejected' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
                                                default => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
                                            };
                                        @endphp
                                        <span class="px-2 py-1 rounded-full text-xs font-medium {{ $badgeCls }}">{{ ucfirst($sugg->status) }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <form method="POST" action="/admin/feature-suggestions/{{ $sugg->id }}/status" class="flex items-center gap-2 flex-wrap">
                                            @csrf
                                            <select name="status" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-xs py-1.5">
                                                @foreach(['pending' => 'Pending', 'planned' => 'Planned', 'completed' => 'Completed', 'rejected' => 'Rejected'] as $sv => $sl)
                                                    <option value="{{ $sv }}" {{ $sugg->status === $sv ? 'selected' : '' }}>{{ $sl }}</option>
                                                @endforeach
                                            </select>
                                            <input type="text" name="admin_note" maxlength="1000" value="{{ $sugg->admin_note }}" placeholder="Note to customer (optional)"
                                                   class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-xs py-1.5 w-44">
                                            <button type="submit" class="px-2.5 py-1.5 rounded-lg text-xs font-medium bg-emerald-600 text-white hover:bg-emerald-700">Save</button>
                                        </form>
                                        <form method="POST" action="/admin/feature-suggestions/{{ $sugg->id }}/delete" class="mt-1.5" onsubmit="return confirm('Delete this suggestion permanently?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="px-2.5 py-1 rounded-lg text-xs font-medium bg-red-50 text-red-600 dark:bg-red-900/20 dark:text-red-400 hover:bg-red-100">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400">No suggestions yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($suggestions->hasPages())
                    <div class="px-4 py-3 border-t border-gray-100 dark:border-gray-700">{{ $suggestions->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-admin-layout>
