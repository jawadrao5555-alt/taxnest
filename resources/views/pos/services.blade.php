<x-pos-layout>
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">POS Services</h1>
    </div>

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 text-sm">{{ session('success') }}</div>
    @endif

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Add New Service</h3>
        <form method="POST" action="{{ route('pos.services.store') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
            @csrf
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Name</label>
                <input type="text" name="name" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500" placeholder="Service name">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Price (PKR)</label>
                <input type="number" name="price" required step="0.01" min="0" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500" placeholder="0.00">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Tax Rate (%)</label>
                <input type="number" name="tax_rate" step="0.01" min="0" max="100" value="0" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Description</label>
                <input type="text" name="description" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500" placeholder="Optional">
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full px-4 py-2 bg-emerald-600 text-white text-sm rounded-lg hover:bg-emerald-700 transition">Add Service</button>
            </div>
        </form>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50 dark:bg-gray-800 text-left text-xs text-gray-500 uppercase">
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3 hidden md:table-cell">Description</th>
                    <th class="px-4 py-3 text-right">Price</th>
                    <th class="px-4 py-3 text-right hidden sm:table-cell">Tax Rate</th>
                    <th class="px-4 py-3 hidden sm:table-cell">Status</th>
                    <th class="px-4 py-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($services as $service)
                <tr class="border-b border-gray-100 dark:border-gray-800 {{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/20' : '' }}" x-data="{ editing: false }">
                    <td class="px-4 py-3 font-medium text-gray-900 dark:text-white" x-show="!editing">{{ $service->name }}</td>
                    <td class="px-4 py-3 text-gray-600 dark:text-gray-400 hidden md:table-cell" x-show="!editing">{{ $service->description ?? '-' }}</td>
                    <td class="px-4 py-3 text-right text-gray-900 dark:text-white" x-show="!editing">PKR {{ number_format($service->price, 2) }}</td>
                    <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300 hidden sm:table-cell" x-show="!editing">{{ $service->tax_rate }}%</td>
                    <td class="px-4 py-3 hidden sm:table-cell" x-show="!editing">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $service->is_active ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400' }}">
                            {{ $service->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td class="px-4 py-3" x-show="!editing">
                        <div class="flex items-center gap-2">
                            <button @click="editing = true" class="text-blue-600 hover:underline text-xs">Edit</button>
                            <form method="POST" action="{{ route('pos.services.delete', $service->id) }}" onsubmit="return confirm('Delete this service?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-red-600 hover:underline text-xs">Delete</button>
                            </form>
                        </div>
                    </td>
                    <td colspan="6" class="px-4 py-3" x-show="editing" x-cloak>
                        <form method="POST" action="{{ route('pos.services.update', $service->id) }}" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2 items-end">
                            @csrf @method('PUT')
                            <input type="text" name="name" value="{{ $service->name }}" required placeholder="Name" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs px-2 py-1 w-full">
                            <input type="text" name="description" value="{{ $service->description }}" placeholder="Description" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs px-2 py-1 w-full">
                            <input type="number" name="price" value="{{ $service->price }}" step="0.01" required placeholder="Price" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs px-2 py-1 w-full">
                            <input type="number" name="tax_rate" value="{{ $service->tax_rate }}" step="0.01" placeholder="Tax %" class="rounded border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs px-2 py-1 w-full">
                            <div class="flex items-center gap-2">
                                <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="is_active" {{ $service->is_active ? 'checked' : '' }} class="rounded"> Active</label>
                                <button type="submit" class="text-emerald-600 text-xs font-medium hover:underline">Save</button>
                                <button type="button" @click="editing = false" class="text-gray-500 text-xs hover:underline">Cancel</button>
                            </div>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-4 py-12 text-center text-gray-400">No services yet. Add your first service above.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>
</x-pos-layout>