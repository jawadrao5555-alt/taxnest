<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">HS Code Mapping Engine</h2>
            <button onclick="document.getElementById('addMappingModal').classList.remove('hidden')" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">
                <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Add Mapping
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

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <p class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ $stats['total'] }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Total Mappings</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ $stats['active'] }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Active</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <p class="text-2xl font-bold text-amber-600 dark:text-amber-400">{{ $stats['sro_applicable'] }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">SRO Applicable</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <p class="text-2xl font-bold text-purple-600 dark:text-purple-400">{{ $stats['multi_mapped'] }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Multi-Mapped HS</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <p class="text-2xl font-bold text-green-600 dark:text-green-400">{{ $stats['accepted'] }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Accepted</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <p class="text-2xl font-bold text-red-600 dark:text-red-400">{{ $stats['rejected'] }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Rejected</p>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 mb-6">
                <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                    <form method="GET" action="{{ route('admin.hs-mapping-engine') }}" class="flex flex-col sm:flex-row gap-3">
                        <input type="text" name="search" value="{{ $search }}" placeholder="Search HS code, label, SRO, PCT..."
                            class="flex-1 rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm focus:ring-emerald-500/50 focus:border-emerald-500">
                        <select name="sale_type" class="rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm focus:ring-emerald-500/50 focus:border-emerald-500">
                            <option value="">All Sale Types</option>
                            <option value="standard" {{ $saleTypeFilter == 'standard' ? 'selected' : '' }}>Standard Rate</option>
                            <option value="reduced" {{ $saleTypeFilter == 'reduced' ? 'selected' : '' }}>Reduced Rate</option>
                            <option value="3rd_schedule" {{ $saleTypeFilter == '3rd_schedule' ? 'selected' : '' }}>3rd Schedule</option>
                            <option value="exempt" {{ $saleTypeFilter == 'exempt' ? 'selected' : '' }}>Exempt</option>
                            <option value="zero_rated" {{ $saleTypeFilter == 'zero_rated' ? 'selected' : '' }}>Zero Rated</option>
                        </select>
                        <button type="submit" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">Search</button>
                        @if($search || $saleTypeFilter)
                            <a href="{{ route('admin.hs-mapping-engine') }}" class="px-4 py-2 bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-lg text-sm font-medium hover:bg-gray-300 dark:hover:bg-gray-500 transition text-center">Clear</a>
                        @endif
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-50 dark:bg-gray-900/50 text-gray-600 dark:text-gray-400 text-xs uppercase tracking-wider">
                            <tr>
                                <th class="px-4 py-3">HS Code</th>
                                <th class="px-4 py-3">Label</th>
                                <th class="px-4 py-3">Sale Type</th>
                                <th class="px-4 py-3">Tax Rate</th>
                                <th class="px-4 py-3">SRO</th>
                                <th class="px-4 py-3">Serial#</th>
                                <th class="px-4 py-3">MRP</th>
                                <th class="px-4 py-3">PCT</th>
                                <th class="px-4 py-3">Buyer</th>
                                <th class="px-4 py-3">Priority</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($mappings as $mapping)
                                <tr class="{{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/50' : '' }}">
                                    <td class="px-4 py-3 font-mono font-medium text-gray-900 dark:text-gray-100">{{ $mapping->hs_code }}</td>
                                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300 max-w-[200px] truncate">{{ $mapping->label ?: '-' }}</td>
                                    <td class="px-4 py-3">
                                        @php
                                            $typeColors = [
                                                'standard' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
                                                'reduced' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
                                                '3rd_schedule' => 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300',
                                                'exempt' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
                                                'zero_rated' => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
                                            ];
                                        @endphp
                                        <span class="px-2 py-0.5 text-xs font-medium rounded-full {{ $typeColors[$mapping->sale_type] ?? 'bg-gray-100 text-gray-700' }}">
                                            {{ str_replace('_', ' ', ucfirst($mapping->sale_type)) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">{{ $mapping->tax_rate }}%</td>
                                    <td class="px-4 py-3">
                                        @if($mapping->sro_applicable)
                                            <span class="text-emerald-600 dark:text-emerald-400 font-medium">{{ $mapping->sro_number ?: 'Yes' }}</span>
                                        @else
                                            <span class="text-gray-400">No</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if($mapping->serial_number_applicable)
                                            <span class="text-emerald-600 dark:text-emerald-400 font-medium">{{ $mapping->serial_number_value ?: 'Yes' }}</span>
                                        @else
                                            <span class="text-gray-400">No</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if($mapping->mrp_required)
                                            <span class="text-amber-600 dark:text-amber-400 font-medium">Yes</span>
                                        @else
                                            <span class="text-gray-400">No</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-400">{{ $mapping->pct_code ?: '-' }}</td>
                                    <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-400">{{ $mapping->buyer_type ? ucfirst($mapping->buyer_type) : 'Any' }}</td>
                                    <td class="px-4 py-3 font-medium text-gray-700 dark:text-gray-300">{{ $mapping->priority }}</td>
                                    <td class="px-4 py-3">
                                        @if($mapping->is_active)
                                            <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300">Active</span>
                                        @else
                                            <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300">Inactive</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-2">
                                            <button onclick="openEditModal({{ $mapping->id }}, {{ json_encode($mapping) }})" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300" title="Edit">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                            </button>
                                            <form method="POST" action="{{ route('admin.hs-mapping-engine.destroy', $mapping->id) }}" onsubmit="return confirm('Delete this mapping?')" class="inline">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300" title="Delete">
                                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="12" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">No HS code mappings found. Click "Add Mapping" to create one.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($mappings->hasPages())
                    <div class="p-4 border-t border-gray-200 dark:border-gray-700">
                        {{ $mappings->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div id="addMappingModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-black/50" onclick="document.getElementById('addMappingModal').classList.add('hidden')"></div>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl max-w-2xl w-full relative z-10 max-h-[90vh] overflow-y-auto">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center sticky top-0 bg-white dark:bg-gray-800 z-10">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Add HS Code Mapping</h3>
                    <button onclick="document.getElementById('addMappingModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <form method="POST" action="{{ route('admin.hs-mapping-engine.store') }}" class="p-6 space-y-4">
                    @csrf
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">HS Code *</label>
                            <input type="text" name="hs_code" required placeholder="8471.3010" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Label / Description</label>
                            <input type="text" name="label" placeholder="e.g. Laptop Computers" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Sale Type *</label>
                            <select name="sale_type" required class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                                <option value="standard">Standard Rate</option>
                                <option value="reduced">Reduced Rate</option>
                                <option value="3rd_schedule">3rd Schedule</option>
                                <option value="exempt">Exempt</option>
                                <option value="zero_rated">Zero Rated</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Tax Rate (%) *</label>
                            <input type="number" name="tax_rate" required step="0.01" min="0" max="100" value="18" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">PCT Code</label>
                            <input type="text" name="pct_code" placeholder="8471.3010" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Default UOM</label>
                            <input type="text" name="default_uom" placeholder="PCS, KG, LTR..." class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Buyer Type</label>
                            <select name="buyer_type" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                                <option value="">Any Buyer</option>
                                <option value="registered">Registered</option>
                                <option value="unregistered">Unregistered</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Priority (1=Highest)</label>
                            <input type="number" name="priority" min="1" max="100" value="10" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <label class="flex items-center gap-2 p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg cursor-pointer">
                            <input type="checkbox" name="sro_applicable" value="1" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" onchange="document.getElementById('sroNumberField').classList.toggle('hidden', !this.checked)">
                            <span class="text-sm text-gray-700 dark:text-gray-300">SRO Applicable</span>
                        </label>
                        <label class="flex items-center gap-2 p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg cursor-pointer">
                            <input type="checkbox" name="serial_number_applicable" value="1" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" onchange="document.getElementById('serialNumberField').classList.toggle('hidden', !this.checked)">
                            <span class="text-sm text-gray-700 dark:text-gray-300">Serial # Applicable</span>
                        </label>
                        <label class="flex items-center gap-2 p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg cursor-pointer">
                            <input type="checkbox" name="mrp_required" value="1" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                            <span class="text-sm text-gray-700 dark:text-gray-300">MRP Required</span>
                        </label>
                    </div>
                    <div id="sroNumberField" class="hidden">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">SRO Number</label>
                        <input type="text" name="sro_number" placeholder="e.g. SRO 1125(I)/2011" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                    </div>
                    <div id="serialNumberField" class="hidden">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Serial Number Value</label>
                        <input type="text" name="serial_number_value" placeholder="e.g. 18A" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Notes</label>
                        <textarea name="notes" rows="2" placeholder="Additional notes about this mapping..." class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm"></textarea>
                    </div>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" onclick="document.getElementById('addMappingModal').classList.add('hidden')" class="px-4 py-2 bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-lg text-sm font-medium hover:bg-gray-300 dark:hover:bg-gray-500 transition">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">Create Mapping</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="editMappingModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-black/50" onclick="document.getElementById('editMappingModal').classList.add('hidden')"></div>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl max-w-2xl w-full relative z-10 max-h-[90vh] overflow-y-auto">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center sticky top-0 bg-white dark:bg-gray-800 z-10">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Edit HS Code Mapping</h3>
                    <button onclick="document.getElementById('editMappingModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <form id="editForm" method="POST" class="p-6 space-y-4">
                    @csrf @method('PUT')
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">HS Code *</label>
                            <input type="text" name="hs_code" id="edit_hs_code" required class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Label / Description</label>
                            <input type="text" name="label" id="edit_label" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Sale Type *</label>
                            <select name="sale_type" id="edit_sale_type" required class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                                <option value="standard">Standard Rate</option>
                                <option value="reduced">Reduced Rate</option>
                                <option value="3rd_schedule">3rd Schedule</option>
                                <option value="exempt">Exempt</option>
                                <option value="zero_rated">Zero Rated</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Tax Rate (%) *</label>
                            <input type="number" name="tax_rate" id="edit_tax_rate" required step="0.01" min="0" max="100" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">PCT Code</label>
                            <input type="text" name="pct_code" id="edit_pct_code" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Default UOM</label>
                            <input type="text" name="default_uom" id="edit_default_uom" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Buyer Type</label>
                            <select name="buyer_type" id="edit_buyer_type" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                                <option value="">Any Buyer</option>
                                <option value="registered">Registered</option>
                                <option value="unregistered">Unregistered</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Priority (1=Highest)</label>
                            <input type="number" name="priority" id="edit_priority" min="1" max="100" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                        <label class="flex items-center gap-2 p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg cursor-pointer">
                            <input type="checkbox" name="sro_applicable" id="edit_sro_applicable" value="1" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" onchange="document.getElementById('editSroNumberField').classList.toggle('hidden', !this.checked)">
                            <span class="text-sm text-gray-700 dark:text-gray-300">SRO</span>
                        </label>
                        <label class="flex items-center gap-2 p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg cursor-pointer">
                            <input type="checkbox" name="serial_number_applicable" id="edit_serial_applicable" value="1" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" onchange="document.getElementById('editSerialField').classList.toggle('hidden', !this.checked)">
                            <span class="text-sm text-gray-700 dark:text-gray-300">Serial#</span>
                        </label>
                        <label class="flex items-center gap-2 p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg cursor-pointer">
                            <input type="checkbox" name="mrp_required" id="edit_mrp_required" value="1" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                            <span class="text-sm text-gray-700 dark:text-gray-300">MRP</span>
                        </label>
                        <label class="flex items-center gap-2 p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg cursor-pointer">
                            <input type="checkbox" name="is_active" id="edit_is_active" value="1" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                            <span class="text-sm text-gray-700 dark:text-gray-300">Active</span>
                        </label>
                    </div>
                    <div id="editSroNumberField" class="hidden">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">SRO Number</label>
                        <input type="text" name="sro_number" id="edit_sro_number" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                    </div>
                    <div id="editSerialField" class="hidden">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Serial Number Value</label>
                        <input type="text" name="serial_number_value" id="edit_serial_value" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Notes</label>
                        <textarea name="notes" id="edit_notes" rows="2" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm"></textarea>
                    </div>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" onclick="document.getElementById('editMappingModal').classList.add('hidden')" class="px-4 py-2 bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-lg text-sm font-medium hover:bg-gray-300 dark:hover:bg-gray-500 transition">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">Update Mapping</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openEditModal(id, mapping) {
            document.getElementById('editForm').action = '/admin/hs-mapping-engine/' + id;
            document.getElementById('edit_hs_code').value = mapping.hs_code || '';
            document.getElementById('edit_label').value = mapping.label || '';
            document.getElementById('edit_sale_type').value = mapping.sale_type || 'standard';
            document.getElementById('edit_tax_rate').value = mapping.tax_rate || 0;
            document.getElementById('edit_pct_code').value = mapping.pct_code || '';
            document.getElementById('edit_default_uom').value = mapping.default_uom || '';
            document.getElementById('edit_buyer_type').value = mapping.buyer_type || '';
            document.getElementById('edit_priority').value = mapping.priority || 10;
            document.getElementById('edit_notes').value = mapping.notes || '';

            let sroCheck = document.getElementById('edit_sro_applicable');
            sroCheck.checked = !!mapping.sro_applicable;
            document.getElementById('editSroNumberField').classList.toggle('hidden', !mapping.sro_applicable);
            document.getElementById('edit_sro_number').value = mapping.sro_number || '';

            let serialCheck = document.getElementById('edit_serial_applicable');
            serialCheck.checked = !!mapping.serial_number_applicable;
            document.getElementById('editSerialField').classList.toggle('hidden', !mapping.serial_number_applicable);
            document.getElementById('edit_serial_value').value = mapping.serial_number_value || '';

            document.getElementById('edit_mrp_required').checked = !!mapping.mrp_required;
            document.getElementById('edit_is_active').checked = mapping.is_active !== false;

            document.getElementById('editMappingModal').classList.remove('hidden');
        }
    </script>
</x-app-layout>
