<x-app-layout>
    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between mb-6">
                <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Team Members</h2>
                <button onclick="document.getElementById('addUserModal').classList.toggle('hidden')" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition shadow-lg shadow-emerald-500/20">+ Add User</button>
            </div>

            @if(session('success'))
            <div class="mb-4 p-4 bg-emerald-50 border border-emerald-200 rounded-lg text-emerald-700">{{ session('success') }}</div>
            @endif
            @if(session('error'))
            <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">{{ session('error') }}</div>
            @endif

            <div id="addUserModal" class="hidden mb-6">
                <form method="POST" action="/company/users" class="bg-white/70 dark:bg-gray-800/70 backdrop-blur-xl rounded-2xl shadow-lg shadow-black/5 border border-white/30 dark:border-gray-700/30 p-6">
                    @csrf
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Add New Team Member</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name <span class="text-red-500">*</span></label>
                            <input type="text" name="name" required class="w-full rounded-lg bg-white/60 dark:bg-gray-800/60 backdrop-blur-sm border-gray-200/50 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email <span class="text-red-500">*</span></label>
                            <input type="email" name="email" required class="w-full rounded-lg bg-white/60 dark:bg-gray-800/60 backdrop-blur-sm border-gray-200/50 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            @error('email') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Username</label>
                            <input type="text" name="username" placeholder="e.g. ahmed_user" class="w-full rounded-lg bg-white/60 dark:bg-gray-800/60 backdrop-blur-sm border-gray-200/50 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            @error('username') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Phone</label>
                            <input type="text" name="phone" placeholder="e.g. 03001234567" class="w-full rounded-lg bg-white/60 dark:bg-gray-800/60 backdrop-blur-sm border-gray-200/50 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            @error('phone') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Password <span class="text-red-500">*</span></label>
                            <input type="password" name="password" required minlength="6" class="w-full rounded-lg bg-white/60 dark:bg-gray-800/60 backdrop-blur-sm border-gray-200/50 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            @error('password') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Role <span class="text-red-500">*</span></label>
                            <select name="role" required class="w-full rounded-lg bg-white/60 dark:bg-gray-800/60 backdrop-blur-sm border-gray-200/50 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="employee">Employee</option>
                                <option value="company_admin">Company Admin</option>
                                <option value="viewer">Viewer</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-4 flex justify-end">
                        <button type="submit" class="px-6 py-2.5 bg-emerald-600 text-white rounded-lg font-medium hover:bg-emerald-700 transition shadow-lg shadow-emerald-500/20">Add User</button>
                    </div>
                </form>
            </div>

            <div class="bg-white/70 dark:bg-gray-800/70 backdrop-blur-xl rounded-2xl shadow-lg shadow-black/5 border border-white/30 dark:border-gray-700/30 overflow-hidden">
                <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50/50 dark:bg-gray-900/30">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Username / Phone</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Role</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Joined</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-transparent divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse($users as $user)
                        <tr class="hover:bg-white/50 dark:hover:bg-gray-700/30 transition">
                            <td class="px-6 py-4 text-sm font-medium text-gray-900">
                                {{ $user->name }}
                                @if($user->id === auth()->id())
                                <span class="text-xs text-emerald-600 font-normal">(You)</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600">{{ $user->email }}</td>
                            <td class="px-6 py-4 text-sm text-gray-600">
                                @if($user->username)<span class="text-gray-800">{{ $user->username }}</span>@endif
                                @if($user->username && $user->phone) <br> @endif
                                @if($user->phone)<span class="text-gray-500">{{ $user->phone }}</span>@endif
                                @if(!$user->username && !$user->phone)<span class="text-gray-400">-</span>@endif
                            </td>
                            <td class="px-6 py-4">
                                <div x-data="{ editing: false }" class="inline-flex items-center gap-2">
                                    <span x-show="!editing" class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium
                                        @if($user->role === 'company_admin') bg-blue-100 text-blue-800
                                        @elseif($user->role === 'employee') bg-green-100 text-green-800
                                        @else bg-gray-100 text-gray-800
                                        @endif">
                                        {{ ucfirst(str_replace('_', ' ', $user->role)) }}
                                    </span>
                                    @if($user->id !== auth()->id())
                                    <button x-show="!editing" @click="editing = true" class="text-xs text-blue-600 hover:text-blue-800">Change</button>
                                    <form x-show="editing" method="POST" action="/company/users/{{ $user->id }}/role" class="inline-flex items-center gap-1">
                                        @csrf
                                        @method('PATCH')
                                        <select name="role" class="text-xs rounded border-gray-300 py-1">
                                            <option value="company_admin" {{ $user->role === 'company_admin' ? 'selected' : '' }}>Company Admin</option>
                                            <option value="employee" {{ $user->role === 'employee' ? 'selected' : '' }}>Employee</option>
                                            <option value="viewer" {{ $user->role === 'viewer' ? 'selected' : '' }}>Viewer</option>
                                        </select>
                                        <button type="submit" class="text-xs text-emerald-600 hover:text-emerald-800 font-medium">Save</button>
                                        <button type="button" @click="editing = false" class="text-xs text-gray-500">Cancel</button>
                                    </form>
                                    @endif
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                @if($user->is_active)
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Active</span>
                                @else
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">Inactive</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $user->created_at->format('d M Y') }}</td>
                            <td class="px-6 py-4 text-sm">
                                @if($user->id !== auth()->id())
                                <div class="flex items-center gap-2">
                                    <div x-data="{ showReset: false }">
                                        <button @click="showReset = !showReset" class="text-xs text-amber-600 hover:text-amber-800">Reset Password</button>
                                        <form x-show="showReset" method="POST" action="/company/users/{{ $user->id }}/reset-password" class="mt-1 flex items-center gap-1">
                                            @csrf
                                            @method('PATCH')
                                            <input type="password" name="password" minlength="6" placeholder="New password" required class="text-xs rounded border-gray-300 py-1 w-28">
                                            <button type="submit" class="text-xs text-emerald-600 font-medium">Set</button>
                                        </form>
                                    </div>
                                    <form method="POST" action="/company/users/{{ $user->id }}/toggle">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="text-xs {{ $user->is_active ? 'text-red-600 hover:text-red-800' : 'text-green-600 hover:text-green-800' }}">
                                            {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </form>
                                </div>
                                @else
                                <span class="text-xs text-gray-400">-</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="6" class="px-6 py-8 text-center text-gray-400">No team members found</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
