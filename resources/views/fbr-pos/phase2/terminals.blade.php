<x-fbr-pos-layout>
<div class="max-w-5xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Counters / Terminals</h1>
            <p class="text-sm text-gray-500 mt-1">Manage your POS counters (multi-cashier setup)</p>
        </div>
    </div>

    @if(session('success'))<div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded mb-4">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4">{{ session('error') }}</div>@endif

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-5 mb-5">
        <h2 class="font-bold mb-3 text-gray-900 dark:text-white">+ Add New Counter</h2>
        <form method="POST" action="{{ route('fbrpos.phase2.terminals.store') }}" class="grid sm:grid-cols-3 gap-3">
            @csrf
            <input type="text" name="terminal_name" placeholder="e.g. Counter 1" required class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white">
            <input type="text" name="location" placeholder="Location (optional)" class="border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white">
            <button class="bg-blue-600 text-white rounded-lg px-4 py-2 text-sm font-semibold hover:bg-blue-700">Add Counter</button>
        </form>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700 text-left">
                <tr>
                    <th class="px-4 py-3">Name</th><th>Code</th><th>Location</th><th>Status</th><th class="text-right pr-4">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($terminals as $t)
                <tr class="border-t dark:border-gray-700">
                    <td class="px-4 py-3 font-semibold dark:text-white">{{ $t->terminal_name }}</td>
                    <td class="dark:text-gray-300"><code>{{ $t->terminal_code }}</code></td>
                    <td class="dark:text-gray-300">{{ $t->location ?? '—' }}</td>
                    <td>@if($t->is_active)<span class="px-2 py-1 bg-emerald-100 text-emerald-700 rounded text-xs">Active</span>@else<span class="px-2 py-1 bg-gray-100 text-gray-700 rounded text-xs">Inactive</span>@endif</td>
                    <td class="text-right pr-4 py-3">
                        <form method="POST" action="{{ route('fbrpos.phase2.terminals.toggle', $t->id) }}" class="inline">
                            @csrf
                            <button class="text-blue-600 hover:underline text-sm">{{ $t->is_active ? 'Deactivate' : 'Activate' }}</button>
                        </form>
                        <form method="POST" action="{{ route('fbrpos.phase2.terminals.delete', $t->id) }}" class="inline ml-2" onsubmit="return confirm('Delete?')">
                            @csrf @method('DELETE')
                            <button class="text-red-600 hover:underline text-sm">Delete</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">No counters yet. Add one above.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
</x-fbr-pos-layout>
