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
                            <label class="block text-sm font-medium text-gray-700 mb-1">Default Tax Rate (%) <span class="text-red-500">*</span></label>
                            <input type="number" step="1" min="0" max="100" name="default_tax_rate" id="taxRateInput" value="{{ old('default_tax_rate', intval($product->default_tax_rate)) }}" required
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            @error('default_tax_rate') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div id="sroSection" class="mb-6 hidden">
                        <div class="p-4 bg-amber-50 border border-amber-200 rounded-lg">
                            <div class="flex items-center justify-between mb-2">
                                <label class="block text-sm font-medium text-amber-700">SRO Reference <span class="text-red-500">*</span> <span class="text-xs text-amber-600">(Required when tax rate is below 18%)</span></label>
                                <button type="button" id="sroLookupBtn" class="text-xs text-emerald-600 hover:text-emerald-700 font-medium flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                                    SRO Lookup
                                </button>
                            </div>
                            <input type="text" name="sro_reference" id="sro_reference" value="{{ old('sro_reference', $product->sro_reference) }}" placeholder="e.g., SRO 1125(I)/2011"
                                class="w-full rounded-lg border-amber-300 bg-amber-50 shadow-sm focus:ring-amber-500 focus:border-amber-500">
                            @error('sro_reference') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror

                            <div id="sroLookupPanel" class="mt-2 border border-gray-200 rounded-lg bg-white p-3 hidden">
                                <div class="flex gap-2 mb-2">
                                    <input type="text" id="sroSearchInput" placeholder="Search SRO rules..." class="flex-1 rounded-lg border-gray-300 shadow-sm text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                    <button type="button" onclick="searchSroForProduct()" class="px-3 py-1.5 bg-emerald-600 text-white rounded-lg text-sm hover:bg-emerald-700">Search</button>
                                </div>
                                <div id="sroSearchResults" class="max-h-48 overflow-y-auto space-y-1"></div>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
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
        const scheduleTypeEl = document.getElementById('scheduleType');
        const taxRateEl = document.getElementById('taxRateInput');
        const sroSection = document.getElementById('sroSection');
        const sroInput = document.getElementById('sro_reference');
        const sroLookupBtn = document.getElementById('sroLookupBtn');
        const sroLookupPanel = document.getElementById('sroLookupPanel');

        function checkSroVisibility() {
            const rate = parseInt(taxRateEl.value) || 0;
            if (rate < 18) {
                sroSection.classList.remove('hidden');
            } else {
                sroSection.classList.add('hidden');
            }
        }

        scheduleTypeEl.addEventListener('change', function() {
            const mrrNote = document.getElementById('mrrNote');
            if (this.value === '3rd_schedule') {
                mrrNote.classList.remove('hidden');
            } else {
                mrrNote.classList.add('hidden');
            }
        });

        taxRateEl.addEventListener('input', function() {
            this.value = Math.round(parseFloat(this.value) || 0);
            checkSroVisibility();
        });

        taxRateEl.addEventListener('change', function() {
            this.value = Math.round(parseFloat(this.value) || 0);
            checkSroVisibility();
        });

        sroLookupBtn?.addEventListener('click', function() {
            sroLookupPanel.classList.toggle('hidden');
        });

        document.getElementById('sroSearchInput')?.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); searchSroForProduct(); }
        });

        function searchSroForProduct() {
            const q = document.getElementById('sroSearchInput').value;
            const scheduleType = scheduleTypeEl.value;
            const resultsDiv = document.getElementById('sroSearchResults');
            if (q.length < 2 && !scheduleType) {
                resultsDiv.innerHTML = '<p class="text-xs text-gray-500">Type at least 2 characters to search</p>';
                return;
            }
            resultsDiv.innerHTML = '<p class="text-xs text-gray-500">Searching...</p>';
            let url = '/api/sro-reference/search?q=' + encodeURIComponent(q);
            if (scheduleType) url += '&schedule_type=' + encodeURIComponent(scheduleType);
            fetch(url).then(r => r.json()).then(data => {
                let html = '';
                const rules = data.sro_rules || [];
                if (rules.length === 0) {
                    html = '<p class="text-xs text-gray-500">No results found</p>';
                } else {
                    rules.forEach(r => {
                        const badge = {exempt:'bg-green-100 text-green-700', zero_rated:'bg-blue-100 text-blue-700', '3rd_schedule':'bg-purple-100 text-purple-700', reduced:'bg-amber-100 text-amber-700'}[r.schedule_type] || 'bg-gray-100 text-gray-700';
                        html += '<div class="p-2 bg-white border border-gray-200 rounded cursor-pointer hover:bg-emerald-50 transition" onclick="selectSroForProduct(\'' + (r.sro_number || '').replace(/'/g, "\\'") + '\')">' +
                            '<div class="flex items-center justify-between">' +
                            '<span class="text-sm font-medium text-gray-800">' + (r.sro_number || '') + '</span>' +
                            '<span class="text-xs px-1.5 py-0.5 rounded ' + badge + '">' + (r.schedule_type || '').replace('_', ' ') + '</span>' +
                            '</div>' +
                            '<p class="text-xs text-gray-500 mt-0.5">' + (r.description || '') + '</p>' +
                            (r.serial_no ? '<p class="text-xs text-gray-400">Serial: ' + r.serial_no + '</p>' : '') +
                            '</div>';
                    });
                }
                resultsDiv.innerHTML = html;
            }).catch(() => {
                resultsDiv.innerHTML = '<p class="text-xs text-red-500">Search failed</p>';
            });
        }

        function selectSroForProduct(sroNumber) {
            sroInput.value = sroNumber;
        }

        window.addEventListener('load', function() {
            if (scheduleTypeEl.value === '3rd_schedule') {
                document.getElementById('mrrNote').classList.remove('hidden');
            }
            checkSroVisibility();
        });

        document.getElementById('productForm')?.addEventListener('submit', function(e) {
            const rate = parseInt(taxRateEl.value) || 0;
            if (rate < 18 && !sroInput.value.trim()) {
                e.preventDefault();
                alert('SRO Reference is required when tax rate is below 18%');
                sroInput.focus();
            }
        });
    </script>
</x-app-layout>
