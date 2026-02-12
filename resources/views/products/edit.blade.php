<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 leading-tight">Edit Product: {{ $product->name }}</h2>
            <a href="/products" class="text-sm text-gray-600 hover:text-gray-800">Back to Products</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <form method="POST" action="/products/{{ $product->id }}" class="space-y-6" id="productForm">
                @csrf
                @method('PUT')

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-6 pb-4 border-b border-gray-200">Product Information</h3>
                    <div class="grid grid-cols-1 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Product Name</label>
                            <input type="text" name="name" value="{{ old('name', $product->name) }}" required
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-6 pb-4 border-b border-gray-200">FBR & Tariff Mapping</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">HS Code <span class="text-red-500">*</span></label>
                            <input type="text" name="hs_code" value="{{ old('hs_code', $product->hs_code) }}" required placeholder="25232900"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            <p class="text-gray-500 text-xs mt-2">e.g., 25232900 for Cement</p>
                            @error('hs_code') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">PCT Code <span class="text-gray-400 text-xs">(Optional)</span></label>
                            <input type="text" name="pct_code" value="{{ old('pct_code', $product->pct_code) }}"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            @error('pct_code') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Schedule Type <span class="text-red-500">*</span></label>
                            <select name="schedule_type" required class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500" id="scheduleType">
                                <option value="">Select Schedule Type</option>
                                <option value="standard" {{ old('schedule_type', $product->schedule_type) === 'standard' ? 'selected' : '' }}>Standard</option>
                                <option value="reduced" {{ old('schedule_type', $product->schedule_type) === 'reduced' ? 'selected' : '' }}>Reduced</option>
                                <option value="3rd_schedule" {{ old('schedule_type', $product->schedule_type) === '3rd_schedule' ? 'selected' : '' }}>3rd Schedule</option>
                                <option value="exempt" {{ old('schedule_type', $product->schedule_type) === 'exempt' ? 'selected' : '' }}>Exempt</option>
                                <option value="zero_rated" {{ old('schedule_type', $product->schedule_type) === 'zero_rated' ? 'selected' : '' }}>Zero Rated</option>
                            </select>
                            @error('schedule_type') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                            <div id="mrrNote" class="mt-2 p-3 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg text-xs hidden">
                                <strong>Note:</strong> MRP (Maximum Retail Price) requirement applies to 3rd Schedule items.
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">SRO Reference <span class="text-gray-400 text-xs">(Optional)</span></label>
                            <input type="text" name="sro_reference" value="{{ old('sro_reference', $product->sro_reference) }}" placeholder="e.g., SRO 123/2023"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            @error('sro_reference') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Default Tax Rate (%) <span class="text-red-500">*</span></label>
                            <input type="number" step="0.01" min="0" max="100" name="default_tax_rate" value="{{ old('default_tax_rate', $product->default_tax_rate) }}" required
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            @error('default_tax_rate') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Unit of Measure <span class="text-red-500">*</span></label>
                            <select name="uom" required class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">Select UOM</option>
                                @foreach(['PCS', 'KG', 'LTR', 'MTR', 'SQM', 'CBM', 'DOZ', 'SET', 'PKT'] as $unit)
                                    <option value="{{ $unit }}" {{ old('uom', $product->uom) === $unit ? 'selected' : '' }}>{{ $unit }}</option>
                                @endforeach
                            </select>
                            @error('uom') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-6 pb-4 border-b border-gray-200">Pricing</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Default Price (Rs.) <span class="text-red-500">*</span></label>
                            <input type="number" step="0.01" min="0" name="default_price" value="{{ old('default_price', $product->default_price) }}" required
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            @error('default_price') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <div class="flex justify-end space-x-3">
                    <a href="/products" class="px-6 py-2.5 border border-gray-300 rounded-lg text-gray-700 font-medium hover:bg-gray-50 transition">Cancel</a>
                    <button type="submit" class="px-6 py-2.5 bg-emerald-600 text-white rounded-lg font-medium hover:bg-emerald-700 transition">
                        Update Product
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.getElementById('scheduleType').addEventListener('change', function() {
            const mrrNote = document.getElementById('mrrNote');
            if (this.value === '3rd_schedule') {
                mrrNote.classList.remove('hidden');
            } else {
                mrrNote.classList.add('hidden');
            }
        });

        window.addEventListener('load', function() {
            const scheduleType = document.getElementById('scheduleType');
            if (scheduleType.value === '3rd_schedule') {
                document.getElementById('mrrNote').classList.remove('hidden');
            }
        });
    </script>
</x-app-layout>
