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

    @php
        // Task 1585: category targeting lists come from the SAME source the app
        // uses for its signup pickers (PosFeatureService), so admin, signup and
        // the resolver can never disagree about what a category is.
        $elaanGroups = [
            'pra' => \App\Services\PosFeatureService::categoryGroups('pra'),
            'fbr' => \App\Services\PosFeatureService::categoryGroups('fbr'),
        ];
        $elaanCatLabel = function ($key) {
            $label = __('pos.auth_bt_' . $key);
            return $label === 'pos.auth_bt_' . $key ? ucwords(str_replace('_', ' ', $key)) : $label;
        };
    @endphp

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

            {{-- Search + filters (owner, 20 Jul 2026) --}}
            <form method="GET" action="/admin/app-updates" class="mb-4 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Search</label>
                        <input type="text" name="q" value="{{ request('q') }}" placeholder="Search title or points…" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Status</label>
                        <select name="status" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                            <option value="">All</option>
                            <option value="published" {{ request('status') === 'published' ? 'selected' : '' }}>Published</option>
                            <option value="hidden" {{ request('status') === 'hidden' ? 'selected' : '' }}>Hidden</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">From</label>
                        <input type="date" name="from" value="{{ request('from') }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">To</label>
                        <input type="date" name="to" value="{{ request('to') }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold bg-emerald-600 text-white hover:bg-emerald-700">Filter</button>
                        @if($filtersActive ?? false)
                            <a href="/admin/app-updates" class="px-4 py-2 rounded-lg text-sm font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200">Reset</a>
                        @endif
                    </div>
                </div>
                @if($filtersActive ?? false)
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ number_format($updates->total()) }} {{ $updates->total() === 1 ? 'result' : 'results' }} found</p>
                @endif
            </form>

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
                                        {{-- Owner (20 Jul 2026): show FULL point text — no truncation, no "+N more" --}}
                                        <ul class="list-disc list-inside space-y-1 max-w-xl">
                                            @foreach($upd->points ?? [] as $pt)
                                                <li class="whitespace-normal break-words">{{ $pt }}</li>
                                            @endforeach
                                        </ul>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-1 rounded-full text-xs font-medium {{ $upd->is_published ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                                            {{ $upd->is_published ? 'Published' : 'Hidden' }}
                                        </span>
                                        <span class="mt-1 block px-2 py-1 rounded-full text-[10px] font-semibold text-center {{ $upd->audience === 'fbr_pos' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300' : ($upd->audience === 'all' ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300' : 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300') }}">
                                            {{ $upd->audience === 'fbr_pos' ? 'FBR POS' : ($upd->audience === 'all' ? 'PRA + FBR' : 'PRA POS') }}
                                        </span>
                                        <span class="mt-1 block px-2 py-1 rounded-full text-[10px] font-semibold text-center bg-teal-100 text-teal-700 dark:bg-teal-900/30 dark:text-teal-300">
                                            {{ \App\Support\PosVocabulary::audienceOptions()[$upd->audience_family] ?? \App\Support\PosVocabulary::audienceOptions()['all'] }}
                                        </span>
                                        {{-- Type (Task 1286): accessor normalizes legacy/blank rows to 'improvement' --}}
                                        <span class="mt-1 block px-2 py-1 rounded-full text-[10px] font-semibold text-center {{ $upd->type === 'feature' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300' }}">
                                            {{ $upd->type === 'feature' ? 'Naya Feature' : 'Behtari / Masla Hal' }}
                                        </span>
                                        {{-- Task 1585: category targeting (empty = every shop of the audience) --}}
                                        @php $updCats = array_values((array) ($upd->target_categories ?? [])); @endphp
                                        @if($updCats)
                                            <span class="mt-1 flex flex-wrap gap-1 justify-center">
                                                @foreach($updCats as $updCat)
                                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-teal-100 text-teal-700 dark:bg-teal-900/30 dark:text-teal-300">{{ $elaanCatLabel($updCat) }}</span>
                                                @endforeach
                                            </span>
                                        @else
                                            <span class="mt-1 block px-2 py-1 rounded-full text-[10px] font-medium text-center bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400">Sab shops</span>
                                        @endif
                                        @if($upd->is_featured ?? false)
                                            <span class="mt-1 block px-2 py-1 rounded-full text-[10px] font-bold text-center bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">⭐ Bara Elaan</span>
                                        @endif
                                        {{-- 7-day live window indicator (Task 1286): what POS users can still see --}}
                                        @php $updLive = $upd->is_published && $upd->created_at->gte(now()->subDays(\App\Models\AppUpdate::LIVE_DAYS)); @endphp
                                        @if($updLive)
                                            <span class="mt-1 block px-2 py-1 rounded-full text-[10px] font-bold text-center bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300">● Live on POS</span>
                                        @elseif($upd->is_published)
                                            <span class="mt-1 block px-2 py-1 rounded-full text-[10px] font-medium text-center bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400" title="Published updates auto-disappear from POS users {{ \App\Models\AppUpdate::LIVE_DAYS }} days after publish (history stays here)">Expired ({{ \App\Models\AppUpdate::LIVE_DAYS }} din guzar gaye)</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ number_format($upd->seens_count) }} users</td>
                                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ $upd->created_at->format('d M Y, h:i A') }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-1.5 flex-wrap">
                                            <button type="button"
                                                onclick='openEditModal(@json($upd->id), @json($upd->title), @json(implode("\n", $upd->points ?? [])), @json($upd->image_path ? asset("storage/" . $upd->image_path) : null), @json($upd->audience), @json((bool) ($upd->is_featured ?? false)), @json($upd->type), @json(array_values((array) ($upd->target_categories ?? []))), @json($upd->audience_family))'
                                                class="px-2.5 py-1.5 rounded-lg text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200">Edit</button>
                                            @php $updExpired = $upd->created_at->lt(now()->subDays(\App\Models\AppUpdate::LIVE_DAYS)); @endphp
                                            <form method="POST" action="/admin/app-updates/{{ $upd->id }}/toggle" class="inline"
                                                @if(!$upd->is_published && $updExpired) onsubmit="return confirm('Yeh update {{ \App\Models\AppUpdate::LIVE_DAYS }} din se purana hai — publish karne se DOBARA ELAAN hoga: 7-din ka clock restart hoga aur sab POS users ko popup + bell dobara dikhega. Jaari rakhein?');" @endif>
                                                @csrf
                                                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-xs font-medium {{ $upd->is_published ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300 hover:bg-amber-200' : 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300 hover:bg-green-200' }}">
                                                    {{ $upd->is_published ? 'Unpublish' : ($updExpired ? 'Publish (dobara elaan)' : 'Publish') }}
                                                </button>
                                            </form>
                                            @if($upd->is_published && $updExpired)
                                                {{-- Task 1295: expired-but-published rows need a dedicated re-announce (toggle would just unpublish) --}}
                                                <form method="POST" action="/admin/app-updates/{{ $upd->id }}/reannounce" class="inline" onsubmit="return confirm('Dobara elaan karein? 7-din ka clock restart hoga aur sab POS users ko popup + bell dobara dikhega (pehle wale dismiss reset ho jayenge).');">
                                                    @csrf
                                                    <button type="submit" class="px-2.5 py-1.5 rounded-lg text-xs font-medium bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300 hover:bg-emerald-200">Dobara Elaan Karein</button>
                                                </form>
                                            @endif
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
                                    <td colspan="6" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">
                                        @if($filtersActive ?? false)
                                            No updates match your search/filters. <a href="/admin/app-updates" class="text-emerald-600 hover:underline">Clear filters</a>
                                        @else
                                            No updates yet. Click "New Update" to announce something to POS users.
                                        @endif
                                    </td>
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
            <form method="POST" action="/admin/app-updates" enctype="multipart/form-data" class="px-6 py-5 space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Title</label>
                    <input type="text" name="title" required maxlength="150" placeholder="e.g. Naya Update Aya Hai!" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Feature points <span class="text-gray-400 font-normal">(one per line, max 15)</span></label>
                    <textarea name="points_text" required rows="6" maxlength="3000" placeholder="Excel import mein Tax Exempt column aya&#10;Bulk price update ka option aya&#10;Receipt printing behtar hui" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm"></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Image <span class="text-gray-400 font-normal">(optional — screenshot/banner, JPG/PNG/WebP, max 3MB)</span></label>
                    <input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="w-full text-sm text-gray-600 dark:text-gray-300 file:mr-3 file:px-3 file:py-1.5 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-700 file:text-sm file:font-medium">
                    <p class="mt-1 text-[11px] text-gray-400">Popup mein points ke upar dikhegi. Landscape screenshot best rehta hai.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Audience</label>
                    <select name="audience" id="addAudience" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                        <option value="pos">PRA POS users only</option>
                        <option value="fbr_pos">FBR POS users only</option>
                        <option value="all">Both (PRA + FBR POS)</option>
                    </select>
                </div>
                @include('admin.partials.elaan-categories', ['prefix' => 'add'])
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Audience family</label>
                    <select name="audience_family" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                        @foreach(\App\Support\PosVocabulary::audienceOptions() as $value => $label)
                            <option value="{{ $value }}" {{ old('audience_family', 'all') === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Type</label>
                    <select name="type" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                        <option value="improvement">Behtari / Masla Hal (improvement or fix)</option>
                        <option value="feature">Naya Feature (new feature)</option>
                    </select>
                    <p class="mt-1 text-[11px] text-gray-400">POS popup aur bell mein colored badge dikhega, taake user foran samjhe kya badla hai.</p>
                </div>
                <div class="flex items-start gap-2">
                    <input type="checkbox" name="is_featured" value="1" id="featNew" class="rounded border-gray-300 text-amber-500 mt-0.5">
                    <label for="featNew" class="text-sm text-gray-700 dark:text-gray-300">⭐ Bara elaan (featured) <span class="block text-xs text-gray-400 font-normal">Celebratory hero popup — bare features ke liye. "Abhi Try Karein" button bills/receipts page par le jata hai.</span></label>
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
            <form method="POST" id="editUpdateForm" action="" enctype="multipart/form-data" class="px-6 py-5 space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Title</label>
                    <input type="text" name="title" id="editTitle" required maxlength="150" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Feature points <span class="text-gray-400 font-normal">(one per line, max 15)</span></label>
                    <textarea name="points_text" id="editPoints" required rows="6" maxlength="3000" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm"></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Audience</label>
                    <select name="audience" id="editAudience" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                        <option value="pos">PRA POS users only</option>
                        <option value="fbr_pos">FBR POS users only</option>
                        <option value="all">Both (PRA + FBR POS)</option>
                    </select>
                </div>
                @include('admin.partials.elaan-categories', ['prefix' => 'edit'])
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Audience family</label>
                    <select name="audience_family" id="editAudienceFamily" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                        @foreach(\App\Support\PosVocabulary::audienceOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Type</label>
                    <select name="type" id="editType" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                        <option value="improvement">Behtari / Masla Hal (improvement or fix)</option>
                        <option value="feature">Naya Feature (new feature)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Image <span class="text-gray-400 font-normal">(optional — JPG/PNG/WebP, max 3MB)</span></label>
                    <div id="editCurrentImageWrap" class="hidden mb-2">
                        <img id="editCurrentImage" src="" alt="Current image" class="max-h-32 rounded-lg border border-gray-200 dark:border-gray-600">
                        <label class="mt-1.5 flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                            <input type="checkbox" name="remove_image" value="1" class="rounded border-gray-300 text-red-600"> Remove current image
                        </label>
                    </div>
                    <input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="w-full text-sm text-gray-600 dark:text-gray-300 file:mr-3 file:px-3 file:py-1.5 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-700 file:text-sm file:font-medium">
                    <p class="mt-1 text-[11px] text-gray-400">Nayi image upload karne se purani replace ho jayegi.</p>
                </div>
                <div class="flex items-start gap-2">
                    <input type="checkbox" name="is_featured" value="1" id="featEdit" class="rounded border-gray-300 text-amber-500 mt-0.5">
                    <label for="featEdit" class="text-sm text-gray-700 dark:text-gray-300">⭐ Bara elaan (featured) <span class="block text-xs text-gray-400 font-normal">Celebratory hero popup — bare features ke liye. "Abhi Try Karein" button bills/receipts page par le jata hai.</span></label>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('editUpdateModal').classList.add('hidden')" class="px-4 py-2 rounded-lg text-sm font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200">Cancel</button>
                    <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold bg-emerald-600 text-white hover:bg-emerald-700">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Task 1585: "Which shops" control — All shops vs a category list, with
        // the visible panel sections following the chosen audience.
        function elaanCatBox(prefix) { return document.querySelector('[data-elaan-cats="' + prefix + '"]'); }

        function elaanSyncPanels(prefix, audience) {
            var box = elaanCatBox(prefix);
            if (!box) return;
            box.querySelectorAll('[data-elaan-panel]').forEach(function (sec) {
                var panel = sec.getAttribute('data-elaan-panel');
                var show = audience === 'all' || (audience === 'fbr_pos' ? panel === 'fbr' : panel === 'pra');
                sec.classList.toggle('hidden', !show);
                if (!show) sec.querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = false; });
            });
        }

        function elaanSyncScope(prefix) {
            var box = elaanCatBox(prefix);
            if (!box) return;
            var cats = box.querySelector('[data-elaan-scope="cats"]').checked;
            box.querySelector('[data-elaan-catbox]').classList.toggle('hidden', !cats);
            if (!cats) box.querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = false; });
        }

        function elaanSetCategories(prefix, list) {
            var box = elaanCatBox(prefix);
            if (!box) return;
            var chosen = Array.isArray(list) ? list : [];
            box.querySelectorAll('input[type=checkbox]').forEach(function (c) {
                c.checked = chosen.indexOf(c.value) !== -1;
            });
            box.querySelector('[data-elaan-scope="' + (chosen.length ? 'cats' : 'all') + '"]').checked = true;
            box.querySelector('[data-elaan-catbox]').classList.toggle('hidden', chosen.length === 0);
        }

        ['add', 'edit'].forEach(function (prefix) {
            var box = elaanCatBox(prefix);
            if (!box) return;
            box.querySelectorAll('[data-elaan-scope]').forEach(function (r) {
                r.addEventListener('change', function () { elaanSyncScope(prefix); });
            });
            box.querySelectorAll('[data-elaan-pick]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var keys = (btn.getAttribute('data-elaan-pick') || '').split(',');
                    box.querySelector('[data-elaan-scope="cats"]').checked = true;
                    box.querySelector('[data-elaan-catbox]').classList.remove('hidden');
                    keys.forEach(function (k) {
                        var el = box.querySelector('input[data-elaan-cat="' + k + '"]');
                        if (el && !el.closest('[data-elaan-panel]').classList.contains('hidden')) el.checked = true;
                    });
                });
            });
            var sel = document.getElementById(prefix + 'Audience');
            if (sel) {
                sel.addEventListener('change', function () { elaanSyncPanels(prefix, sel.value); });
                elaanSyncPanels(prefix, sel.value);
            }
        });

        function openEditModal(id, title, pointsText, imageUrl, audience, isFeatured, type, categories, audienceFamily) {
            document.getElementById('editUpdateForm').action = '/admin/app-updates/' + id + '/update';
            document.getElementById('editTitle').value = title;
            document.getElementById('editPoints').value = pointsText;
            document.getElementById('editAudience').value = ['pos','fbr_pos','all'].includes(audience) ? audience : 'pos';
            document.getElementById('editAudienceFamily').value = ['all','food_service','goods_retail','pharmacy','services'].includes(audienceFamily) ? audienceFamily : 'all';
            document.getElementById('editType').value = type === 'feature' ? 'feature' : 'improvement';
            elaanSyncPanels('edit', document.getElementById('editAudience').value);
            elaanSetCategories('edit', categories || []);
            document.getElementById('featEdit').checked = !!isFeatured;
            var wrap = document.getElementById('editCurrentImageWrap');
            var img = document.getElementById('editCurrentImage');
            if (imageUrl) {
                img.src = imageUrl;
                wrap.classList.remove('hidden');
            } else {
                img.src = '';
                wrap.classList.add('hidden');
            }
            var rm = document.querySelector('#editUpdateForm input[name="remove_image"]');
            if (rm) rm.checked = false;
            document.getElementById('editUpdateModal').classList.remove('hidden');
        }
    </script>
</x-admin-layout>
