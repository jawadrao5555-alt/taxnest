<x-pos-layout>
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">POS Terminals</h1>
    </div>

    @if(session('success'))
    <div class="mb-4 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 rounded-xl p-4">
        <p class="text-sm text-emerald-800 dark:text-emerald-300 font-medium">{{ session('success') }}</p>
    </div>
    @endif
    @if(session('error'))
    <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-xl p-4">
        <p class="text-sm text-red-800 dark:text-red-300 font-medium">{{ session('error') }}</p>
    </div>
    @endif

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">Add New Terminal</h3>
        <form method="POST" action="{{ route('pos.terminals.store') }}" class="grid grid-cols-1 sm:grid-cols-4 gap-4">
            @csrf
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Terminal Name</label>
                <input type="text" name="terminal_name" required placeholder="e.g. Counter 1" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Terminal Code</label>
                <input type="text" name="terminal_code" required placeholder="e.g. T001" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Location</label>
                <input type="text" name="location" placeholder="e.g. Main Floor" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-lg transition">Add Terminal</button>
            </div>
        </form>
        @if($errors->any())
        <div class="mt-3">
            <ul class="text-xs text-red-600 list-disc pl-4">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
        @endif
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50 dark:bg-gray-800 text-left text-xs text-gray-500 dark:text-gray-400 uppercase">
                    <th class="px-4 py-3">Terminal Name</th>
                    <th class="px-4 py-3">Code</th>
                    <th class="px-4 py-3 hidden sm:table-cell">Location</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3 hidden sm:table-cell">Transactions</th>
                    <th class="px-4 py-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($terminals as $terminal)
                <tr class="border-b border-gray-100 dark:border-gray-800 {{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/20' : '' }}" x-data="{ editing: false }">
                    <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">
                        <span x-show="!editing">{{ $terminal->terminal_name }}</span>
                        <form x-show="editing" method="POST" action="{{ route('pos.terminals.update', $terminal->id) }}" class="flex flex-wrap gap-2 items-center" x-cloak>
                            @csrf @method('PUT')
                            <input type="text" name="terminal_name" value="{{ $terminal->terminal_name }}" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm px-2 py-1 w-full sm:w-28">
                            <input type="text" name="terminal_code" value="{{ $terminal->terminal_code }}" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm px-2 py-1 w-full sm:w-20">
                            <input type="text" name="location" value="{{ $terminal->location }}" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm px-2 py-1 w-full sm:w-24">
                            <label class="flex items-center gap-1 text-xs text-gray-600 dark:text-gray-400">
                                <input type="checkbox" name="is_active" {{ $terminal->is_active ? 'checked' : '' }} class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                                Active
                            </label>
                            <button type="submit" class="px-2 py-1 bg-emerald-600 text-white text-xs rounded hover:bg-emerald-700">Save</button>
                            <button type="button" @click="editing = false" class="px-2 py-1 bg-gray-300 dark:bg-gray-600 text-gray-700 dark:text-gray-300 text-xs rounded">Cancel</button>
                        </form>
                    </td>
                    <td class="px-4 py-3 text-gray-600 dark:text-gray-400 font-mono" x-show="!editing">{{ $terminal->terminal_code }}</td>
                    <td class="px-4 py-3 text-gray-600 dark:text-gray-400 hidden sm:table-cell" x-show="!editing">{{ $terminal->location ?? '-' }}</td>
                    <td class="px-4 py-3" x-show="!editing">
                        @if($terminal->is_active)
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400">Active</span>
                        @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">Inactive</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-gray-600 dark:text-gray-400 hidden sm:table-cell" x-show="!editing">{{ $terminal->transactions_count ?? $terminal->transactions()->count() }}</td>
                    <td class="px-4 py-3" x-show="!editing">
                        <div class="flex gap-2">
                            <button @click="editing = true" class="text-blue-600 hover:underline text-xs font-medium">Edit</button>
                            @if(!$terminal->transactions()->exists())
                            <form method="POST" action="{{ route('pos.terminals.delete', $terminal->id) }}" onsubmit="return confirm('Delete this terminal?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-red-600 hover:underline text-xs font-medium">Delete</button>
                            </form>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-4 py-12 text-center text-gray-400">No terminals configured</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>
</x-pos-layout>
