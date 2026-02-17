<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Suppliers</h2>
            <button onclick="document.getElementById('addSupplierModal').classList.remove('hidden')" class="inline-flex items-center px-3 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">
                <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Add Supplier
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

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
                <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                    <form method="GET" action="{{ route('suppliers.index') }}" class="flex gap-3">
                        <input type="text" name="search" value="{{ $search }}" placeholder="Search name, NTN, phone, city..."
                            class="flex-1 rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                        <button type="submit" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">Search</button>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-50 dark:bg-gray-900/50 text-gray-600 dark:text-gray-400 text-xs uppercase tracking-wider">
                            <tr>
                                <th class="px-4 py-3">Name</th>
                                <th class="px-4 py-3">NTN</th>
                                <th class="px-4 py-3">Phone</th>
                                <th class="px-4 py-3">City</th>
                                <th class="px-4 py-3">Contact</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($suppliers as $sup)
                                <tr class="{{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/50' : '' }}">
                                    <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">{{ $sup->name }}</td>
                                    <td class="px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-400">{{ $sup->ntn ?: '-' }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-400">{{ $sup->phone ?: '-' }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-400">{{ $sup->city ?: '-' }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-400 text-xs">{{ $sup->contact_person ?: '-' }}</td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-0.5 text-xs font-medium rounded-full {{ $sup->is_active ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300' }}">
                                            {{ $sup->is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-1.5">
                                            <button onclick="openEditSupplier({{ $sup->id }}, {{ json_encode($sup) }})" class="text-blue-600 hover:text-blue-800 dark:text-blue-400" title="Edit">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                            </button>
                                            <form method="POST" action="{{ route('suppliers.destroy', $sup->id) }}" onsubmit="return confirm('Delete this supplier?')" class="inline">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="text-red-600 hover:text-red-800 dark:text-red-400" title="Delete">
                                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">No suppliers added yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($suppliers->hasPages())
                    <div class="p-4 border-t border-gray-200 dark:border-gray-700">{{ $suppliers->links() }}</div>
                @endif
            </div>
        </div>
    </div>

    <div id="addSupplierModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-black/50" onclick="document.getElementById('addSupplierModal').classList.add('hidden')"></div>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl max-w-lg w-full relative z-10">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Add Supplier</h3>
                    <button onclick="document.getElementById('addSupplierModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
                <form method="POST" action="{{ route('suppliers.store') }}" class="p-6 space-y-4">
                    @csrf
                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2"><label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Name *</label><input type="text" name="name" required class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm"></div>
                        <div><label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">NTN</label><input type="text" name="ntn" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm"></div>
                        <div><label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">CNIC</label><input type="text" name="cnic" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm"></div>
                        <div><label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Phone</label><input type="text" name="phone" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm"></div>
                        <div><label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Email</label><input type="email" name="email" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm"></div>
                        <div><label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">City</label><input type="text" name="city" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm"></div>
                        <div><label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Contact Person</label><input type="text" name="contact_person" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm"></div>
                        <div class="col-span-2"><label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Address</label><input type="text" name="address" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm"></div>
                        <div class="col-span-2"><label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Notes</label><textarea name="notes" rows="2" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm"></textarea></div>
                    </div>
                    <div class="flex justify-end gap-3">
                        <button type="button" onclick="document.getElementById('addSupplierModal').classList.add('hidden')" class="px-4 py-2 bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-lg text-sm font-medium">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">Add Supplier</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="editSupplierModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-black/50" onclick="document.getElementById('editSupplierModal').classList.add('hidden')"></div>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl max-w-lg w-full relative z-10">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Edit Supplier</h3>
                    <button onclick="document.getElementById('editSupplierModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
                <form id="editSupplierForm" method="POST" class="p-6 space-y-4">
                    @csrf @method('PUT')
                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2"><label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Name *</label><input type="text" name="name" id="es_name" required class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm"></div>
                        <div><label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">NTN</label><input type="text" name="ntn" id="es_ntn" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm"></div>
                        <div><label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">CNIC</label><input type="text" name="cnic" id="es_cnic" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm"></div>
                        <div><label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Phone</label><input type="text" name="phone" id="es_phone" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm"></div>
                        <div><label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Email</label><input type="email" name="email" id="es_email" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm"></div>
                        <div><label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">City</label><input type="text" name="city" id="es_city" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm"></div>
                        <div><label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Contact Person</label><input type="text" name="contact_person" id="es_contact" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm"></div>
                        <div class="col-span-2"><label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Address</label><input type="text" name="address" id="es_address" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm"></div>
                        <div class="col-span-2"><label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Notes</label><textarea name="notes" id="es_notes" rows="2" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm"></textarea></div>
                        <div class="col-span-2">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="is_active" id="es_active" value="1" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                                <span class="text-sm text-gray-700 dark:text-gray-300">Active</span>
                            </label>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3">
                        <button type="button" onclick="document.getElementById('editSupplierModal').classList.add('hidden')" class="px-4 py-2 bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-lg text-sm font-medium">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">Update Supplier</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openEditSupplier(id, sup) {
            document.getElementById('editSupplierForm').action = '/suppliers/' + id;
            document.getElementById('es_name').value = sup.name || '';
            document.getElementById('es_ntn').value = sup.ntn || '';
            document.getElementById('es_cnic').value = sup.cnic || '';
            document.getElementById('es_phone').value = sup.phone || '';
            document.getElementById('es_email').value = sup.email || '';
            document.getElementById('es_city').value = sup.city || '';
            document.getElementById('es_contact').value = sup.contact_person || '';
            document.getElementById('es_address').value = sup.address || '';
            document.getElementById('es_notes').value = sup.notes || '';
            document.getElementById('es_active').checked = sup.is_active !== false;
            document.getElementById('editSupplierModal').classList.remove('hidden');
        }
    </script>
</x-app-layout>
