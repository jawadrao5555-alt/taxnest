<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Announcements</h2>
            <button onclick="document.getElementById('addAnnouncementModal').classList.remove('hidden')" class="inline-flex items-center px-3 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">
                <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New Announcement
            </button>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="mb-4 p-4 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300 rounded-lg">{{ session('success') }}</div>
            @endif

            <div class="mb-4 flex gap-2">
                <a href="/admin/announcements" class="px-3 py-1.5 rounded-lg text-sm font-medium {{ !request('status') ? 'bg-emerald-600 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300' }}">All</a>
                <a href="/admin/announcements?status=active" class="px-3 py-1.5 rounded-lg text-sm font-medium {{ request('status') === 'active' ? 'bg-emerald-600 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300' }}">Active</a>
                <a href="/admin/announcements?status=inactive" class="px-3 py-1.5 rounded-lg text-sm font-medium {{ request('status') === 'inactive' ? 'bg-emerald-600 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300' }}">Inactive</a>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-50 dark:bg-gray-900/50 text-gray-600 dark:text-gray-400 text-xs uppercase tracking-wider">
                            <tr>
                                <th class="px-4 py-3">Title</th>
                                <th class="px-4 py-3">Type</th>
                                <th class="px-4 py-3">Target</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Expires</th>
                                <th class="px-4 py-3">Created</th>
                                <th class="px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($announcements as $ann)
                                @php
                                    $typeColors = [
                                        'info' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
                                        'warning' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
                                        'urgent' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
                                        'success' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
                                    ];
                                @endphp
                                <tr class="{{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/50' : '' }}">
                                    <td class="px-4 py-3">
                                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ $ann->title }}</p>
                                        <p class="text-xs text-gray-500 mt-0.5 line-clamp-1">{{ Str::limit($ann->message, 80) }}</p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-0.5 text-xs font-medium rounded-full {{ $typeColors[$ann->type] ?? '' }}">{{ ucfirst($ann->type) }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-400">
                                        @if($ann->target === 'all')
                                            <span class="text-emerald-600 font-medium">All Companies</span>
                                        @else
                                            {{ $ann->targetCompany->name ?? 'N/A' }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if($ann->is_active && (!$ann->expires_at || $ann->expires_at->isFuture()))
                                            <span class="inline-flex items-center gap-1 text-xs font-medium text-green-700"><span class="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse"></span> Active</span>
                                        @elseif($ann->expires_at && $ann->expires_at->isPast())
                                            <span class="text-xs text-gray-400">Expired</span>
                                        @else
                                            <span class="text-xs text-gray-400">Inactive</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-500">{{ $ann->expires_at ? $ann->expires_at->format('d M Y H:i') : 'Never' }}</td>
                                    <td class="px-4 py-3 text-xs text-gray-500">{{ $ann->created_at->format('d M Y') }}<br>by {{ $ann->creator->name ?? '-' }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-1.5">
                                            <form method="POST" action="/admin/announcements/{{ $ann->id }}/toggle" class="inline">
                                                @csrf
                                                <button type="submit" class="text-xs px-2 py-1 rounded {{ $ann->is_active ? 'bg-gray-200 text-gray-700 hover:bg-gray-300' : 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200' }} transition" title="{{ $ann->is_active ? 'Deactivate' : 'Activate' }}">
                                                    {{ $ann->is_active ? 'Deactivate' : 'Activate' }}
                                                </button>
                                            </form>
                                            <form method="POST" action="/admin/announcements/{{ $ann->id }}/delete" onsubmit="return confirm('Delete this announcement?')" class="inline">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="text-red-500 hover:text-red-700">
                                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500">No announcements yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($announcements->hasPages())
                    <div class="p-4 border-t border-gray-200 dark:border-gray-700">{{ $announcements->links() }}</div>
                @endif
            </div>
        </div>
    </div>

    <div id="addAnnouncementModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-black/50" onclick="document.getElementById('addAnnouncementModal').classList.add('hidden')"></div>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl max-w-lg w-full relative z-10">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">New Announcement</h3>
                    <button onclick="document.getElementById('addAnnouncementModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
                <form method="POST" action="/admin/announcements" class="p-6 space-y-4" x-data="{ target: 'all' }">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Title *</label>
                        <input type="text" name="title" required maxlength="255" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Message *</label>
                        <textarea name="message" required rows="3" maxlength="2000" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm"></textarea>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Type *</label>
                            <select name="type" required class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                                <option value="info">Info</option>
                                <option value="warning">Warning</option>
                                <option value="urgent">Urgent</option>
                                <option value="success">Success</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Target *</label>
                            <select name="target" required x-model="target" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                                <option value="all">All Companies</option>
                                <option value="specific">Specific Company</option>
                            </select>
                        </div>
                    </div>
                    <div x-show="target === 'specific'" x-cloak>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Company</label>
                        <select name="target_company_id" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                            <option value="">Select Company</option>
                            @foreach($companies as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Expires At (optional)</label>
                        <input type="datetime-local" name="expires_at" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                    </div>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" onclick="document.getElementById('addAnnouncementModal').classList.add('hidden')" class="px-4 py-2 bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-lg text-sm font-medium">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">Publish</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
