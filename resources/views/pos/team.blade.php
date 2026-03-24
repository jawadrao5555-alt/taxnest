<x-pos-layout>
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
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
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Add New Cashier</h3>
            <button @click="showForm = !showForm" class="text-sm text-purple-600 hover:text-purple-700 font-medium">
                <span x-text="showForm ? 'Cancel' : '+ Add Cashier'"></span>
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
            <div class="sm:col-span-2">
                <button type="submit" class="px-6 py-2 bg-purple-600 text-white text-sm rounded-lg hover:bg-purple-700 transition font-semibold">Create Cashier Account</button>
            </div>
        </form>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 dark:bg-gray-800 text-left text-xs text-gray-500 dark:text-gray-400 uppercase">
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Email</th>
                        <th class="px-4 py-3 hidden sm:table-cell">Phone</th>
                        <th class="px-4 py-3">Role</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($team as $member)
                    <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50" x-data="{ editing: false }">
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
                            @if($member->pos_role === 'pos_admin' || $member->role === 'company_admin')
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400">Admin</span>
                            @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400">Cashier</span>
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
                            @if($member->pos_role === 'pos_cashier')
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
                    <tr><td colspan="6" class="px-4 py-12 text-center text-gray-400">No team members yet. Add your first cashier above.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
</x-pos-layout>
