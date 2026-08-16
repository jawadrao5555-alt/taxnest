<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">POS Surveys</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="mb-4 p-4 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300 rounded-lg">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="mb-4 p-4 bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300 rounded-lg">{{ session('error') }}</div>
            @endif

            <div class="mb-5 flex items-center justify-between gap-3 flex-wrap">
                <p class="text-sm text-gray-500 dark:text-gray-400">One-question-set advice surveys shown as a popup on the PRA POS panel (admins/managers only).</p>
                <form method="POST" action="{{ route('admin.surveys.feature-toggle') }}">
                    @csrf
                    <button type="submit" class="px-3.5 py-2 rounded-lg text-xs font-bold transition {{ $featureOn ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300 hover:bg-green-200' : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300 hover:bg-red-200' }}">
                        Popups: {{ $featureOn ? 'ON — click to disable' : 'OFF — click to enable' }}
                    </button>
                </form>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left table-cards">
                        <thead class="bg-gray-50 dark:bg-gray-900/50 text-gray-600 dark:text-gray-400 text-xs uppercase tracking-wider">
                            <tr>
                                <th class="px-4 py-3">Survey</th>
                                <th class="px-4 py-3">Audience</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Seen / Answered (users)</th>
                                <th class="px-4 py-3">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($surveys as $sv)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/30 align-top">
                                    <td class="px-4 py-3">
                                        <a href="{{ route('admin.surveys.show', $sv->id) }}" class="font-medium text-emerald-600 dark:text-emerald-400 hover:underline">{{ $sv->title }}</a>
                                        <div class="text-xs text-gray-400 mt-0.5">{{ count($sv->questions) }} questions · {{ $sv->created_at->format('d M Y') }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-1 rounded-full text-xs font-medium {{ $sv->audience === 'pos_restaurant' ? 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300' : 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300' }}">
                                            {{ $sv->audience === 'pos_restaurant' ? 'Restaurant-mode only' : 'All PRA POS' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        @if(!$sv->is_published)
                                            <span class="px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">Draft</span>
                                        @elseif($sv->closed_at)
                                            <span class="px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300">Closed {{ $sv->closed_at->format('d M Y') }}</span>
                                        @else
                                            <span class="px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300">Live</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $sv->seen_count }} / <span class="font-bold">{{ $sv->answered_count }}</span></td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <a href="{{ route('admin.surveys.show', $sv->id) }}" class="px-2.5 py-1.5 rounded-lg text-xs font-medium bg-emerald-600 text-white hover:bg-emerald-700">Results</a>
                                            @if($sv->is_published)
                                                <form method="POST" action="{{ route('admin.surveys.toggle-close', $sv->id) }}">
                                                    @csrf
                                                    <button type="submit" class="px-2.5 py-1.5 rounded-lg text-xs font-medium {{ $sv->closed_at ? 'bg-blue-50 text-blue-600 dark:bg-blue-900/20 dark:text-blue-400 hover:bg-blue-100' : 'bg-red-50 text-red-600 dark:bg-red-900/20 dark:text-red-400 hover:bg-red-100' }}">
                                                        {{ $sv->closed_at ? 'Reopen' : 'Close survey' }}
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400">No surveys yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($surveys->hasPages())
                    <div class="px-4 py-3 border-t border-gray-100 dark:border-gray-700">{{ $surveys->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-admin-layout>
