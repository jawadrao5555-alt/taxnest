<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-bold text-xl text-gray-800 leading-tight">Tax Override Rules Management</h2>
            <p class="text-sm text-gray-500 mt-1">Priority: Manual > Customer > Province > Sector > Global</p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8" x-data="{
            activeTab: '{{ $isSuperAdmin ? 'sector' : 'customer' }}',
            showModal: false,
            editingRule: null,
            modalType: '',
            openAdd(type) {
                this.editingRule = null;
                this.modalType = type;
                this.showModal = true;
            },
            openEdit(type, rule) {
                this.editingRule = rule;
                this.modalType = type;
                this.showModal = true;
            },
            closeModal() {
                this.showModal = false;
                this.editingRule = null;
                this.modalType = '';
            }
        }">

            @if(session('success'))
            <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-xl text-green-800 text-sm font-medium">
                {{ session('success') }}
            </div>
            @endif

            @if(session('error'))
            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl text-red-800 text-sm font-medium">
                {{ session('error') }}
            </div>
            @endif

            @if($errors->any())
            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl text-red-800 text-sm">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            <div class="flex flex-wrap gap-2 mb-6">
                @if($isSuperAdmin)
                <button @click="activeTab = 'sector'" :class="activeTab === 'sector' ? 'bg-emerald-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'" class="px-4 py-2 rounded-lg font-medium text-sm transition">Sector Rules</button>
                <button @click="activeTab = 'province'" :class="activeTab === 'province' ? 'bg-emerald-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'" class="px-4 py-2 rounded-lg font-medium text-sm transition">Province Rules</button>
                @endif
                <button @click="activeTab = 'customer'" :class="activeTab === 'customer' ? 'bg-emerald-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'" class="px-4 py-2 rounded-lg font-medium text-sm transition">Customer Rules</button>
                @if($isSuperAdmin)
                <button @click="activeTab = 'sro'" :class="activeTab === 'sro' ? 'bg-emerald-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'" class="px-4 py-2 rounded-lg font-medium text-sm transition">SRO Rules</button>
                @endif
                <button @click="activeTab = 'logs'" :class="activeTab === 'logs' ? 'bg-emerald-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'" class="px-4 py-2 rounded-lg font-medium text-sm transition">Usage Logs</button>
            </div>

            {{-- Sector Rules Tab --}}
            @if($isSuperAdmin)
            <div x-show="activeTab === 'sector'" x-cloak>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-800">Sector Tax Rules</h3>
                        <button @click="openAdd('sector')" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white rounded-lg font-medium text-sm hover:bg-emerald-700 transition">Add New</button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Sector</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">HS Code</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tax Rate</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Schedule</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">SRO Req</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">MRP Req</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @forelse($sectorRules as $rule)
                                <tr class="hover:bg-gray-50 {{ $loop->even ? 'bg-gray-50/50' : '' }}">
                                    <td class="px-4 py-3 text-sm text-gray-900">{{ $rule->sector_type }}</td>
                                    <td class="px-4 py-3 text-sm font-mono text-gray-700">{{ $rule->hs_code }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-700">{{ $rule->override_tax_rate !== null ? $rule->override_tax_rate . '%' : '-' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-700">{{ $rule->override_schedule_type ?? '-' }}</td>
                                    <td class="px-4 py-3 text-sm">{{ $rule->override_sro_required ? 'Yes' : 'No' }}</td>
                                    <td class="px-4 py-3 text-sm">{{ $rule->override_mrp_required ? 'Yes' : 'No' }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $rule->is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">{{ $rule->is_active ? 'Active' : 'Inactive' }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-sm space-x-2">
                                        <button @click="openEdit('sector', {{ json_encode($rule) }})" class="text-emerald-600 hover:text-emerald-800 font-medium">Edit</button>
                                        <form method="POST" action="{{ route('tax-overrides.sector.delete', $rule->id) }}" class="inline" onsubmit="return confirm('Deactivate this rule?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:text-red-800 font-medium">Deactivate</button>
                                        </form>
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="8" class="px-4 py-6 text-center text-gray-400">No sector rules found</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            @endif

            {{-- Province Rules Tab --}}
            @if($isSuperAdmin)
            <div x-show="activeTab === 'province'" x-cloak>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-800">Province Tax Rules</h3>
                        <button @click="openAdd('province')" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white rounded-lg font-medium text-sm hover:bg-emerald-700 transition">Add New</button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Province</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">HS Code</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tax Rate</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Schedule</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">SRO Req</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">MRP Req</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @forelse($provinceRules as $rule)
                                <tr class="hover:bg-gray-50 {{ $loop->even ? 'bg-gray-50/50' : '' }}">
                                    <td class="px-4 py-3 text-sm text-gray-900">{{ $rule->province }}</td>
                                    <td class="px-4 py-3 text-sm font-mono text-gray-700">{{ $rule->hs_code }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-700">{{ $rule->override_tax_rate !== null ? $rule->override_tax_rate . '%' : '-' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-700">{{ $rule->override_schedule_type ?? '-' }}</td>
                                    <td class="px-4 py-3 text-sm">{{ $rule->override_sro_required ? 'Yes' : 'No' }}</td>
                                    <td class="px-4 py-3 text-sm">{{ $rule->override_mrp_required ? 'Yes' : 'No' }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $rule->is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">{{ $rule->is_active ? 'Active' : 'Inactive' }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-sm space-x-2">
                                        <button @click="openEdit('province', {{ json_encode($rule) }})" class="text-emerald-600 hover:text-emerald-800 font-medium">Edit</button>
                                        <form method="POST" action="{{ route('tax-overrides.province.delete', $rule->id) }}" class="inline" onsubmit="return confirm('Deactivate this rule?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:text-red-800 font-medium">Deactivate</button>
                                        </form>
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="8" class="px-4 py-6 text-center text-gray-400">No province rules found</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            @endif

            {{-- Customer Rules Tab --}}
            <div x-show="activeTab === 'customer'" x-cloak>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-800">Customer Tax Rules</h3>
                        <button @click="openAdd('customer')" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white rounded-lg font-medium text-sm hover:bg-emerald-700 transition">Add New</button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer NTN</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">HS Code</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tax Rate</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Schedule</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">SRO Req</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">MRP Req</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @forelse($customerRules as $rule)
                                <tr class="hover:bg-gray-50 {{ $loop->even ? 'bg-gray-50/50' : '' }}">
                                    <td class="px-4 py-3 text-sm font-mono text-gray-900">{{ $rule->customer_ntn }}</td>
                                    <td class="px-4 py-3 text-sm font-mono text-gray-700">{{ $rule->hs_code }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-700">{{ $rule->override_tax_rate !== null ? $rule->override_tax_rate . '%' : '-' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-700">{{ $rule->override_schedule_type ?? '-' }}</td>
                                    <td class="px-4 py-3 text-sm">{{ $rule->override_sro_required ? 'Yes' : 'No' }}</td>
                                    <td class="px-4 py-3 text-sm">{{ $rule->override_mrp_required ? 'Yes' : 'No' }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $rule->is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">{{ $rule->is_active ? 'Active' : 'Inactive' }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-sm space-x-2">
                                        <button @click="openEdit('customer', {{ json_encode($rule) }})" class="text-emerald-600 hover:text-emerald-800 font-medium">Edit</button>
                                        <form method="POST" action="{{ route('tax-overrides.customer.delete', $rule->id) }}" class="inline" onsubmit="return confirm('Deactivate this rule?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:text-red-800 font-medium">Deactivate</button>
                                        </form>
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="8" class="px-4 py-6 text-center text-gray-400">No customer rules found</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- SRO Rules Tab --}}
            @if($isSuperAdmin)
            <div x-show="activeTab === 'sro'" x-cloak>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-800">Special SRO Rules</h3>
                        <button @click="openAdd('sro')" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white rounded-lg font-medium text-sm hover:bg-emerald-700 transition">Add New</button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">SRO #</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">HS Code</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Schedule</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Serial</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rate</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Sector</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Province</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Effective</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @forelse($sroRules as $rule)
                                <tr class="hover:bg-gray-50 {{ $loop->even ? 'bg-gray-50/50' : '' }}">
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $rule->sro_number }}</td>
                                    <td class="px-4 py-3 text-sm font-mono text-gray-700">{{ $rule->hs_code }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-700">{{ $rule->schedule_type }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-700">{{ $rule->serial_no ?? '-' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-700">{{ $rule->concessionary_rate !== null ? $rule->concessionary_rate . '%' : '-' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-700">{{ $rule->applicable_sector ?? '-' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-700">{{ $rule->applicable_province ?? '-' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
                                        {{ $rule->effective_from ? \Carbon\Carbon::parse($rule->effective_from)->format('M d, Y') : '-' }}
                                        @if($rule->effective_until)
                                        <br><span class="text-xs text-gray-400">to {{ \Carbon\Carbon::parse($rule->effective_until)->format('M d, Y') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $rule->is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">{{ $rule->is_active ? 'Active' : 'Inactive' }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-sm space-x-2">
                                        <button @click="openEdit('sro', {{ json_encode($rule) }})" class="text-emerald-600 hover:text-emerald-800 font-medium">Edit</button>
                                        <form method="POST" action="{{ route('tax-overrides.sro.delete', $rule->id) }}" class="inline" onsubmit="return confirm('Deactivate this rule?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:text-red-800 font-medium">Deactivate</button>
                                        </form>
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="10" class="px-4 py-6 text-center text-gray-400">No SRO rules found</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            @endif

            {{-- Usage Logs Tab --}}
            <div x-show="activeTab === 'logs'" x-cloak>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h3 class="text-lg font-semibold text-gray-800">Override Usage Logs</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Company</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">HS Code</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Override Layer</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Original Values</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Overridden Values</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @forelse($usageLogs as $log)
                                <tr class="hover:bg-gray-50 {{ $loop->even ? 'bg-gray-50/50' : '' }}">
                                    <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">{{ $log->created_at->format('M d, Y H:i') }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-900">{{ $log->company->name ?? 'N/A' }}</td>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $log->invoice->invoice_number ?? 'N/A' }}</td>
                                    <td class="px-4 py-3 text-sm font-mono text-gray-700">{{ $log->hs_code ?? '-' }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">{{ $log->override_layer ?? '-' }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-xs font-mono text-gray-600 max-w-xs truncate">{{ is_array($log->original_values) ? json_encode($log->original_values) : ($log->original_values ?? '-') }}</td>
                                    <td class="px-4 py-3 text-xs font-mono text-gray-600 max-w-xs truncate">{{ is_array($log->overridden_values) ? json_encode($log->overridden_values) : ($log->overridden_values ?? '-') }}</td>
                                </tr>
                                @empty
                                <tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">No usage logs found</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Modal Overlay --}}
            <div x-show="showModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
                <div class="flex items-center justify-center min-h-screen px-4">
                    <div x-show="showModal" x-transition:enter="ease-out duration-200" x-transition:leave="ease-in duration-150" class="fixed inset-0 bg-black/50" @click="closeModal()"></div>
                    <div x-show="showModal" x-transition:enter="ease-out duration-200" x-transition:leave="ease-in duration-150" class="relative bg-white rounded-xl shadow-xl max-w-2xl w-full p-6 z-10 max-h-[90vh] overflow-y-auto">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-semibold text-gray-800">
                                <span x-text="editingRule ? 'Edit' : 'Add New'"></span>
                                <span x-show="modalType === 'sector'">Sector Rule</span>
                                <span x-show="modalType === 'province'">Province Rule</span>
                                <span x-show="modalType === 'customer'">Customer Rule</span>
                                <span x-show="modalType === 'sro'">SRO Rule</span>
                            </h3>
                            <button @click="closeModal()" class="text-gray-400 hover:text-gray-600">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>

                        {{-- Sector Form --}}
                        @if($isSuperAdmin)
                        <div x-show="modalType === 'sector'">
                            <form :action="editingRule ? '{{ url('admin/tax-overrides/sector') }}/' + editingRule.id : '{{ route('tax-overrides.sector.store') }}'" method="POST" class="space-y-4">
                                @csrf
                                <template x-if="editingRule"><input type="hidden" name="_method" value="PUT"></template>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Sector Type</label>
                                    <select name="sector_type" x-model="editingRule ? editingRule.sector_type : ''" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm" required>
                                        <option value="">Select Sector</option>
                                        @foreach($sectorTypes as $type)
                                        <option value="{{ $type }}">{{ $type }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">HS Code</label>
                                    <input type="text" name="hs_code" :value="editingRule?.hs_code" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm" required>
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Override Tax Rate (%)</label>
                                        <input type="number" name="override_tax_rate" :value="editingRule?.override_tax_rate" step="0.01" min="0" max="100" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Schedule Type</label>
                                        <select name="override_schedule_type" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm">
                                            <option value="">None</option>
                                            <option value="standard" :selected="editingRule?.override_schedule_type === 'standard'">Standard</option>
                                            <option value="3rd_schedule" :selected="editingRule?.override_schedule_type === '3rd_schedule'">3rd Schedule</option>
                                            <option value="exempt" :selected="editingRule?.override_schedule_type === 'exempt'">Exempt</option>
                                            <option value="zero_rated" :selected="editingRule?.override_schedule_type === 'zero_rated'">Zero Rated</option>
                                            <option value="reduced" :selected="editingRule?.override_schedule_type === 'reduced'">Reduced</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-6">
                                    <label class="flex items-center space-x-2">
                                        <input type="checkbox" name="override_sro_required" value="1" :checked="editingRule?.override_sro_required" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                                        <span class="text-sm text-gray-700">SRO Required</span>
                                    </label>
                                    <label class="flex items-center space-x-2">
                                        <input type="checkbox" name="override_mrp_required" value="1" :checked="editingRule?.override_mrp_required" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                                        <span class="text-sm text-gray-700">MRP Required</span>
                                    </label>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                                    <textarea name="description" rows="2" :value="editingRule?.description" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm"></textarea>
                                </div>
                                <template x-if="editingRule">
                                    <div>
                                        <label class="flex items-center space-x-2">
                                            <input type="checkbox" name="is_active" value="1" :checked="editingRule?.is_active" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                                            <span class="text-sm text-gray-700">Active</span>
                                        </label>
                                    </div>
                                </template>
                                <div class="flex justify-end space-x-3 pt-4">
                                    <button type="button" @click="closeModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg font-medium text-sm hover:bg-gray-300 transition">Cancel</button>
                                    <button type="submit" class="px-4 py-2 bg-emerald-600 text-white rounded-lg font-medium text-sm hover:bg-emerald-700 transition">Save</button>
                                </div>
                            </form>
                        </div>

                        {{-- Province Form --}}
                        <div x-show="modalType === 'province'">
                            <form :action="editingRule ? '{{ url('admin/tax-overrides/province') }}/' + editingRule.id : '{{ route('tax-overrides.province.store') }}'" method="POST" class="space-y-4">
                                @csrf
                                <template x-if="editingRule"><input type="hidden" name="_method" value="PUT"></template>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Province</label>
                                    <select name="province" x-model="editingRule ? editingRule.province : ''" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm" required>
                                        <option value="">Select Province</option>
                                        @foreach($provinces as $province)
                                        <option value="{{ $province }}">{{ $province }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">HS Code</label>
                                    <input type="text" name="hs_code" :value="editingRule?.hs_code" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm" required>
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Override Tax Rate (%)</label>
                                        <input type="number" name="override_tax_rate" :value="editingRule?.override_tax_rate" step="0.01" min="0" max="100" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Schedule Type</label>
                                        <select name="override_schedule_type" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm">
                                            <option value="">None</option>
                                            <option value="standard" :selected="editingRule?.override_schedule_type === 'standard'">Standard</option>
                                            <option value="3rd_schedule" :selected="editingRule?.override_schedule_type === '3rd_schedule'">3rd Schedule</option>
                                            <option value="exempt" :selected="editingRule?.override_schedule_type === 'exempt'">Exempt</option>
                                            <option value="zero_rated" :selected="editingRule?.override_schedule_type === 'zero_rated'">Zero Rated</option>
                                            <option value="reduced" :selected="editingRule?.override_schedule_type === 'reduced'">Reduced</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-6">
                                    <label class="flex items-center space-x-2">
                                        <input type="checkbox" name="override_sro_required" value="1" :checked="editingRule?.override_sro_required" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                                        <span class="text-sm text-gray-700">SRO Required</span>
                                    </label>
                                    <label class="flex items-center space-x-2">
                                        <input type="checkbox" name="override_mrp_required" value="1" :checked="editingRule?.override_mrp_required" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                                        <span class="text-sm text-gray-700">MRP Required</span>
                                    </label>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                                    <textarea name="description" rows="2" :value="editingRule?.description" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm"></textarea>
                                </div>
                                <template x-if="editingRule">
                                    <div>
                                        <label class="flex items-center space-x-2">
                                            <input type="checkbox" name="is_active" value="1" :checked="editingRule?.is_active" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                                            <span class="text-sm text-gray-700">Active</span>
                                        </label>
                                    </div>
                                </template>
                                <div class="flex justify-end space-x-3 pt-4">
                                    <button type="button" @click="closeModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg font-medium text-sm hover:bg-gray-300 transition">Cancel</button>
                                    <button type="submit" class="px-4 py-2 bg-emerald-600 text-white rounded-lg font-medium text-sm hover:bg-emerald-700 transition">Save</button>
                                </div>
                            </form>
                        </div>

                        {{-- SRO Form --}}
                        <div x-show="modalType === 'sro'">
                            <form :action="editingRule ? '{{ url('admin/tax-overrides/sro') }}/' + editingRule.id : '{{ route('tax-overrides.sro.store') }}'" method="POST" class="space-y-4">
                                @csrf
                                <template x-if="editingRule"><input type="hidden" name="_method" value="PUT"></template>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">HS Code</label>
                                        <input type="text" name="hs_code" :value="editingRule?.hs_code" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm" required>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Schedule Type</label>
                                        <input type="text" name="schedule_type" :value="editingRule?.schedule_type" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm" required>
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">SRO Number</label>
                                        <input type="text" name="sro_number" :value="editingRule?.sro_number" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm" required>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Serial No</label>
                                        <input type="text" name="serial_no" :value="editingRule?.serial_no" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm">
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Applicable Sector</label>
                                        <select name="applicable_sector" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm">
                                            <option value="">All Sectors</option>
                                            @foreach($sectorTypes as $type)
                                            <option value="{{ $type }}" :selected="editingRule?.applicable_sector === '{{ $type }}'">{{ $type }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Applicable Province</label>
                                        <select name="applicable_province" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm">
                                            <option value="">All Provinces</option>
                                            @foreach($provinces as $province)
                                            <option value="{{ $province }}" :selected="editingRule?.applicable_province === '{{ $province }}'">{{ $province }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Concessionary Rate (%)</label>
                                    <input type="number" name="concessionary_rate" :value="editingRule?.concessionary_rate" step="0.01" min="0" max="100" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                                    <textarea name="description" rows="2" :value="editingRule?.description" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm"></textarea>
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Effective From</label>
                                        <input type="date" name="effective_from" :value="editingRule?.effective_from?.substring(0, 10)" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Effective Until</label>
                                        <input type="date" name="effective_until" :value="editingRule?.effective_until?.substring(0, 10)" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm">
                                    </div>
                                </div>
                                <template x-if="editingRule">
                                    <div>
                                        <label class="flex items-center space-x-2">
                                            <input type="checkbox" name="is_active" value="1" :checked="editingRule?.is_active" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                                            <span class="text-sm text-gray-700">Active</span>
                                        </label>
                                    </div>
                                </template>
                                <div class="flex justify-end space-x-3 pt-4">
                                    <button type="button" @click="closeModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg font-medium text-sm hover:bg-gray-300 transition">Cancel</button>
                                    <button type="submit" class="px-4 py-2 bg-emerald-600 text-white rounded-lg font-medium text-sm hover:bg-emerald-700 transition">Save</button>
                                </div>
                            </form>
                        </div>
                        @endif

                        {{-- Customer Form --}}
                        <div x-show="modalType === 'customer'">
                            <form :action="editingRule ? '{{ url('admin/tax-overrides/customer') }}/' + editingRule.id : '{{ route('tax-overrides.customer.store') }}'" method="POST" class="space-y-4">
                                @csrf
                                <template x-if="editingRule"><input type="hidden" name="_method" value="PUT"></template>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Customer NTN</label>
                                    <input type="text" name="customer_ntn" :value="editingRule?.customer_ntn" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm" required>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">HS Code</label>
                                    <input type="text" name="hs_code" :value="editingRule?.hs_code" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm" required>
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Override Tax Rate (%)</label>
                                        <input type="number" name="override_tax_rate" :value="editingRule?.override_tax_rate" step="0.01" min="0" max="100" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Schedule Type</label>
                                        <select name="override_schedule_type" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm">
                                            <option value="">None</option>
                                            <option value="standard" :selected="editingRule?.override_schedule_type === 'standard'">Standard</option>
                                            <option value="3rd_schedule" :selected="editingRule?.override_schedule_type === '3rd_schedule'">3rd Schedule</option>
                                            <option value="exempt" :selected="editingRule?.override_schedule_type === 'exempt'">Exempt</option>
                                            <option value="zero_rated" :selected="editingRule?.override_schedule_type === 'zero_rated'">Zero Rated</option>
                                            <option value="reduced" :selected="editingRule?.override_schedule_type === 'reduced'">Reduced</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-6">
                                    <label class="flex items-center space-x-2">
                                        <input type="checkbox" name="override_sro_required" value="1" :checked="editingRule?.override_sro_required" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                                        <span class="text-sm text-gray-700">SRO Required</span>
                                    </label>
                                    <label class="flex items-center space-x-2">
                                        <input type="checkbox" name="override_mrp_required" value="1" :checked="editingRule?.override_mrp_required" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                                        <span class="text-sm text-gray-700">MRP Required</span>
                                    </label>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                                    <textarea name="description" rows="2" :value="editingRule?.description" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm"></textarea>
                                </div>
                                <template x-if="editingRule">
                                    <div>
                                        <label class="flex items-center space-x-2">
                                            <input type="checkbox" name="is_active" value="1" :checked="editingRule?.is_active" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                                            <span class="text-sm text-gray-700">Active</span>
                                        </label>
                                    </div>
                                </template>
                                <div class="flex justify-end space-x-3 pt-4">
                                    <button type="button" @click="closeModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg font-medium text-sm hover:bg-gray-300 transition">Cancel</button>
                                    <button type="submit" class="px-4 py-2 bg-emerald-600 text-white rounded-lg font-medium text-sm hover:bg-emerald-700 transition">Save</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-6">
                <a href="/admin/dashboard" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg font-medium text-sm hover:bg-gray-700 transition">Back to Dashboard</a>
            </div>
        </div>
    </div>
</x-app-layout>
