<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Edit HS Code: {{ $record->hs_code }}</h2>
            <a href="{{ route('admin.hs-master-global.index') }}" class="inline-flex items-center px-4 py-2 bg-gray-500 text-white rounded-lg text-sm font-medium hover:bg-gray-600 transition">
                <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
                Back to List
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="mb-4 p-4 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300 rounded-lg">{{ session('success') }}</div>
            @endif
            @if($errors->any())
                <div class="mb-4 p-4 bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300 rounded-lg">
                    <ul class="list-disc list-inside">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.hs-master-global.update', $record->id) }}" class="space-y-6">
                @csrf

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Basic Information</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">HS Code</label>
                            <input type="text" name="hs_code" value="{{ old('hs_code', $record->hs_code) }}" readonly
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-600 dark:text-gray-300 text-sm bg-gray-100 cursor-not-allowed">
                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">HS Code cannot be changed</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
                            <input type="text" name="description" value="{{ old('description', $record->description) }}"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Schedule Type</label>
                            <select name="schedule_type" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">— None —</option>
                                <option value="standard" {{ old('schedule_type', $record->schedule_type) === 'standard' ? 'selected' : '' }}>Standard</option>
                                <option value="3rd_schedule" {{ old('schedule_type', $record->schedule_type) === '3rd_schedule' ? 'selected' : '' }}>3rd Schedule</option>
                                <option value="exempt" {{ old('schedule_type', $record->schedule_type) === 'exempt' ? 'selected' : '' }}>Exempt</option>
                                <option value="zero_rated" {{ old('schedule_type', $record->schedule_type) === 'zero_rated' ? 'selected' : '' }}>Zero Rated</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Default Tax Rate (%)</label>
                            <input type="number" step="0.01" min="0" max="99.99" name="default_tax_rate" value="{{ old('default_tax_rate', $record->default_tax_rate) }}"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Default UOM</label>
                            <select name="default_uom" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">— None —</option>
                                @foreach(['NOS', 'KGS', 'LTR', 'MTR', 'SQM', 'CBM', 'PKT', 'SET', 'PCS', 'TON'] as $uom)
                                    <option value="{{ $uom }}" {{ old('default_uom', $record->default_uom) === $uom ? 'selected' : '' }}>{{ $uom }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Confidence Score (0-100)</label>
                            <input type="number" min="0" max="100" name="confidence_score" value="{{ old('confidence_score', $record->confidence_score) }}"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">SRO / Serial / MRP Requirements</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="flex items-center space-x-3">
                            <input type="checkbox" name="sro_required" value="1" {{ old('sro_required', $record->sro_required) ? 'checked' : '' }}
                                class="rounded border-gray-300 dark:border-gray-600 text-emerald-600 focus:ring-emerald-500">
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">SRO Required</label>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Default SRO Number</label>
                            <input type="text" name="default_sro_number" value="{{ old('default_sro_number', $record->default_sro_number) }}"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div class="flex items-center space-x-3">
                            <input type="checkbox" name="serial_required" value="1" {{ old('serial_required', $record->serial_required) ? 'checked' : '' }}
                                class="rounded border-gray-300 dark:border-gray-600 text-emerald-600 focus:ring-emerald-500">
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Serial Required</label>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Default Serial No</label>
                            <input type="text" name="default_serial_no" value="{{ old('default_serial_no', $record->default_serial_no) }}"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div class="flex items-center space-x-3">
                            <input type="checkbox" name="mrp_required" value="1" {{ old('mrp_required', $record->mrp_required) ? 'checked' : '' }}
                                class="rounded border-gray-300 dark:border-gray-600 text-emerald-600 focus:ring-emerald-500">
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">MRP Required</label>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Applicability Flags</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="flex items-center space-x-3">
                            <input type="checkbox" name="st_withheld_applicable" value="1" {{ old('st_withheld_applicable', $record->st_withheld_applicable) ? 'checked' : '' }}
                                class="rounded border-gray-300 dark:border-gray-600 text-emerald-600 focus:ring-emerald-500">
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">ST Withheld Applicable</label>
                        </div>
                        <div class="flex items-center space-x-3">
                            <input type="checkbox" name="petroleum_levy_applicable" value="1" {{ old('petroleum_levy_applicable', $record->petroleum_levy_applicable) ? 'checked' : '' }}
                                class="rounded border-gray-300 dark:border-gray-600 text-emerald-600 focus:ring-emerald-500">
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Petroleum Levy Applicable</label>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Status</h3>
                    <div class="flex items-center space-x-3">
                        <input type="checkbox" name="is_active" value="1" {{ old('is_active', $record->is_active) ? 'checked' : '' }}
                            class="rounded border-gray-300 dark:border-gray-600 text-emerald-600 focus:ring-emerald-500">
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Active</label>
                    </div>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-2">Last Source: {{ $record->last_source }} | Last Updated: {{ $record->updated_at?->format('M d, Y H:i') ?? 'Never' }}</p>
                </div>

                <div class="flex justify-end space-x-3">
                    <a href="{{ route('admin.hs-master-global.index') }}" class="px-6 py-2.5 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg text-sm font-medium hover:bg-gray-300 dark:hover:bg-gray-600 transition">Cancel</a>
                    <button type="submit" class="px-6 py-2.5 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
