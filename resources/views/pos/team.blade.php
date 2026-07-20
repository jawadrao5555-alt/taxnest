<x-pos-layout>
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <a href="{{ route('pos.customize') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400 hover:text-purple-600 dark:hover:text-purple-400 transition mb-3">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        Back to Customize
    </a>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Team Management</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Manage cashier accounts for your POS</p>
        </div>
    </div>

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm">{{ session('error') }}</div>
    @endif
    @if($errors->any())
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm">
        @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
    </div>
    @endif

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6" x-data="{ showForm: false }">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Add Team Member</h3>
            <button @click="showForm = !showForm" class="text-sm text-purple-600 hover:text-purple-700 font-medium">
                <span x-text="showForm ? 'Cancel' : '+ Add Member'"></span>
            </button>
        </div>
        <form x-show="showForm" x-transition method="POST" action="{{ route('pos.team.store-cashier') }}" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            @csrf
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Full Name</label>
                <input type="text" name="name" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500" placeholder="Cashier name">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Email</label>
                <input type="email" name="email" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500" placeholder="cashier@email.com">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Phone (optional)</label>
                <input type="text" name="phone" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500" placeholder="03001234567">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Password</label>
                <input type="password" name="password" required minlength="6" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500" placeholder="Min 6 characters">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Role</label>
                <select name="pos_role" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                    <option value="pos_cashier">Cashier — sale screen &amp; billing only</option>
                    <option value="pos_manager">Manager — full admin access</option>
                    <option value="pos_kitchen">Kitchen — Kitchen Display only (free, no team-slot)</option>
                    <option value="pos_waiter">Waiter — tablet ordering only (free, no team-slot)</option>
                </select>
            </div>
            <div class="sm:col-span-2">
                <button type="submit" class="px-6 py-2 bg-purple-600 text-white text-sm rounded-lg hover:bg-purple-700 transition font-semibold">Create Account</button>
            </div>
        </form>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm table-cards">
                <thead>
                    <tr class="bg-gray-50 dark:bg-gray-800 text-left text-xs text-gray-500 dark:text-gray-400 uppercase">
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Email</th>
                        <th class="px-4 py-3 hidden sm:table-cell">Phone</th>
                        <th class="px-4 py-3">Password</th>
                        <th class="px-4 py-3">Role</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">PRA Reporting</th>
                        <th class="px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($team as $member)
                    <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50" x-data="{ editing: false, showPw: false }">
                        <td class="px-4 py-3">
                            <span x-show="!editing" class="font-medium text-gray-900 dark:text-white">{{ $member->name }}</span>
                            <template x-if="editing">
                                <input form="edit-{{ $member->id }}" type="text" name="name" value="{{ $member->name }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                            </template>
                        </td>
                        <td class="px-4 py-3">
                            <span x-show="!editing" class="text-gray-600 dark:text-gray-400">{{ $member->email }}</span>
                            <template x-if="editing">
                                <input form="edit-{{ $member->id }}" type="email" name="email" value="{{ $member->email }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                            </template>
                        </td>
                        <td class="px-4 py-3 hidden sm:table-cell">
                            <span x-show="!editing" class="text-gray-600 dark:text-gray-400">{{ $member->phone ?? '—' }}</span>
                            <template x-if="editing">
                                <input form="edit-{{ $member->id }}" type="text" name="phone" value="{{ $member->phone }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                            </template>
                        </td>
                        <td class="px-4 py-3">
                            {{-- Owner request (Jul 2026): admin can VIEW team passwords.
                                 Decrypted server-side (admin-gated page); hidden behind an
                                 eye toggle. Old accounts have no stored copy until the
                                 admin sets a new password from the edit row. --}}
                            @if(isset($teamPasswords[$member->id]))
                            <div class="flex items-center gap-1.5">
                                <span class="font-mono text-xs text-gray-700 dark:text-gray-300" x-text="showPw ? {{ \Illuminate\Support\Js::from($teamPasswords[$member->id]) }} : '••••••••'"></span>
                                <button type="button" @click="showPw = !showPw" class="text-gray-400 hover:text-purple-600 dark:hover:text-purple-400 transition" :title="showPw ? 'Hide password' : 'Show password'">
                                    <svg x-show="!showPw" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    <svg x-show="showPw" x-cloak class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                                </button>
                            </div>
                            @elseif(in_array($member->pos_role, ['pos_cashier', 'pos_manager', 'pos_kitchen', 'pos_waiter'], true))
                            <span class="text-xs text-gray-400" title="Is account ka password abhi save nahi hua — Edit se naya password set karein, phir yahan nazar aayega.">Set new password to view</span>
                            @else
                            <span class="text-xs text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($member->pos_role === 'pos_admin' || $member->role === 'company_admin')
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400">Admin</span>
                            @elseif($member->pos_role === 'pos_manager')
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-400">Manager</span>
                            @elseif($member->pos_role === 'pos_kitchen')
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400">Kitchen</span>
                            @elseif($member->pos_role === 'pos_waiter')
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400">Waiter</span>
                            @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300">Cashier</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($member->is_active)
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400">Active</span>
                            @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">Inactive</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            {{-- Owner rule (20 Jul 2026): admin ASSIGNS each cashier Online (PRA
                                 reporting) / Offline here — the sale-screen toggle is read-only
                                 for cashiers. Admin/Manager keep their own sale-screen toggle. --}}
                            @if(($company->pos_integration_mode ?? 'pra') === 'standalone')
                            <span class="text-xs text-gray-400">—</span>
                            @elseif($member->pos_role === 'pos_cashier')
                            @php $memberPraOn = (bool) $member->praReportingEnabled($company); @endphp
                            <form method="POST" action="{{ route('pos.team.set-pra', $member->id) }}" class="inline">
                                @csrf
                                <input type="hidden" name="enabled" value="{{ $memberPraOn ? 0 : 1 }}">
                                <button type="submit"
                                    title="{{ $memberPraOn ? 'Click: is cashier ko OFFLINE karein (bills sirf local banenge)' : 'Click: is cashier ko ONLINE karein (bills PRA ko report honge)' }}"
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold uppercase tracking-wide transition {{ $memberPraOn ? 'bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-100 dark:hover:bg-emerald-900/50' : 'bg-gray-100 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700' }}">
                                    <span class="w-2 h-2 rounded-full {{ $memberPraOn ? 'bg-emerald-500' : 'bg-gray-400' }}"></span>
                                    {{ $memberPraOn ? 'Online' : 'Offline' }}
                                    <svg class="w-3 h-3 opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                                </button>
                            </form>
                            @elseif($member->pos_role === 'pos_admin' || $member->pos_role === 'pos_manager' || $member->role === 'company_admin')
                            @php $memberPraOn = (bool) $member->praReportingEnabled($company); @endphp
                            <span title="Admin/Manager apna PRA Reporting toggle sale screen par khud control karte hain."
                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold uppercase tracking-wide {{ $memberPraOn ? 'bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300' : 'bg-gray-100 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400' }}">
                                <span class="w-2 h-2 rounded-full {{ $memberPraOn ? 'bg-emerald-500' : 'bg-gray-400' }}"></span>
                                {{ $memberPraOn ? 'Online' : 'Offline' }}
                            </span>
                            @else
                            <span class="text-xs text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if(in_array($member->pos_role, ['pos_cashier', 'pos_manager', 'pos_kitchen', 'pos_waiter'], true))
                            <div class="flex items-center gap-2">
                                <button x-show="!editing" @click="editing = true" class="text-amber-600 hover:text-amber-700 text-xs font-medium" title="Edit">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                </button>
                                <template x-if="editing">
                                    <div class="flex items-center gap-1">
                                        <form id="edit-{{ $member->id }}" method="POST" action="{{ route('pos.team.update-cashier', $member->id) }}">
                                            @csrf
                                            @method('PUT')
                                        </form>
                                        {{-- Item #7: optional password reset — blank keeps the current one.
                                             Setting a new password also refreshes the admin-viewable
                                             encrypted copy shown in the Password column. --}}
                                        <input form="edit-{{ $member->id }}" type="password" name="password" placeholder="New password (optional)" autocomplete="new-password" data-lpignore="true" data-form-type="other" data-1p-ignore class="w-36 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs px-2 py-1.5 focus:ring-purple-500 focus:border-purple-500">
                                        <button form="edit-{{ $member->id }}" type="submit" class="text-emerald-600 hover:text-emerald-700 text-xs font-medium">Save</button>
                                        <button @click="editing = false" class="text-gray-400 hover:text-gray-600 text-xs font-medium">Cancel</button>
                                    </div>
                                </template>
                                <form method="POST" action="{{ route('pos.team.toggle-cashier', $member->id) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="{{ $member->is_active ? 'text-red-500 hover:text-red-700' : 'text-emerald-600 hover:text-emerald-700' }} text-xs font-medium" title="{{ $member->is_active ? 'Deactivate' : 'Activate' }}">
                                        {{ $member->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </form>
                            </div>
                            @else
                            <span class="text-xs text-gray-400">Owner</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="px-4 py-12 text-center text-gray-400">No team members yet. Add your first cashier above.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
</x-pos-layout>
