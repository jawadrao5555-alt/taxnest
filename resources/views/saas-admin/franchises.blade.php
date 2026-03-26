<x-admin-layout>
<div class="p-4 sm:p-6 max-w-7xl mx-auto">
    <h1 class="text-2xl font-bold text-white mb-6">Franchises</h1>

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6" x-data="{ showForm: false }">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-white">Create Franchise</h3>
            <button @click="showForm = !showForm" class="text-xs text-indigo-400 hover:underline" x-text="showForm ? 'Hide' : 'New Franchise'"></button>
        </div>
        <form x-show="showForm" method="POST" action="{{ route('saas.admin.franchises.store') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 items-end">
            @csrf
            <input type="text" name="name" placeholder="Franchise Name" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
            <input type="email" name="email" placeholder="Email" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
            <input type="text" name="phone" placeholder="Phone" class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
            <input type="number" name="commission_rate" placeholder="Commission Rate %" step="0.01" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
            <input type="password" name="password" placeholder="Password" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">Create</button>
        </form>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b border-gray-800 bg-gray-800/50">
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Email</th>
                        <th class="px-4 py-3">Phone</th>
                        <th class="px-4 py-3 text-right">Commission</th>
                        <th class="px-4 py-3 text-right">Companies</th>
                        <th class="px-4 py-3 text-center">Status</th>
                        <th class="px-4 py-3 text-center">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @forelse($franchises as $f)
                    <tr class="hover:bg-gray-800/50">
                        <td class="px-4 py-3 text-white font-medium">{{ $f->name }}</td>
                        <td class="px-4 py-3 text-gray-400">{{ $f->email }}</td>
                        <td class="px-4 py-3 text-gray-400">{{ $f->phone ?? '—' }}</td>
                        <td class="px-4 py-3 text-right text-indigo-400 font-medium">{{ $f->commission_rate }}%</td>
                        <td class="px-4 py-3 text-right text-white">{{ $f->companies_count }}</td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $f->status === 'active' ? 'bg-emerald-900/30 text-emerald-400' : 'bg-red-900/30 text-red-400' }}">{{ $f->status }}</span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <form method="POST" action="{{ route('saas.admin.franchises.toggle', $f->id) }}" class="inline">@csrf
                                <button class="text-xs {{ $f->status === 'active' ? 'text-red-400' : 'text-emerald-400' }}">{{ $f->status === 'active' ? 'Suspend' : 'Activate' }}</button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="px-4 py-12 text-center text-gray-500 dark:text-gray-400">No franchises created yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
</x-admin-layout>
