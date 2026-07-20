<x-admin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">POS What's New</h2>
            <button onclick="document.getElementById('addUpdateModal').classList.remove('hidden')" class="inline-flex items-center px-3 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">
                <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New Update
            </button>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="mb-4 p-4 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300 rounded-lg">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="mb-4 p-4 bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300 rounded-lg">{{ session('error') }}</div>
            @endif

            {{-- Master feature switch --}}
            <div class="mb-6 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-5 flex items-center justify-between flex-wrap gap-3">
                <div>
                    <div class="font-semibold text-gray-800 dark:text-gray-100">What's New Notifications (POS)</div>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Controls the one-time popup and the bell icon on the entire NestPOS panel. When OFF, POS users see nothing.</p>
                </div>
                <form method="POST" action="/admin/app-updates/feature-toggle">
                    @csrf
                    <button type="submit" class="inline-flex items-center px-4 py-2 rounded-lg text-sm font-semibold transition {{ $featureOn ? 'bg-emerald-600 text-white hover:bg-emerald-700' : 'bg-gray-300 dark:bg-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-400' }}">
                        <span class="w-2 h-2 rounded-full mr-2 {{ $featureOn ? 'bg-white' : 'bg-gray-500 dark:bg-gray-400' }}"></span>
                        {{ $featureOn ? 'ENABLED — click to disable' : 'DISABLED — click to enable' }}
                    </button>
                </form>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left table-cards">
                        <thead class="bg-gray-50 dark:bg-gray-900/50 text-gray-600 dark:text-gray-400 text-xs uppercase tracking-wider">
                            <tr>
                                <th class="px-4 py-3">Title</th>
                                <th class="px-4 py-3">Points</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Seen By</th>
                                <th class="px-4 py-3">Created</th>
                                <th class="px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($updates as $upd)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/30">
                                    <td class="px-4 py-3 font-medium text-gray-800 dark:text-gray-100">{{ $upd->title }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                                        <ul class="list-disc list-inside space-y-0.5">
                                            @foreach(array_slice($upd->points ?? [], 0, 3) as $pt)
                                                <li class="truncate max-w-xs">{{ $pt }}</li>
                                            @endforeach
                                            @if(count($upd->points ?? []) > 3)
                                                <li class="text-gray-400">+{{ count($upd->points) - 3 }} more…</li>
                                            @endif
                                        </ul>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-1 rounded-full text-xs font-medium {{ $upd->is_published ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                                            {{ $upd->is_published ? 'Published' : 'Hidden' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ number_format($upd->seens_count) }} users</td>
                                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ $upd->created_at->format('d M Y, h:i A') }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-1.5 flex-wrap">
                                            <button type="button"
                                                onclick='openEditModal(@json($upd->id), @json($upd->title), @json(implode("\n", $upd->points ?? [])))'
                                                class="px-2.5 py-1.5 rounded-lg text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200">Edit</button>
                                            <form method="POST" action="/admin/app-updates/{{ $upd->id }}/toggle" class="inline">
                                                @csrf
                                                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-xs font-medium {{ $upd->is_published ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300 hover:bg-amber-200' : 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300 hover:bg-green-200' }}">
                                                    {{ $upd->is_published ? 'Unpublish' : 'Publish' }}
                                                </button>
                                            </form>
                                            <form method="POST" action="/admin/app-updates/{{ $upd->id }}/delete" class="inline" onsubmit="return confirm('Delete this update permanently?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-xs font-medium bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300 hover:bg-red-200">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">No updates yet. Click "New Update" to announce something to POS users.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($updates->hasPages())
                    <div class="px-4 py-3 border-t border-gray-100 dark:border-gray-700">{{ $updates->links() }}</div>
                @endif
            </div>
        </div>
    </div>

    {{-- Add modal --}}
    <div id="addUpdateModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.5);">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                <h3 class="font-bold text-gray-800 dark:text-gray-100">New POS Update</h3>
                <button onclick="document.getElementById('addUpdateModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
            </div>
            <form method="POST" action="/admin/app-updates" class="px-6 py-5 space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Title</label>
                    <input type="text" name="title" required maxlength="150" placeholder="e.g. Naya Update Aya Hai!" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Feature points <span class="text-gray-400 font-normal">(one per line, max 15)</span></label>
                    <textarea name="points_text" required rows="6" maxlength="3000" placeholder="Excel import mein Tax Exempt column aya&#10;Bulk price update ka option aya&#10;Receipt printing behtar hui" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm"></textarea>
                </div>
                <div class="flex items-center gap-2">
                    <input type="checkbox" name="is_published" value="1" checked id="pubNew" class="rounded border-gray-300 text-emerald-600">
                    <label for="pubNew" class="text-sm text-gray-700 dark:text-gray-300">Publish immediately (POS users will see popup + bell)</label>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('addUpdateModal').classList.add('hidden')" class="px-4 py-2 rounded-lg text-sm font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200">Cancel</button>
                    <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold bg-emerald-600 text-white hover:bg-emerald-700">Publish Update</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Edit modal --}}
    <div id="editUpdateModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.5);">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                <h3 class="font-bold text-gray-800 dark:text-gray-100">Edit Update</h3>
                <button onclick="document.getElementById('editUpdateModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
            </div>
            <form method="POST" id="editUpdateForm" action="" class="px-6 py-5 space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Title</label>
                    <input type="text" name="title" id="editTitle" required maxlength="150" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Feature points <span class="text-gray-400 font-normal">(one per line, max 15)</span></label>
                    <textarea name="points_text" id="editPoints" required rows="6" maxlength="3000" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm"></textarea>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('editUpdateModal').classList.add('hidden')" class="px-4 py-2 rounded-lg text-sm font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200">Cancel</button>
                    <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold bg-emerald-600 text-white hover:bg-emerald-700">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openEditModal(id, title, pointsText) {
            document.getElementById('editUpdateForm').action = '/admin/app-updates/' + id + '/update';
            document.getElementById('editTitle').value = title;
            document.getElementById('editPoints').value = pointsText;
            document.getElementById('editUpdateModal').classList.remove('hidden');
        }
    </script>
</x-admin-layout>
