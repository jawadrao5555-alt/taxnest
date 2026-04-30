<x-pos-layout>
<div class="p-4 sm:p-6 max-w-7xl mx-auto">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <h1 class="text-xl font-bold text-gray-900 dark:text-white">POS Products</h1>
        <div class="flex items-center gap-2">
            <button onclick="document.getElementById('importSection').classList.toggle('hidden')" class="w-full sm:w-auto inline-flex items-center justify-center gap-1.5 bg-gradient-to-r from-emerald-500 to-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-semibold shadow-md hover:shadow-lg transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                    Import Excel
            </button>
            <button onclick="document.getElementById('addProductForm').classList.toggle('hidden')" class="w-full sm:w-auto bg-gradient-to-r from-purple-500 to-purple-700 text-white px-4 py-2 rounded-lg text-sm font-semibold shadow-md hover:shadow-lg transition">+ Add Product</button>
        </div>
    </div>

    @if(session('success'))
    <div class="mb-4 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 text-emerald-800 dark:text-emerald-300 rounded-lg px-4 py-3 text-sm">{{ session('success') }}</div>
    @endif
    @if($errors->any())
    <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 text-red-800 dark:text-red-300 rounded-lg px-4 py-3 text-sm">{{ $errors->first() }}</div>
    @endif

    <div id="importSection" class="hidden mb-6 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-md p-5">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">Bulk Import Products from Excel/CSV</h3>

        <div class="mb-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg px-4 py-3">
            <p class="text-xs text-blue-800 dark:text-blue-300"><strong>How it works:</strong> Sirf woh products update honge jinki aap ne price ya details change ki hain. Baqi sab products jaise hain waise hi rahenge. Agar koi naya product CSV mein hai jo list mein nahi, woh add ho jayega. Agar same naam ka product hai, uski price/details update ho jayengi.</p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                <h4 class="text-xs font-bold text-gray-700 dark:text-gray-300 uppercase mb-2">Step 1: Download {{ $products->count() > 0 ? 'Your Products' : 'Template' }}</h4>
                @if($products->count() > 0)
                <p class="text-xs text-gray-500 mb-3">Apni current product list download karein. Excel mein kholein, prices change karein, naye products add karein, phir CSV save karke upload karein.</p>
                @else
                <p class="text-xs text-gray-500 mb-3">Blank template download karein. Excel mein kholein, products fill karein, phir CSV save karke upload karein.</p>
                @endif
                <a href="{{ route('pos.products.template') }}" class="inline-flex items-center gap-1.5 bg-gradient-to-r from-purple-500 to-purple-700 text-white px-5 py-2 rounded-lg text-xs font-semibold shadow-md hover:shadow-lg transition no-underline">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    {{ $products->count() > 0 ? 'Export Products CSV (' . $products->count() . ')' : 'Download Empty Template' }}
                </a>
                <div class="mt-3 text-[11px] text-gray-400">
                    <p class="font-semibold text-gray-500 mb-1">CSV Columns:</p>
                    <p><strong>Name</strong> (required), <strong>Price</strong> (required), Description, Category, SKU, Barcode, Tax Rate %, Unit (UOM)</p>
                </div>
            </div>
            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                <h4 class="text-xs font-bold text-gray-700 dark:text-gray-300 uppercase mb-2">Step 2: Upload Updated File</h4>
                <p class="text-xs text-gray-500 mb-3">CSV file upload karein. Changed products update honge, naye products add honge, baqi untouched rahenge.</p>
                <form method="POST" action="{{ route('pos.products.import') }}" enctype="multipart/form-data" class="space-y-3">
                    @csrf
                    <input type="file" name="csv_file" accept=".csv,.txt" required class="block w-full text-sm text-gray-500 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100 dark:file:bg-purple-900/30 dark:file:text-purple-300">
                    <button type="submit" class="inline-flex items-center gap-1.5 bg-gradient-to-r from-emerald-500 to-emerald-700 text-white px-5 py-2 rounded-lg text-xs font-semibold shadow-md hover:shadow-lg transition">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                        Upload & Import
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div id="addProductForm" class="hidden mb-6 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-md p-5"
         x-data="{ exempt: false }">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">Add New Product</h3>

        {{-- PRA POS tax model helper: keeps UX simple per Pakistan PRA flow --}}
        <div class="mb-4 p-3 rounded-lg bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800">
            <div class="flex items-start gap-2">
                <svg class="w-4 h-4 text-purple-600 dark:text-purple-400 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <div class="text-xs text-purple-800 dark:text-purple-200 leading-relaxed">
                    <strong class="block mb-0.5">PRA Tax — Simple Setup</strong>
                    Default: <strong>16% cash / 5% card</strong> automatically apply hota hai. Agar product ka custom rate hai to <strong>Tax Rate %</strong> field mein dijiye. Tax-free product ke liye seedha <strong class="text-amber-700 dark:text-amber-300">"Tax Exempt"</strong> toggle on karein &mdash; rate khud-ba-khud 0 ho jayega.
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('pos.products.store') }}" enctype="multipart/form-data" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            @csrf
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Product Name *</label>
                <input type="text" name="name" required placeholder="Enter product name" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Price (PKR) *</label>
                <input type="number" name="price" required step="0.01" min="0" placeholder="0.00" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Cost Price <span class="text-gray-400">(for profit)</span></label>
                <input type="number" name="cost_price" step="0.01" min="0" placeholder="0.00" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-emerald-500">
            </div>
            {{-- Unified Tax cell: rate + exempt toggle, both prominent --}}
            <div class="rounded-lg border-2 p-2.5 transition-all"
                 :class="exempt ? 'border-amber-400 bg-amber-50 dark:bg-amber-900/20' : 'border-purple-200 dark:border-purple-800 bg-purple-50/30 dark:bg-purple-900/10'">
                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1.5">Tax Setup</label>
                {{-- When exempt is on, the visible input is disabled (won't submit). This hidden input ensures tax_rate=0 still posts. --}}
                <template x-if="exempt"><input type="hidden" name="tax_rate" value="0"></template>
                <input type="number" name="tax_rate" step="0.01" min="0" max="100"
                       :placeholder="exempt ? '0 (exempt)' : '16'"
                       :disabled="exempt"
                       class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-1.5 focus:ring-2 focus:ring-purple-500 disabled:opacity-50 disabled:bg-gray-100 dark:disabled:bg-gray-700">
                <label class="flex items-center gap-2 mt-2 cursor-pointer select-none">
                    <input type="checkbox" name="is_tax_exempt" value="1" x-model="exempt"
                           class="w-4 h-4 rounded border-gray-300 text-amber-600 focus:ring-amber-500">
                    <span class="text-xs font-bold uppercase tracking-wider"
                          :class="exempt ? 'text-amber-700 dark:text-amber-300' : 'text-gray-600 dark:text-gray-400'">
                        Tax Exempt (Tax-Free)
                    </span>
                </label>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Category</label>
                <input type="text" name="category" placeholder="e.g. Food, Electronics" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">SKU</label>
                <input type="text" name="sku" placeholder="Product SKU" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Barcode</label>
                <input type="text" name="barcode" placeholder="Barcode number" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Unit (UOM)</label>
                <select name="uom" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
                    <option value="NOS">NOS (Numbers)</option>
                    <option value="KGS">KGS (Kilograms)</option>
                    <option value="LTR">LTR (Liters)</option>
                    <option value="MTR">MTR (Meters)</option>
                    <option value="PCS">PCS (Pieces)</option>
                    <option value="PKT">PKT (Packets)</option>
                    <option value="BOX">BOX (Boxes)</option>
                </select>
            </div>
            <div x-data="{ mode: 'none' }">
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Product Image <span class="text-gray-400 text-[10px]">(Optional)</span></label>
                <div class="flex flex-wrap gap-1.5 mb-2">
                    <label class="flex items-center gap-1 cursor-pointer text-[11px] font-semibold px-2.5 py-1 rounded-lg border transition" :class="mode === 'none' ? 'bg-gray-200 dark:bg-gray-700 border-gray-400 text-gray-800 dark:text-gray-100' : 'bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-600 text-gray-500'">
                        <input type="radio" name="image_mode" value="none" x-model="mode" class="hidden"> No Image
                    </label>
                    <label class="flex items-center gap-1 cursor-pointer text-[11px] font-semibold px-2.5 py-1 rounded-lg border transition" :class="mode === 'upload' ? 'bg-purple-100 dark:bg-purple-900/30 border-purple-400 text-purple-700 dark:text-purple-300' : 'bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-600 text-gray-500'">
                        <input type="radio" name="image_mode" value="upload" x-model="mode" class="hidden"> Upload
                    </label>
                    <label class="flex items-center gap-1 cursor-pointer text-[11px] font-semibold px-2.5 py-1 rounded-lg border transition" :class="mode === 'auto' ? 'bg-amber-100 dark:bg-amber-900/30 border-amber-400 text-amber-700 dark:text-amber-300' : 'bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-600 text-gray-500'">
                        <input type="radio" name="image_mode" value="auto" x-model="mode" class="hidden"> Auto-fetch
                    </label>
                </div>
                <input type="file" name="image" accept="image/jpeg,image/jpg,image/png,image/webp"
                    x-show="mode === 'upload'"
                    class="w-full text-sm text-gray-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100 dark:file:bg-purple-900/30 dark:file:text-purple-300">
                <p x-show="mode === 'none'" class="text-[10px] text-gray-500 dark:text-gray-400 italic">List mein sirf product name show ho ga &mdash; koi picture nahi.</p>
                <p x-show="mode === 'auto'" class="text-[10px] text-amber-600 dark:text-amber-400 italic">System khud product ke naam ki picture internet se laye ga.</p>
            </div>
            @if(count($categoryFields) > 0)
            <div class="col-span-full border-t border-gray-200 dark:border-gray-700 pt-3 mt-1">
                <p class="text-xs font-bold uppercase tracking-widest text-purple-600 dark:text-purple-400 mb-3">{{ ucfirst($posType) }} Fields</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    @include('pos.partials.category-fields', ['categoryFields' => $categoryFields, 'product' => null])
                </div>
            </div>
            @endif
            <div class="flex items-end">
                <button type="submit" class="w-full bg-gradient-to-r from-purple-500 to-purple-700 text-white px-5 py-2 rounded-lg text-sm font-semibold shadow-md hover:shadow-lg transition">Save Product</button>
            </div>
        </form>
    </div>

    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-md overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
                        <th class="px-4 py-3">Product</th>
                        <th class="px-4 py-3 hidden md:table-cell">Category</th>
                        <th class="px-4 py-3 hidden lg:table-cell">SKU</th>
                        <th class="px-4 py-3 text-right">Price</th>
                        <th class="px-4 py-3 text-right hidden sm:table-cell">Tax %</th>
                        <th class="px-4 py-3 text-center hidden sm:table-cell">Status</th>
                        <th class="px-4 py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800" x-data="{ editingId: null }">
                    @forelse($products as $product)
                    <tr class="{{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/20' : '' }} {{ !$product->is_active ? 'opacity-50' : '' }}" x-show="editingId !== {{ $product->id }}">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                @if($product->image)
                                    <img src="{{ asset('storage/products/' . $product->image) }}" alt="{{ $product->name }}" class="w-10 h-10 rounded-lg object-cover bg-gray-100 dark:bg-gray-700 flex-shrink-0 border border-gray-200 dark:border-gray-700" onerror="this.style.display='none'">
                                @else
                                    {{-- name-only mode: tiny initials chip so the row doesn't look broken --}}
                                    <div class="w-10 h-10 rounded-lg flex-shrink-0 flex items-center justify-center text-[11px] font-bold text-purple-700 dark:text-purple-300 bg-purple-50 dark:bg-purple-900/20 border border-purple-100 dark:border-purple-800">
                                        {{ strtoupper(mb_substr($product->name, 0, 2)) }}
                                    </div>
                                @endif
                                <div>
                                    <span class="font-medium text-gray-900 dark:text-white">{{ $product->name }}</span>
                                    @if($product->is_tax_exempt)
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400 ml-1">EXEMPT</span>
                                    @endif
                                    @if($product->description)
                                    <div class="text-[10px] text-gray-400 truncate max-w-[180px]">{{ $product->description }}</div>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-500 hidden md:table-cell">{{ $product->category ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-500 text-xs hidden lg:table-cell">{{ $product->sku ?? '—' }}</td>
                        <td class="px-4 py-3 text-right font-medium text-gray-900 dark:text-white">PKR {{ number_format($product->price, 2) }}</td>
                        <td class="px-4 py-3 text-right text-gray-500 hidden sm:table-cell">{{ $product->tax_rate }}%</td>
                        <td class="px-4 py-3 text-center hidden sm:table-cell">
                            <form method="POST" action="{{ route('pos.products.toggle', $product->id) }}" class="inline">
                                @csrf
                                <button type="submit" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $product->is_active ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' }}">
                                    {{ $product->is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </form>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <div class="flex items-center justify-center gap-1">
                                <button @click="editingId = (editingId === {{ $product->id }} ? null : {{ $product->id }})" class="text-xs text-purple-600 hover:text-purple-700 px-2 py-1">Edit</button>
                                <form method="POST" action="{{ route('pos.products.delete', $product->id) }}" onsubmit="return confirm('Delete this product?')" class="inline">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-xs text-red-500 hover:text-red-600 px-2 py-1">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <tr x-show="editingId === {{ $product->id }}" x-cloak class="bg-purple-50/50 dark:bg-purple-900/10">
                        <td colspan="7" class="px-4 py-3">
                            <form method="POST" action="{{ route('pos.products.update', $product->id) }}" enctype="multipart/form-data"
                                  x-data="{ exempt: {{ $product->is_tax_exempt ? 'true' : 'false' }} }"
                                  class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2 items-end">
                                @csrf @method('PUT')
                                <input type="text" name="name" value="{{ $product->name }}" required placeholder="Name" class="text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-2 py-1.5 w-full col-span-2 sm:col-span-1">
                                <input type="number" name="price" value="{{ $product->price }}" step="0.01" required placeholder="Price" class="text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-2 py-1.5 w-full">
                                <input type="number" name="cost_price" value="{{ $product->cost_price ?? 0 }}" step="0.01" min="0" placeholder="Cost" title="Cost Price" class="text-sm rounded-lg border border-emerald-200 dark:border-emerald-800 bg-emerald-50/50 dark:bg-emerald-900/10 text-gray-900 dark:text-white px-2 py-1.5 w-full">
                                {{-- Unified Tax Setup cell: rate + exempt toggle (consolidated, no longer buried) --}}
                                <div class="rounded-lg border-2 px-2 py-1.5 transition-all"
                                     :class="exempt ? 'border-amber-400 bg-amber-50 dark:bg-amber-900/20' : 'border-purple-200 dark:border-purple-800 bg-white dark:bg-gray-800'">
                                    {{-- Hidden submit-safe input ensures tax_rate=0 posts when visible input is disabled --}}
                                    <template x-if="exempt"><input type="hidden" name="tax_rate" value="0"></template>
                                    <input type="number" name="tax_rate" value="{{ $product->tax_rate }}" step="0.01" min="0" max="100"
                                           :placeholder="exempt ? '0 (exempt)' : 'Tax %'"
                                           :disabled="exempt"
                                           class="text-sm rounded border-0 bg-transparent text-gray-900 dark:text-white px-1 py-0 w-full focus:ring-0 focus:outline-none disabled:opacity-50">
                                    <label class="flex items-center gap-1.5 mt-0.5 cursor-pointer select-none">
                                        <input type="checkbox" name="is_tax_exempt" value="1" x-model="exempt"
                                               class="w-3.5 h-3.5 rounded border-gray-300 text-amber-600 focus:ring-amber-500">
                                        <span class="text-[10px] font-bold uppercase tracking-wider"
                                              :class="exempt ? 'text-amber-700 dark:text-amber-300' : 'text-gray-500 dark:text-gray-400'">
                                            Tax Exempt
                                        </span>
                                    </label>
                                </div>
                                <input type="text" name="category" value="{{ $product->category }}" placeholder="Category" class="text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-2 py-1.5 w-full">
                                <input type="text" name="sku" value="{{ $product->sku }}" placeholder="SKU" class="text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-2 py-1.5 w-full">
                                <input type="text" name="barcode" value="{{ $product->barcode }}" placeholder="Barcode" class="text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-2 py-1.5 w-full">
                                <select name="uom" class="text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-2 py-1.5 w-full">
                                    @foreach(['NOS','KGS','LTR','MTR','PCS','PKT','BOX'] as $u)
                                    <option value="{{ $u }}" {{ $product->uom === $u ? 'selected' : '' }}>{{ $u }}</option>
                                    @endforeach
                                </select>
                                <div x-data="{ emode: 'keep' }">
                                    <div class="flex items-center gap-2 mb-1">
                                        @if($product->image)
                                        <img src="{{ asset('storage/products/' . $product->image) }}" class="w-8 h-8 rounded object-cover border" onerror="this.style.display='none'">
                                        @else
                                        <div class="w-8 h-8 rounded flex items-center justify-center text-[9px] font-bold text-purple-700 bg-purple-50 border border-purple-100">{{ strtoupper(mb_substr($product->name, 0, 2)) }}</div>
                                        @endif
                                    </div>
                                    <div class="flex flex-wrap gap-1 mb-1">
                                        <label class="cursor-pointer text-[10px] font-semibold px-1.5 py-0.5 rounded border" :class="emode === 'keep' ? 'bg-gray-200 border-gray-400 text-gray-800' : 'bg-white border-gray-300 text-gray-500'">
                                            <input type="radio" name="image_mode" value="keep" x-model="emode" class="hidden" checked> Keep
                                        </label>
                                        <label class="cursor-pointer text-[10px] font-semibold px-1.5 py-0.5 rounded border" :class="emode === 'upload' ? 'bg-purple-100 border-purple-400 text-purple-700' : 'bg-white border-gray-300 text-gray-500'">
                                            <input type="radio" name="image_mode" value="upload" x-model="emode" class="hidden"> Upload
                                        </label>
                                        <label class="cursor-pointer text-[10px] font-semibold px-1.5 py-0.5 rounded border" :class="emode === 'auto' ? 'bg-amber-100 border-amber-400 text-amber-700' : 'bg-white border-gray-300 text-gray-500'">
                                            <input type="radio" name="image_mode" value="auto" x-model="emode" class="hidden"> Auto
                                        </label>
                                        <label class="cursor-pointer text-[10px] font-semibold px-1.5 py-0.5 rounded border" :class="emode === 'remove' ? 'bg-red-100 border-red-400 text-red-700' : 'bg-white border-gray-300 text-gray-500'">
                                            <input type="radio" name="image_mode" value="remove" x-model="emode" class="hidden"> No Image
                                        </label>
                                    </div>
                                    <input type="file" name="image" accept="image/jpeg,image/jpg,image/png,image/webp"
                                        x-show="emode === 'upload'"
                                        class="w-full text-xs text-gray-500 file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:text-[10px] file:font-semibold file:bg-purple-50 file:text-purple-700">
                                    {{-- Backwards-compat: controller still honours remove_image=1 --}}
                                    <input type="hidden" name="remove_image" :value="emode === 'remove' ? '1' : '0'">
                                </div>
                                @if(count($categoryFields) > 0)
                                <div class="col-span-full border-t border-gray-200 dark:border-gray-700 pt-2 mt-1">
                                    <p class="text-[10px] font-bold uppercase tracking-widest text-purple-500 mb-2">{{ ucfirst($posType) }} Fields</p>
                                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
                                        @include('pos.partials.category-fields', ['categoryFields' => $categoryFields, 'product' => $product, 'isCompact' => true])
                                    </div>
                                </div>
                                @endif
                                <div class="flex gap-2 col-span-2 sm:col-span-1">
                                    <button type="submit" class="text-xs font-semibold text-white px-3 py-1.5 rounded-lg bg-purple-600 hover:bg-purple-700 transition">Save</button>
                                    <button type="button" @click="editingId = null" class="text-xs text-gray-500 px-3 py-1.5">Cancel</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="px-4 py-12 text-center text-gray-500">No products yet. Click "+ Add Product" to create your first POS product.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4 text-xs text-gray-400 text-center">
        These products are exclusive to NestPOS (PRA). Digital Invoice and FBR POS products are managed separately in their own systems.
    </div>
</div>
</x-pos-layout>
