<x-admin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div>
                <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">HS Code → Tax Rate Mappings</h2>
                <p class="text-xs text-gray-500 mt-0.5">Manual seed + auto-learned from real invoicing</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('admin.hs-rate-links.sample') }}" class="inline-flex items-center px-3 py-2 bg-slate-600 text-white rounded-lg text-sm font-medium hover:bg-slate-700">
                    📄 Sample CSV
                </a>
                <a href="{{ route('admin.hs-rate-links.export') }}" class="inline-flex items-center px-3 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">
                    ⬇ Export CSV
                </a>
                <button onclick="document.getElementById('importModal').classList.remove('hidden')" class="inline-flex items-center px-3 py-2 bg-amber-600 text-white rounded-lg text-sm font-medium hover:bg-amber-700">
                    ⬆ Import CSV
                </button>
                <button onclick="document.getElementById('addModal').classList.remove('hidden')" class="inline-flex items-center px-3 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700">
                    + Add Mapping
                </button>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            @if(session('success'))<div class="mb-4 p-4 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300 rounded-lg">{{ session('success') }}</div>@endif
            @if(session('error'))<div class="mb-4 p-4 bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300 rounded-lg">{{ session('error') }}</div>@endif

            <!-- Stats -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <p class="text-3xl font-bold text-emerald-600">{{ $stats['total'] }}</p>
                    <p class="text-xs text-gray-500 mt-1">Total Mappings</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <p class="text-3xl font-bold text-blue-600">{{ $stats['active'] }}</p>
                    <p class="text-xs text-gray-500 mt-1">Active</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <p class="text-3xl font-bold text-slate-500">{{ $stats['inactive'] }}</p>
                    <p class="text-xs text-gray-500 mt-1">Inactive</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 text-center">
                    <p class="text-3xl font-bold text-purple-600">{{ $stats['auto_learned'] }}</p>
                    <p class="text-xs text-gray-500 mt-1">🤖 Auto-Learned</p>
                </div>
            </div>

            <!-- Filters -->
            <form method="GET" class="bg-white dark:bg-gray-800 p-4 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 mb-4 grid grid-cols-1 md:grid-cols-4 gap-3">
                <input name="q" value="{{ $filters['q'] }}" placeholder="HS code / SRO / notes..." class="px-3 py-2 border rounded-lg text-sm">
                <select name="schedule" class="px-3 py-2 border rounded-lg text-sm">
                    <option value="">All Schedules</option>
                    @foreach($schedules as $s)<option value="{{ $s }}" @selected($filters['schedule']===$s)>{{ $s }}</option>@endforeach
                </select>
                <select name="status" class="px-3 py-2 border rounded-lg text-sm">
                    <option value="">All Status</option>
                    <option value="active" @selected($filters['status']==='active')>Active</option>
                    <option value="inactive" @selected($filters['status']==='inactive')>Inactive</option>
                </select>
                <button class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700">🔍 Filter</button>
            </form>

            <!-- Table -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900 text-xs uppercase text-gray-600 dark:text-gray-400">
                            <tr>
                                <th class="px-3 py-3 text-left">HS Code</th>
                                <th class="px-3 py-3 text-left">Schedule</th>
                                <th class="px-3 py-3 text-right">Rate</th>
                                <th class="px-3 py-3 text-left">Sale Type</th>
                                <th class="px-3 py-3 text-left">SRO</th>
                                <th class="px-3 py-3 text-center">Sr No</th>
                                <th class="px-3 py-3 text-center">UoM</th>
                                <th class="px-3 py-3 text-left">Notes</th>
                                <th class="px-3 py-3 text-center">Status</th>
                                <th class="px-3 py-3 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($rows as $r)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                                <td class="px-3 py-2 font-mono font-bold text-emerald-700">{{ $r->hs_code }}</td>
                                <td class="px-3 py-2"><span class="px-2 py-0.5 bg-blue-100 text-blue-800 rounded text-xs font-medium">{{ $r->schedule_type }}</span></td>
                                <td class="px-3 py-2 text-right font-bold">{{ $r->rate_label ?: ($r->tax_rate !== null ? $r->tax_rate.'%' : '—') }}</td>
                                <td class="px-3 py-2 text-xs text-gray-600">{{ $r->sale_type ?: '—' }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $r->sro_number ?: '—' }}</td>
                                <td class="px-3 py-2 text-center font-mono font-bold text-blue-600">{{ $r->sr_no ?: '—' }}</td>
                                <td class="px-3 py-2 text-center text-xs">{{ $r->uom ?: '—' }}</td>
                                <td class="px-3 py-2 text-xs text-gray-500 italic max-w-xs truncate" title="{{ $r->notes }}">{{ $r->notes ?: '—' }}</td>
                                <td class="px-3 py-2 text-center">
                                    <form method="POST" action="{{ route('admin.hs-rate-links.toggle', $r->id) }}" class="inline">@csrf
                                        <button class="px-2 py-0.5 rounded text-xs font-medium {{ $r->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">
                                            {{ $r->is_active ? '✓ Active' : '✗ Off' }}
                                        </button>
                                    </form>
                                </td>
                                <td class="px-3 py-2 text-center whitespace-nowrap">
                                    <button onclick='editRow(@json($r))' class="text-blue-600 hover:underline text-xs mr-2">Edit</button>
                                    <form method="POST" action="{{ route('admin.hs-rate-links.destroy', $r->id) }}" class="inline" onsubmit="return confirm('Delete this mapping?')">@csrf @method('DELETE')
                                        <button class="text-red-600 hover:underline text-xs">Delete</button>
                                    </form>
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="10" class="px-3 py-12 text-center text-gray-400">No mappings found. Click "+ Add Mapping" or "⬆ Import CSV" to start.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="p-4">{{ $rows->links() }}</div>
            </div>
        </div>
    </div>

    <!-- ADD / EDIT MODAL -->
    <div id="addModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-2xl w-full p-6 max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold" id="modalTitle">Add HS-Rate Mapping</h3>
                <button onclick="closeAdd()" class="text-gray-400 hover:text-gray-600">✕</button>
            </div>
            <form method="POST" id="addForm" action="{{ route('admin.hs-rate-links.store') }}">@csrf
                <input type="hidden" name="_method" id="formMethod" value="POST">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-xs font-semibold text-gray-600">HS Code *</label>
                        <input name="hs_code" id="f_hs_code" required placeholder="3105.3000" class="w-full px-3 py-2 border rounded text-sm font-mono">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-gray-600">Schedule Type *</label>
                        <select name="schedule_type" id="f_schedule_type" required class="w-full px-3 py-2 border rounded text-sm">
                            @foreach($schedules as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-gray-600">Tax Rate (%)</label>
                        <input name="tax_rate" id="f_tax_rate" type="number" step="0.01" placeholder="5" class="w-full px-3 py-2 border rounded text-sm">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-gray-600">Rate Label</label>
                        <input name="rate_label" id="f_rate_label" placeholder="5% or Exempt" class="w-full px-3 py-2 border rounded text-sm">
                    </div>
                    <div class="col-span-2">
                        <label class="text-xs font-semibold text-gray-600">Sale Type</label>
                        <input name="sale_type" id="f_sale_type" placeholder="3rd Schedule Goods" class="w-full px-3 py-2 border rounded text-sm">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-gray-600">SRO Number</label>
                        <input name="sro_number" id="f_sro_number" placeholder="3rd Schedule goods" class="w-full px-3 py-2 border rounded text-sm">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-gray-600">Sr No (3rd Sch)</label>
                        <input name="sr_no" id="f_sr_no" placeholder="51" class="w-full px-3 py-2 border rounded text-sm">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-gray-600">UoM</label>
                        <input name="uom" id="f_uom" placeholder="KG / NO / Litre" class="w-full px-3 py-2 border rounded text-sm">
                    </div>
                    <div class="col-span-2">
                        <label class="text-xs font-semibold text-gray-600">Notes</label>
                        <textarea name="notes" id="f_notes" rows="2" class="w-full px-3 py-2 border rounded text-sm"></textarea>
                    </div>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" onclick="closeAdd()" class="px-4 py-2 bg-gray-200 rounded text-sm">Cancel</button>
                    <button class="px-4 py-2 bg-emerald-600 text-white rounded text-sm font-medium">💾 Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- IMPORT MODAL -->
    <div id="importModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-lg w-full p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold">Import CSV</h3>
                <button onclick="document.getElementById('importModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">✕</button>
            </div>
            <form method="POST" action="{{ route('admin.hs-rate-links.import') }}" enctype="multipart/form-data">@csrf
                <p class="text-xs text-gray-500 mb-3">Required columns: <code class="bg-gray-100 dark:bg-gray-900 px-1.5 py-0.5 rounded text-[11px]">hs_code, schedule_type, tax_rate, rate_label, sale_type, sro_number, sr_no, uom, notes</code></p>
                <p class="text-xs text-blue-600 mb-3"><a href="{{ route('admin.hs-rate-links.sample') }}" class="underline">📄 Download Sample CSV</a> to see the expected format.</p>
                <div class="mb-3">
                    <label class="text-xs font-semibold text-gray-600 block mb-1">CSV File</label>
                    <input type="file" name="csv_file" accept=".csv,text/csv" required class="w-full text-sm">
                </div>
                <div class="mb-4">
                    <label class="text-xs font-semibold text-gray-600 block mb-1">Duplicate Handling</label>
                    <select name="duplicate_mode" class="w-full px-3 py-2 border rounded text-sm">
                        <option value="update">Update existing mappings</option>
                        <option value="skip">Skip existing mappings</option>
                    </select>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="document.getElementById('importModal').classList.add('hidden')" class="px-4 py-2 bg-gray-200 rounded text-sm">Cancel</button>
                    <button class="px-4 py-2 bg-amber-600 text-white rounded text-sm font-medium">⬆ Import</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function closeAdd(){ document.getElementById('addModal').classList.add('hidden'); resetForm(); }
        function resetForm(){
            document.getElementById('modalTitle').textContent = 'Add HS-Rate Mapping';
            document.getElementById('addForm').action = "{{ route('admin.hs-rate-links.store') }}";
            document.getElementById('formMethod').value = 'POST';
            document.getElementById('f_hs_code').readOnly = false;
            ['hs_code','tax_rate','rate_label','sale_type','sro_number','sr_no','uom','notes'].forEach(k => document.getElementById('f_'+k).value = '');
            document.getElementById('f_schedule_type').value = '3rd_schedule';
        }
        function editRow(r){
            document.getElementById('modalTitle').textContent = 'Edit Mapping — '+r.hs_code;
            document.getElementById('addForm').action = '/admin/hs-rate-links/'+r.id;
            document.getElementById('formMethod').value = 'PUT';
            document.getElementById('f_hs_code').value = r.hs_code; document.getElementById('f_hs_code').readOnly = true;
            document.getElementById('f_schedule_type').value = r.schedule_type;
            document.getElementById('f_tax_rate').value = r.tax_rate ?? '';
            document.getElementById('f_rate_label').value = r.rate_label ?? '';
            document.getElementById('f_sale_type').value = r.sale_type ?? '';
            document.getElementById('f_sro_number').value = r.sro_number ?? '';
            document.getElementById('f_sr_no').value = r.sr_no ?? '';
            document.getElementById('f_uom').value = r.uom ?? '';
            document.getElementById('f_notes').value = r.notes ?? '';
            document.getElementById('addModal').classList.remove('hidden');
        }
    </script>
</x-admin-layout>
