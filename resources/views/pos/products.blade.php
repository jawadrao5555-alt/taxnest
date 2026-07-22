<x-pos-layout>
<div class="p-4 sm:p-6 max-w-7xl mx-auto">
    @include('pos.partials.back-link')
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
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">Bulk Import Products (Excel)</h3>

        <div class="mb-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg px-4 py-3">
            <p class="text-xs text-blue-800 dark:text-blue-300"><strong>Aasan tareeqa:</strong> Excel file download karein &rarr; usi file mein products likhein ya prices badlein &rarr; wahi file wapis upload kar dein. Bas! Naye products add ho jayenge, purane update ho jayenge, baqi sab waise hi rahenge. CSV banane ki koi zaroorat NAHI.</p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                <h4 class="text-xs font-bold text-gray-700 dark:text-gray-300 uppercase mb-2">Step 1: Excel File Download Karein</h4>
                @if($products->count() > 0)
                <p class="text-xs text-gray-500 mb-3">Aap ki poori product list Excel file mein aa jayegi. Excel mein kholein, prices badlein ya neeche naye products likhein.</p>
                @else
                <p class="text-xs text-gray-500 mb-3">Khali template download karein — usme 3 example products likhe hain, unki jagah apne products likhein (example rows khud skip ho jati hain).</p>
                @endif
                <a href="{{ route('pos.products.template') }}" class="inline-flex items-center gap-1.5 bg-gradient-to-r from-purple-500 to-purple-700 text-white px-5 py-2 rounded-lg text-xs font-semibold shadow-md hover:shadow-lg transition no-underline">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    {{ $products->count() > 0 ? 'Download Excel (' . $products->count() . ' Products)' : 'Download Excel Template' }}
                </a>
                <div class="mt-3 text-[11px] text-gray-400">
                    <p class="font-semibold text-gray-500 mb-1">Sirf 2 cheezein zaroori hain:</p>
                    <p><strong>Name</strong> aur <strong>Price</strong> — baqi columns (Description, Category, SKU, Barcode, Tax Rate %, Unit) chahein to bharein, warna khali chhor dein.</p>
                </div>
            </div>
            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                <h4 class="text-xs font-bold text-gray-700 dark:text-gray-300 uppercase mb-2">Step 2: Wahi File Wapis Upload Karein</h4>
                <p class="text-xs text-gray-500 mb-3">Excel (.xlsx) file seedha upload ho jati hai — CSV bhi chalti hai agar aap ke paas purani file hai.</p>
                <form method="POST" action="{{ route('pos.products.import') }}" enctype="multipart/form-data" class="space-y-3">
                    @csrf
                    <input type="file" name="csv_file" accept=".xlsx,.xls,.csv,.txt" required class="block w-full text-sm text-gray-500 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100 dark:file:bg-purple-900/30 dark:file:text-purple-300">
                    <button type="submit" class="inline-flex items-center gap-1.5 bg-gradient-to-r from-emerald-500 to-emerald-700 text-white px-5 py-2 rounded-lg text-xs font-semibold shadow-md hover:shadow-lg transition">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                        Upload & Import
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div id="addProductForm" class="hidden mb-6 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-md p-5"
         x-data="{ exempt: false, price: '', cost: '' }">
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

        <form method="POST" action="{{ route('pos.products.store') }}" enctype="multipart/form-data" class="space-y-5">
            @csrf

            {{-- ── SECTION: Basic Information ── --}}
            <div class="rounded-xl border border-gray-200 dark:border-gray-800 bg-gray-50/60 dark:bg-gray-800/20 p-4">
                <div class="flex items-center gap-2 mb-3">
                    <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5a1.99 1.99 0 011.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.99 1.99 0 013 12V7a4 4 0 014-4z"/></svg>
                    <span class="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Basic Information</span>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Product Name *</label>
                        <input type="text" name="name" required placeholder="Enter product name" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Category</label>
                        <input type="text" name="category" placeholder="e.g. Food, Electronics" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">SKU</label>
                        <input type="text" name="sku" placeholder="Product SKU" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
                    </div>
                    <div class="sm:col-span-2 lg:col-span-3">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Description <span class="text-gray-400">(optional)</span></label>
                        <input type="text" name="description" placeholder="Short description" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Barcode</label>
                        <input type="text" name="barcode" placeholder="Barcode number" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
                    </div>
                </div>
            </div>

            {{-- ── SECTION: Pricing & Tax ── --}}
            <div class="rounded-xl border border-gray-200 dark:border-gray-800 bg-gray-50/60 dark:bg-gray-800/20 p-4">
                <div class="flex items-center gap-2 mb-3">
                    <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span class="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Pricing &amp; Tax</span>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Price (PKR) *</label>
                        <input type="number" name="price" x-model.number="price" required step="0.01" min="0" placeholder="0.00" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Cost Price <span class="text-gray-400">(for profit)</span></label>
                        <input type="number" name="cost_price" x-model.number="cost" step="0.01" min="0" placeholder="0.00" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-emerald-500">
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
                </div>
                {{-- Live profit / margin preview (computes as you type) --}}
                <div x-show="Number(price) > 0 && Number(cost) > 0 && Number(cost) <= Number(price)" x-cloak
                     class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-1 text-xs font-semibold text-emerald-700 dark:text-emerald-300 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-lg px-3 py-2">
                    <span>Margin: <span class="font-extrabold" x-text="Math.round((Number(price) - Number(cost)) / Number(price) * 100) + '%'"></span></span>
                    <span>Profit / unit: <span class="font-extrabold" x-text="'Rs ' + (Number(price) - Number(cost)).toFixed(2)"></span></span>
                </div>
                <div x-show="Number(price) > 0 && Number(cost) > Number(price)" x-cloak
                     class="mt-3 flex items-center gap-2 text-xs font-semibold text-red-700 dark:text-red-300 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg px-3 py-2">
                    <svg class="w-3.5 h-3.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                    Cost price, sale price se zyada hai — yeh product loss par jayega.
                </div>
                {{-- Sale screen visibility toggle --}}
                <label class="mt-3 flex items-center gap-2.5 cursor-pointer select-none rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-3 py-2.5 hover:border-emerald-300">
                    <input type="checkbox" name="show_on_sale" value="1" checked class="w-4 h-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                    <span class="flex-1">
                        <span class="block text-xs font-bold text-gray-700 dark:text-gray-300">Sale screen par dikhayein</span>
                        <span class="block text-[11px] text-gray-500 dark:text-gray-400">Off karein to yeh product billing / sale screen par nahi aayega (catalog mein rahega).</span>
                    </span>
                </label>
            </div>
            {{-- ── SECTION: Inventory & Unit ── --}}
            <div class="rounded-xl border border-gray-200 dark:border-gray-800 bg-gray-50/60 dark:bg-gray-800/20 p-4">
                <div class="flex items-center gap-2 mb-3">
                    <svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    <span class="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Inventory &amp; Unit</span>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
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
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Opening Stock <span class="text-gray-400">(blank = not tracked)</span></label>
                        <input type="number" name="stock_quantity" step="1" min="0" placeholder="e.g. 50" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-emerald-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Low-Stock Alert At</label>
                        <input type="number" name="low_stock_threshold" step="1" min="0" value="10" placeholder="10" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-amber-500">
                    </div>
                </div>
            </div>

            {{-- ── SECTION: Product Image ── --}}
            <div class="rounded-xl border border-gray-200 dark:border-gray-800 bg-gray-50/60 dark:bg-gray-800/20 p-4">
            <div x-data="{ mode: 'none' }">
                <label class="flex items-center justify-between text-xs font-semibold text-gray-700 dark:text-gray-300 mb-2">
                    <span class="flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        Product Image
                    </span>
                    <span class="text-[9px] font-bold uppercase tracking-wider text-gray-400 bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5 rounded">Optional</span>
                </label>
                <div class="flex flex-wrap gap-1.5 mb-2">
                    <label class="flex items-center gap-1.5 cursor-pointer text-[11px] font-semibold px-3 py-1.5 rounded-xl border-2 transition-all peer-focus-visible:ring-2 peer-focus-visible:ring-offset-2 peer-focus-visible:ring-purple-500 dark:peer-focus-visible:ring-offset-gray-900" :class="mode === 'none' ? 'bg-gradient-to-br from-gray-100 to-gray-200 dark:from-gray-700 dark:to-gray-800 border-gray-400 dark:border-gray-500 text-gray-800 dark:text-gray-100 shadow-sm scale-105' : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-500 hover:border-gray-300'">
                        <input type="radio" name="image_mode" value="none" x-model="mode" class="sr-only peer">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                        No Image
                    </label>
                    <label class="flex items-center gap-1.5 cursor-pointer text-[11px] font-semibold px-3 py-1.5 rounded-xl border-2 transition-all peer-focus-visible:ring-2 peer-focus-visible:ring-offset-2 peer-focus-visible:ring-purple-500 dark:peer-focus-visible:ring-offset-gray-900" :class="mode === 'upload' ? 'bg-purple-100 dark:bg-purple-900/40 border-purple-400 text-purple-700 dark:text-purple-300 shadow-sm scale-105' : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-500 hover:border-purple-300'">
                        <input type="radio" name="image_mode" value="upload" x-model="mode" class="sr-only peer">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                        Upload
                    </label>
                    <label class="flex items-center gap-1.5 cursor-pointer text-[11px] font-semibold px-3 py-1.5 rounded-xl border-2 transition-all peer-focus-visible:ring-2 peer-focus-visible:ring-offset-2 peer-focus-visible:ring-purple-500 dark:peer-focus-visible:ring-offset-gray-900" :class="mode === 'auto' ? 'bg-gradient-to-br from-amber-100 to-orange-200 dark:from-amber-900/40 dark:to-orange-800/30 border-amber-400 text-amber-700 dark:text-amber-300 shadow-sm shadow-amber-200 scale-105' : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-500 hover:border-amber-300'">
                        <input type="radio" name="image_mode" value="auto" x-model="mode" class="sr-only peer">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        Auto-fetch
                    </label>
                </div>
                <input type="file" name="image" accept="image/jpeg,image/jpg,image/png,image/webp"
                    x-show="mode === 'upload'" x-transition.opacity
                    class="w-full text-xs text-gray-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100 dark:file:bg-purple-900/30 dark:file:text-purple-300 mt-1">
                <div x-show="mode === 'none'" x-transition.opacity class="text-[10px] text-gray-500 dark:text-gray-400 italic flex items-start gap-1.5 mt-1 px-1">
                    <svg class="w-3 h-3 mt-0.5 flex-shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    List mein sirf product name + 2-letter chip show ho ga &mdash; koi picture nahi.
                </div>
                <div x-show="mode === 'auto'" x-transition.opacity class="text-[10px] text-amber-600 dark:text-amber-400 italic flex items-start gap-1.5 mt-1 px-1">
                    <svg class="w-3 h-3 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                    System khud product ke naam ki picture internet se laye ga.
                </div>
            </div>
            </div>
            @if(count($categoryFields) > 0)
            {{-- ── SECTION: Category-specific Fields ── --}}
            <div class="rounded-xl border border-gray-200 dark:border-gray-800 bg-gray-50/60 dark:bg-gray-800/20 p-4">
                <div class="flex items-center gap-2 mb-3">
                    <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                    <span class="text-xs font-bold uppercase tracking-wider text-purple-600 dark:text-purple-400">{{ ucfirst($posType) }} Fields</span>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    @include('pos.partials.category-fields', ['categoryFields' => $categoryFields, 'product' => null])
                </div>
            </div>
            @endif

            {{-- ═══════════════════════════════════════════════════════════════════
                 Unified Recipe / Ingredients (Single-Box) — optional, collapsible.
                 Vendor request: "product add karte waqt sath hi uski recipe add ho".
                 Backend: PosController::storeProduct accepts ingredients[] array;
                 each row may pick existing ingredient OR create new inline.
                 ═══════════════════════════════════════════════════════════════════ --}}
            <div class="col-span-full border-t-2 border-dashed border-purple-200 dark:border-purple-800 pt-4 mt-2"
                 x-data="{
                    open: false,
                    rows: [],
                    copySrc: '',
                    ings: @js(($ingredients ?? collect())->map(fn($i)=>['id'=>$i->id,'name'=>$i->name,'unit'=>$i->unit])->values()),
                    existingRecipes: @js($existingRecipes ?? []),
                    add() { this.rows.push({ mode:'existing', ingredient_id:'', new_name:'', new_unit:'KGS', new_cost:'', quantity_needed:'' }); },
                    toggle() { this.open = !this.open; if (this.open && this.rows.length === 0) this.add(); },
                    copyFromRecipe() {
                        const pid = parseInt(this.copySrc);
                        if (!pid) return;
                        const src = this.existingRecipes.find(r => r.product_id === pid);
                        if (!src) return;
                        // Drop any blank starter row, then append all ingredients from the source recipe
                        this.rows = this.rows.filter(r => r.ingredient_id || r.new_name || (parseFloat(r.quantity_needed) > 0));
                        src.ingredients.forEach(ing => {
                            this.rows.push({
                                mode: 'existing',
                                ingredient_id: String(ing.ingredient_id),
                                new_name: '',
                                new_unit: 'KGS',
                                new_cost: '',
                                quantity_needed: ing.quantity_needed
                            });
                        });
                        this.copySrc = '';
                    }
                 }">
                <button type="button" @click="toggle()"
                        class="w-full flex items-center justify-between px-4 py-2.5 rounded-lg border-2 transition-all"
                        :class="open ? 'bg-purple-100 dark:bg-purple-900/40 border-purple-400 dark:border-purple-600 text-purple-800 dark:text-purple-200' : 'bg-purple-50 dark:bg-purple-900/10 border-purple-200 dark:border-purple-800 text-purple-700 dark:text-purple-300 hover:bg-purple-100 dark:hover:bg-purple-900/20'">
                    <span class="flex items-center gap-2 text-sm font-bold">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                        Recipe / Ingredients
                        <span class="text-[10px] font-normal uppercase tracking-wider bg-purple-200/60 dark:bg-purple-700/40 px-1.5 py-0.5 rounded">Optional — for restaurants / manufacturing</span>
                        <span x-show="rows.length > 0 && open" class="text-[10px] font-bold bg-emerald-500 text-white px-2 py-0.5 rounded-full" x-text="rows.length + ' row' + (rows.length===1?'':'s')"></span>
                    </span>
                    <svg class="w-4 h-4 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>

                {{-- SINGLE SOURCE OF TRUTH: serialize rows to hidden JSON on every change.
                     Controller parses ingredients_json first; this completely bypasses Alpine
                     <template x-if/x-for> form-submission edge cases that caused recipes to
                     silently drop on save. Inputs below are UI-only (no name attribute). --}}
                <input type="hidden" name="ingredients_json" :value="JSON.stringify(rows)">

                <div x-show="open" x-transition class="mt-3 space-y-2" x-cloak>
                    <div class="text-[11px] text-gray-600 dark:text-gray-400 bg-purple-50/50 dark:bg-purple-900/10 border border-purple-200 dark:border-purple-800 rounded-lg px-3 py-2">
                        <strong class="text-purple-700 dark:text-purple-300">Tip:</strong> "Qty Needed" = ek product banane ke liye kitna ingredient lagta hai (e.g. Burger ke liye 0.2 kg meat). Naya ingredient ho to <strong>"New"</strong> select kar ke usi waqt naam, unit, cost dijiye — alag se Ingredients page jane ki zarurat nahi. Ya niche se kisi <strong>existing product ki recipe copy</strong> bhi kar sakte hain.
                    </div>

                    {{-- Copy from existing recipe (product name aur ingredient name alag ho sakte hain — yeh sirf rows pre-fill karta hai) --}}
                    <div x-show="existingRecipes.length > 0"
                         class="flex flex-wrap items-center gap-2 p-2.5 rounded-lg bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800">
                        <span class="text-[11px] font-bold uppercase tracking-wider text-indigo-700 dark:text-indigo-300 flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                            Copy from existing recipe:
                        </span>
                        <select x-model="copySrc" @change="copyFromRecipe()"
                                class="flex-1 min-w-[180px] text-xs rounded-md border border-indigo-300 dark:border-indigo-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-2 py-1.5 focus:ring-2 focus:ring-indigo-500">
                            <option value="">— pick a product to copy its ingredients —</option>
                            <template x-for="r in existingRecipes" :key="r.product_id">
                                <option :value="r.product_id" x-text="r.product_name + '  (' + r.ingredients.length + ' ingredient' + (r.ingredients.length===1?'':'s') + ')'"></option>
                            </template>
                        </select>
                        <span class="text-[10px] text-indigo-600 dark:text-indigo-400 italic">Append hoga — phir edit kar sakte ho</span>
                    </div>

                    <template x-for="(row, idx) in rows" :key="idx">
                        <div class="grid grid-cols-12 gap-2 items-end p-2.5 rounded-lg bg-white dark:bg-gray-800 border border-purple-200 dark:border-purple-800 shadow-sm">
                            {{-- Mode toggle: existing vs new --}}
                            <div class="col-span-12 sm:col-span-2">
                                <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-600 dark:text-gray-400 mb-1">Type</label>
                                <select x-model="row.mode" class="w-full text-xs rounded-md border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-2 py-1.5 focus:ring-2 focus:ring-purple-500">
                                    <option value="existing">Existing</option>
                                    <option value="new">+ New</option>
                                </select>
                            </div>

                            {{-- Existing ingredient picker --}}
                            <div class="col-span-12 sm:col-span-5" x-show="row.mode === 'existing'">
                                <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-600 dark:text-gray-400 mb-1">Pick Ingredient</label>
                                <select x-model="row.ingredient_id"
                                        class="w-full text-xs rounded-md border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-2 py-1.5 focus:ring-2 focus:ring-purple-500">
                                    <option value="">— select —</option>
                                    <template x-for="ing in ings" :key="ing.id">
                                        <option :value="ing.id" x-text="ing.name + ' (' + ing.unit + ')'"></option>
                                    </template>
                                </select>
                                <p x-show="ings.length === 0" class="text-[10px] text-amber-600 dark:text-amber-400 mt-1">Koi ingredient nahi — "+ New" choose karke create karein.</p>
                            </div>

                            {{-- New ingredient inline --}}
                            <div class="col-span-12 sm:col-span-5 grid grid-cols-3 gap-1.5" x-show="row.mode === 'new'">
                                <div>
                                    <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-600 dark:text-gray-400 mb-1">New Name *</label>
                                    <input type="text" x-model="row.new_name" placeholder="e.g. Chicken Breast" class="w-full text-xs rounded-md border border-emerald-300 dark:border-emerald-700 bg-emerald-50/30 dark:bg-emerald-900/10 text-gray-900 dark:text-white px-2 py-1.5 focus:ring-2 focus:ring-emerald-500">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-600 dark:text-gray-400 mb-1">Unit *</label>
                                    <select x-model="row.new_unit" class="w-full text-xs rounded-md border border-emerald-300 dark:border-emerald-700 bg-emerald-50/30 dark:bg-emerald-900/10 text-gray-900 dark:text-white px-1.5 py-1.5">
                                        <option value="KGS">KGS</option>
                                        <option value="GMS">GMS</option>
                                        <option value="LTR">LTR</option>
                                        <option value="ML">ML</option>
                                        <option value="PCS">PCS</option>
                                        <option value="DOZ">DOZ</option>
                                        <option value="PKT">PKT</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-600 dark:text-gray-400 mb-1">Cost/Unit</label>
                                    <input type="number" x-model="row.new_cost" step="0.01" min="0" placeholder="0" class="w-full text-xs rounded-md border border-emerald-300 dark:border-emerald-700 bg-emerald-50/30 dark:bg-emerald-900/10 text-gray-900 dark:text-white px-2 py-1.5 focus:ring-2 focus:ring-emerald-500">
                                </div>
                            </div>

                            {{-- Quantity needed per 1 product --}}
                            <div class="col-span-8 sm:col-span-4">
                                <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-600 dark:text-gray-400 mb-1">Qty Needed (per 1 product) *</label>
                                <input type="number" x-model="row.quantity_needed" step="0.0001" min="0" placeholder="e.g. 0.2"
                                       class="w-full text-xs rounded-md border border-purple-300 dark:border-purple-700 bg-purple-50/30 dark:bg-purple-900/10 text-gray-900 dark:text-white px-2 py-1.5 focus:ring-2 focus:ring-purple-500">
                            </div>

                            {{-- Remove row --}}
                            <div class="col-span-4 sm:col-span-1 flex items-end justify-end">
                                <button type="button" @click="rows.splice(idx, 1)" title="Remove row"
                                        class="w-full text-xs font-bold text-red-600 hover:text-white hover:bg-red-600 border border-red-300 dark:border-red-700 rounded-md py-1.5 transition">✕</button>
                            </div>
                        </div>
                    </template>

                    <button type="button" @click="add()"
                            class="w-full text-xs font-bold uppercase tracking-wider text-purple-700 dark:text-purple-300 bg-purple-100 dark:bg-purple-900/30 hover:bg-purple-200 dark:hover:bg-purple-900/50 border-2 border-dashed border-purple-300 dark:border-purple-700 rounded-lg py-2 transition">
                        + Add Another Ingredient
                    </button>

                    {{-- Live preview of what will be saved (debug-friendly for cashier) --}}
                    <div x-show="rows.length > 0" class="text-[11px] text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-lg px-3 py-2 font-mono">
                        <span class="font-bold">Save par:</span>
                        <span x-text="rows.filter(r => r.quantity_needed > 0 && (r.ingredient_id || (r.new_name && r.new_unit))).length"></span> ingredient(s) link honge.
                    </div>
                </div>
            </div>

            <div class="flex items-end col-span-full">
                <button type="submit" class="w-full bg-gradient-to-r from-purple-500 to-purple-700 text-white px-5 py-2.5 rounded-lg text-sm font-bold shadow-md hover:shadow-lg transition">Save Product (+ Recipe if added)</button>
            </div>
        </form>
    </div>

    @php
        $catFieldNames = array_values($categoryFields ?? []);
        $boolCatFields = ['prescription_required', 'weight_based', 'custom_order'];
        $productsJson = $products->map(function ($p) use ($catFieldNames, $boolCatFields) {
            $row = [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'price' => (float) $p->price,
                'cost_price' => (float) ($p->cost_price ?? 0),
                'tax_rate' => (float) $p->tax_rate,
                'is_tax_exempt' => (bool) $p->is_tax_exempt,
                'category' => $p->category,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'uom' => $p->uom ?? 'NOS',
                'is_active' => (bool) $p->is_active,
                'show_on_sale' => (bool) ($p->show_on_sale ?? true),
                'stock_quantity' => $p->stock_quantity,
                'low_stock_threshold' => $p->low_stock_threshold ?? 10,
                'image' => $p->image,
                'image_url' => $p->image ? asset('storage/products/' . $p->image) : null,
            ];
            foreach ($catFieldNames as $cf) {
                $val = $p->$cf;
                if ($val instanceof \Carbon\Carbon) $val = $val->format('Y-m-d');
                if (in_array($cf, $boolCatFields)) $val = (bool) $val;
                $row[$cf] = $val;
            }
            return $row;
        })->values();
    @endphp

    <div x-data="productCatalog()" x-cloak>
        <style>[x-cloak]{display:none!important;}</style>

        {{-- ═══ STATS BAR ═══ --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-5">
            <div class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-3.5 shadow-sm">
                <div class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Total Products</div>
                <div class="text-2xl font-extrabold text-gray-900 dark:text-white mt-0.5" x-text="stats.total"></div>
            </div>
            <div class="rounded-xl border border-emerald-200 dark:border-emerald-800 bg-emerald-50/50 dark:bg-emerald-900/10 p-3.5 shadow-sm">
                <div class="text-[10px] font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Active</div>
                <div class="text-2xl font-extrabold text-emerald-700 dark:text-emerald-300 mt-0.5" x-text="stats.active"></div>
            </div>
            <div class="rounded-xl border p-3.5 shadow-sm transition-colors"
                 :class="stats.lowStock > 0 ? 'border-red-300 dark:border-red-700 bg-red-50 dark:bg-red-900/20' : 'border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900'">
                <div class="text-[10px] font-bold uppercase tracking-wider" :class="stats.lowStock > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-400'">Low Stock</div>
                <div class="text-2xl font-extrabold mt-0.5" :class="stats.lowStock > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-white'" x-text="stats.lowStock"></div>
            </div>
            <div class="rounded-xl border border-purple-200 dark:border-purple-800 bg-purple-50/50 dark:bg-purple-900/10 p-3.5 shadow-sm">
                <div class="text-[10px] font-bold uppercase tracking-wider text-purple-600 dark:text-purple-400">Stock Value</div>
                <div class="text-lg font-extrabold text-purple-700 dark:text-purple-300 mt-1.5" x-text="'Rs ' + fmt(stats.stockValue)"></div>
            </div>
            <div class="rounded-xl border border-blue-200 dark:border-blue-800 bg-blue-50/50 dark:bg-blue-900/10 p-3.5 shadow-sm col-span-2 sm:col-span-1">
                <div class="text-[10px] font-bold uppercase tracking-wider text-blue-600 dark:text-blue-400">Avg Margin</div>
                <div class="text-2xl font-extrabold text-blue-700 dark:text-blue-300 mt-0.5" x-text="stats.avgMargin !== null ? stats.avgMargin + '%' : '—'"></div>
            </div>
        </div>

        {{-- ═══ TOOLBAR ═══ --}}
        <div class="flex flex-col lg:flex-row lg:items-center gap-2 mb-3">
            <div class="relative flex-1">
                <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="text" x-model="search" placeholder="Search name, SKU, barcode, category…"
                       class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white pl-9 pr-3 py-2 focus:ring-2 focus:ring-purple-500">
            </div>
            <select x-model="catFilter" class="text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
                <option value="">All Categories</option>
                <template x-for="c in categories" :key="c"><option :value="c" x-text="c"></option></template>
            </select>
            <select x-model="statusFilter" class="text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
                <option value="">All Status</option>
                <option value="active">Active only</option>
                <option value="inactive">Inactive only</option>
                <option value="low">Low stock</option>
            </select>
            <select x-model="sortKey" class="text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
                <option value="name">Name</option>
                <option value="price">Price</option>
                <option value="stock_quantity">Stock</option>
                <option value="margin">Margin</option>
                <option value="category">Category</option>
            </select>
            <button @click="sortDir = (sortDir === 'asc' ? 'desc' : 'asc')" :title="sortDir === 'asc' ? 'Ascending' : 'Descending'"
                    class="px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                <span x-text="sortDir === 'asc' ? '↑' : '↓'" class="font-bold"></span>
            </button>
            <div class="flex rounded-lg border border-gray-300 dark:border-gray-700 overflow-hidden">
                <button @click="view = 'table'" :class="view === 'table' ? 'bg-purple-600 text-white' : 'bg-white dark:bg-gray-800 text-gray-500'" class="px-3 py-2" title="Table view">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <button @click="view = 'grid'" :class="view === 'grid' ? 'bg-purple-600 text-white' : 'bg-white dark:bg-gray-800 text-gray-500'" class="px-3 py-2" title="Grid view">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM14 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zM14 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z"/></svg>
                </button>
            </div>
            <a :href="labelsUrl()" target="_blank"
               class="inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 text-sm font-semibold hover:bg-gray-50 dark:hover:bg-gray-700 whitespace-nowrap">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                <span x-text="selected.length > 0 ? 'Print ' + selected.length : 'Print Labels'"></span>
            </a>
        </div>

        @if(auth('pos')->user() && !auth('pos')->user()->isPosCashier() && auth('pos')->user()->isPosAdmin())
        {{-- ═══ SALE-SCREEN VISIBILITY (bulk hide/show — admin/manager only) ═══ --}}
        <div class="flex flex-wrap items-center gap-2 mb-3 p-3 rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm">
            <span class="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Sale Screen Visibility</span>
            <select x-model="bulkSaleCat" class="text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-1.5 focus:ring-2 focus:ring-purple-500">
                <option value="">Sab Categories</option>
                <template x-for="c in categories" :key="'bs'+c"><option :value="c" x-text="c"></option></template>
            </select>
            <button @click="doBulkSale('hide')" class="px-3 py-2 rounded-lg bg-gray-700 hover:bg-gray-800 text-white text-xs font-semibold">Sab Hide Karo</button>
            <button @click="doBulkSale('show')" class="px-3 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold">Sab Show Karo</button>
            <span class="text-[11px] text-gray-400">Hidden products grid se hat jate hain magar search se milte rehte hain aur bill ho sakte hain.</span>
        </div>
        @endif

        {{-- ═══ BULK ACTION BAR ═══ --}}
        <div x-show="selected.length > 0" x-transition
             class="flex flex-wrap items-center gap-2 mb-3 p-3 rounded-xl bg-purple-600 text-white shadow-lg">
            <span class="font-bold text-sm" x-text="selected.length + ' selected'"></span>
            <div class="flex-1"></div>
            <button @click="doBulk('activate')" class="px-3 py-1.5 rounded-lg bg-white/15 hover:bg-white/25 text-xs font-semibold">Activate</button>
            <button @click="doBulk('deactivate')" class="px-3 py-1.5 rounded-lg bg-white/15 hover:bg-white/25 text-xs font-semibold">Deactivate</button>
            <button @click="doBulk('category')" class="px-3 py-1.5 rounded-lg bg-white/15 hover:bg-white/25 text-xs font-semibold">Set Category</button>
            <button @click="doBulk('price')" class="px-3 py-1.5 rounded-lg bg-white/15 hover:bg-white/25 text-xs font-semibold">Set Price</button>
            <button @click="doBulk('price_percent')" class="px-3 py-1.5 rounded-lg bg-white/15 hover:bg-white/25 text-xs font-semibold">Price % +/-</button>
            <button @click="doBulk('exempt_on')" class="px-3 py-1.5 rounded-lg bg-white/15 hover:bg-white/25 text-xs font-semibold">Tax Exempt ON</button>
            <button @click="doBulk('exempt_off')" class="px-3 py-1.5 rounded-lg bg-white/15 hover:bg-white/25 text-xs font-semibold">Tax Exempt OFF</button>
            <button @click="doBulk('delete')" class="px-3 py-1.5 rounded-lg bg-red-500 hover:bg-red-600 text-xs font-semibold">Delete</button>
            <button @click="clearSelect()" class="px-3 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-xs font-semibold">Clear</button>
        </div>

        {{-- ═══ TABLE VIEW ═══ --}}
        <div x-show="view === 'table'" class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-md overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
                            <th class="px-3 py-3 w-8"><input type="checkbox" @change="toggleAll($event)" :checked="allVisibleSelected" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500"></th>
                            <th class="px-4 py-3">Product</th>
                            <th class="px-4 py-3 hidden md:table-cell">Category</th>
                            <th class="px-4 py-3 hidden lg:table-cell">SKU</th>
                            <th class="px-4 py-3 text-center">Stock</th>
                            <th class="px-4 py-3 text-right">Price</th>
                            <th class="px-4 py-3 text-right hidden sm:table-cell">Margin</th>
                            <th class="px-4 py-3 text-right hidden sm:table-cell">Tax %</th>
                            <th class="px-4 py-3 text-center hidden sm:table-cell">Status</th>
                            <th class="px-4 py-3 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <template x-for="p in filtered" :key="p.id">
                            <tr :class="!p.is_active ? 'opacity-50' : ''">
                                <td class="px-3 py-3"><input type="checkbox" :value="p.id" x-model.number="selected" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500"></td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <template x-if="p.image_url">
                                            <img :src="p.image_url" :alt="p.name" class="w-10 h-10 rounded-lg object-cover bg-gray-100 dark:bg-gray-700 flex-shrink-0 border border-gray-200 dark:border-gray-700 shadow-sm" onerror="this.style.display='none'">
                                        </template>
                                        <template x-if="!p.image_url">
                                            <div class="w-10 h-10 rounded-lg flex-shrink-0 flex items-center justify-center text-[12px] font-extrabold border tracking-tight" :style="chipStyle(p.name)" x-text="initials(p.name)"></div>
                                        </template>
                                        <div class="min-w-0">
                                            <span class="font-medium text-gray-900 dark:text-white" x-text="p.name"></span>
                                            <span x-show="p.is_tax_exempt" class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400 ml-1">EXEMPT</span>
                                            <span x-show="!p.show_on_sale" class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300 ml-1">HIDDEN</span>
                                            <div x-show="p.description" class="text-[10px] text-gray-400 truncate max-w-[180px]" x-text="p.description"></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-gray-500 hidden md:table-cell" x-text="p.category || '—'"></td>
                                <td class="px-4 py-3 text-gray-500 text-xs hidden lg:table-cell" x-text="p.sku || '—'"></td>
                                <td class="px-4 py-3 text-center">
                                    <template x-if="p.stock_quantity === null">
                                        <span class="text-gray-300 dark:text-gray-600">—</span>
                                    </template>
                                    <template x-if="p.stock_quantity !== null">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold"
                                              :class="isLow(p) ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300'">
                                            <span x-text="p.stock_quantity"></span>
                                            <svg x-show="isLow(p)" class="w-3 h-3 ml-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                        </span>
                                    </template>
                                </td>
                                <td class="px-4 py-3 text-right font-medium text-gray-900 dark:text-white" x-text="'PKR ' + fmt(p.price)"></td>
                                <td class="px-4 py-3 text-right hidden sm:table-cell">
                                    <template x-if="marginPct(p) !== null">
                                        <span class="font-semibold" :class="marginPct(p) >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'" x-text="marginPct(p) + '%'"></span>
                                    </template>
                                    <template x-if="marginPct(p) === null"><span class="text-gray-300 dark:text-gray-600">—</span></template>
                                </td>
                                <td class="px-4 py-3 text-right text-gray-500 hidden sm:table-cell" x-text="p.tax_rate + '%'"></td>
                                <td class="px-4 py-3 text-center hidden sm:table-cell">
                                    <button @click="toggleStatus(p)" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                                            :class="p.is_active ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400'"
                                            x-text="p.is_active ? 'Active' : 'Inactive'"></button>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex items-center justify-center gap-1">
                                        <button @click="toggleSale(p)" :title="p.show_on_sale ? 'Showing on sale screen — click to hide' : 'Hidden from sale screen — click to show'" class="text-xs px-2 py-1 font-semibold rounded" :class="p.show_on_sale ? 'text-emerald-600 hover:text-emerald-700' : 'text-gray-400 hover:text-gray-600'" x-text="p.show_on_sale ? 'On Sale' : 'Hidden'"></button>
                                        <button @click="openEdit(p)" class="text-xs text-purple-600 hover:text-purple-700 px-2 py-1 font-semibold">Edit</button>
                                        <button @click="deleteOne(p)" class="text-xs text-red-500 hover:text-red-600 px-2 py-1">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="filtered.length === 0">
                            <td colspan="10" class="px-4 py-12 text-center text-gray-500" x-text="products.length === 0 ? 'No products yet. Click \'+ Add Product\' to create your first POS product.' : 'No products match your search/filters.'"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ═══ GRID VIEW ═══ --}}
        <div x-show="view === 'grid'" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
            <template x-for="p in filtered" :key="'g'+p.id">
                <div class="relative rounded-xl border bg-white dark:bg-gray-900 shadow-sm overflow-hidden transition hover:shadow-md"
                     :class="[!p.is_active ? 'opacity-60' : '', selected.includes(p.id) ? 'border-purple-500 ring-2 ring-purple-300' : 'border-gray-200 dark:border-gray-800']">
                    <div class="absolute top-2 left-2 z-10">
                        <input type="checkbox" :value="p.id" x-model.number="selected" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 bg-white/90 shadow">
                    </div>
                    <span x-show="isLow(p)" class="absolute top-2 right-2 z-10 inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-red-500 text-white shadow">LOW</span>
                    <div class="aspect-square bg-gray-50 dark:bg-gray-800 flex items-center justify-center">
                        <template x-if="p.image_url"><img :src="p.image_url" :alt="p.name" class="w-full h-full object-cover" onerror="this.style.display='none'"></template>
                        <template x-if="!p.image_url"><div class="w-16 h-16 rounded-xl flex items-center justify-center text-xl font-extrabold border" :style="chipStyle(p.name)" x-text="initials(p.name)"></div></template>
                    </div>
                    <div class="p-3">
                        <div class="font-semibold text-sm text-gray-900 dark:text-white truncate" x-text="p.name"></div>
                        <div class="text-[11px] text-gray-400 truncate" x-text="p.category || '—'"></div>
                        <div class="flex items-center justify-between mt-1.5">
                            <span class="font-extrabold text-gray-900 dark:text-white" x-text="'Rs ' + fmt(p.price)"></span>
                            <template x-if="p.stock_quantity !== null">
                                <span class="text-xs font-bold" :class="isLow(p) ? 'text-red-600' : 'text-gray-500'" x-text="'Stk ' + p.stock_quantity"></span>
                            </template>
                        </div>
                        <div class="flex items-center gap-1 mt-2">
                            <button @click="openEdit(p)" class="flex-1 text-xs font-semibold text-purple-700 dark:text-purple-300 bg-purple-50 dark:bg-purple-900/30 hover:bg-purple-100 rounded-md py-1">Edit</button>
                            <button @click="toggleSale(p)" :title="p.show_on_sale ? 'On sale screen — click to hide' : 'Hidden — click to show'" class="text-xs px-2 py-1 rounded-md" :class="p.show_on_sale ? 'text-emerald-600 bg-emerald-50 dark:bg-emerald-900/20' : 'text-gray-400 bg-gray-100 dark:bg-gray-800'" x-text="p.show_on_sale ? 'Sale' : 'Hide'"></button>
                            <button @click="deleteOne(p)" class="text-xs px-2 py-1 rounded-md text-red-500 bg-red-50 dark:bg-red-900/20">✕</button>
                        </div>
                    </div>
                </div>
            </template>
            <div x-show="filtered.length === 0" class="col-span-full px-4 py-12 text-center text-gray-500" x-text="products.length === 0 ? 'No products yet.' : 'No products match your search/filters.'"></div>
        </div>

        {{-- ═══ EDIT MODAL ═══ --}}
        <div x-show="editing" x-transition.opacity class="fixed inset-0 z-50 flex items-start justify-center p-4 overflow-y-auto bg-black/50" @click.self="editing = null" x-cloak>
            <div class="w-full max-w-2xl my-8 bg-white dark:bg-gray-900 rounded-2xl shadow-2xl" @keydown.escape.window="editing = null">
                <template x-if="editing">
                    <form :action="updateUrl(editing.id)" method="POST" enctype="multipart/form-data" class="p-5">
                        @csrf @method('PUT')
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-base font-bold text-gray-900 dark:text-white">Edit Product</h3>
                            <button type="button" @click="editing = null" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                            <div class="sm:col-span-2 lg:col-span-3">
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Product Name *</label>
                                <input type="text" name="name" x-model="editing.name" required class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Price (PKR) *</label>
                                <input type="number" name="price" x-model="editing.price" step="0.01" min="0" required class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Cost Price</label>
                                <input type="number" name="cost_price" x-model="editing.cost_price" step="0.01" min="0" class="w-full text-sm rounded-lg border border-emerald-200 dark:border-emerald-800 bg-emerald-50/50 dark:bg-emerald-900/10 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-emerald-500">
                            </div>
                            <div class="rounded-lg border-2 p-2.5 transition-all" :class="editing.is_tax_exempt ? 'border-amber-400 bg-amber-50 dark:bg-amber-900/20' : 'border-purple-200 dark:border-purple-800 bg-white dark:bg-gray-800'">
                                <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Tax Setup</label>
                                <template x-if="editing.is_tax_exempt"><input type="hidden" name="tax_rate" value="0"></template>
                                <input type="number" name="tax_rate" x-model="editing.tax_rate" step="0.01" min="0" max="100" :disabled="editing.is_tax_exempt" :placeholder="editing.is_tax_exempt ? '0 (exempt)' : 'Tax %'" class="w-full text-sm rounded border-0 bg-transparent text-gray-900 dark:text-white px-1 py-0 focus:ring-0 disabled:opacity-50">
                                <label class="flex items-center gap-1.5 mt-1 cursor-pointer select-none">
                                    <input type="checkbox" name="is_tax_exempt" value="1" x-model="editing.is_tax_exempt" class="w-3.5 h-3.5 rounded border-gray-300 text-amber-600 focus:ring-amber-500">
                                    <span class="text-[10px] font-bold uppercase tracking-wider" :class="editing.is_tax_exempt ? 'text-amber-700 dark:text-amber-300' : 'text-gray-500'">Tax Exempt</span>
                                </label>
                            </div>
                            <label class="rounded-lg border-2 p-2.5 flex items-center gap-2 cursor-pointer select-none transition-all" :class="editing.show_on_sale ? 'border-emerald-300 bg-emerald-50 dark:bg-emerald-900/20' : 'border-gray-300 bg-gray-50 dark:bg-gray-800'">
                                <input type="checkbox" name="show_on_sale" value="1" x-model="editing.show_on_sale" class="w-4 h-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                                <span class="text-[11px] font-bold uppercase tracking-wider" :class="editing.show_on_sale ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-500'" x-text="editing.show_on_sale ? 'On Sale Screen' : 'Hidden from Sale'"></span>
                            </label>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Stock <span class="text-gray-400">(blank=untracked)</span></label>
                                <input type="number" name="stock_quantity" x-model="editing.stock_quantity" step="1" min="0" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-emerald-500">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Low-Stock Alert At</label>
                                <input type="number" name="low_stock_threshold" x-model="editing.low_stock_threshold" step="1" min="0" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-amber-500">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Category</label>
                                <input type="text" name="category" x-model="editing.category" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">SKU</label>
                                <input type="text" name="sku" x-model="editing.sku" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Barcode</label>
                                <input type="text" name="barcode" x-model="editing.barcode" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Unit (UOM)</label>
                                <select name="uom" x-model="editing.uom" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
                                    @foreach(['NOS','KGS','LTR','MTR','PCS','PKT','BOX'] as $u)
                                    <option value="{{ $u }}">{{ $u }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="sm:col-span-2 lg:col-span-3">
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Description</label>
                                <input type="text" name="description" x-model="editing.description" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
                            </div>
                            {{-- Image mode --}}
                            <div class="sm:col-span-2 lg:col-span-3" x-data="{ emode: 'keep' }">
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Image</label>
                                <div class="flex flex-wrap gap-1.5 mb-1.5">
                                    <template x-for="m in ['keep','upload','auto','remove']" :key="m">
                                        <label class="cursor-pointer text-[11px] font-bold px-3 py-1.5 rounded-lg border-2 transition-all capitalize"
                                               :class="emode === m ? 'bg-purple-100 dark:bg-purple-900/40 border-purple-400 text-purple-700 dark:text-purple-300' : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-500'">
                                            <input type="radio" name="image_mode" :value="m" x-model="emode" class="sr-only">
                                            <span x-text="m === 'remove' ? 'No Image' : m"></span>
                                        </label>
                                    </template>
                                </div>
                                <input type="file" name="image" accept="image/jpeg,image/jpg,image/png,image/webp" x-show="emode === 'upload'" class="w-full text-xs text-gray-500 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-purple-50 file:text-purple-700">
                                <input type="hidden" name="remove_image" :value="emode === 'remove' ? '1' : '0'">
                            </div>
                            @if(count($categoryFields) > 0)
                            <div class="sm:col-span-2 lg:col-span-3 border-t border-gray-200 dark:border-gray-700 pt-3">
                                <p class="text-[10px] font-bold uppercase tracking-widest text-purple-500 mb-2">{{ ucfirst($posType) }} Fields</p>
                                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                    @foreach($categoryFields as $cf)
                                        @php
                                            $label = ucwords(str_replace('_', ' ', $cf));
                                            $isBool = in_array($cf, ['prescription_required','weight_based','custom_order']);
                                            $type = $cf === 'expiry_date' ? 'date' : (in_array($cf, ['warranty_months','service_duration','bulk_discount_qty','bulk_discount_pct']) ? 'number' : 'text');
                                        @endphp
                                        @if($isBool)
                                        <label class="flex items-center gap-2 text-xs text-gray-700 dark:text-gray-300 px-2 py-2 rounded-lg border border-gray-200 dark:border-gray-700">
                                            <input type="checkbox" name="{{ $cf }}" value="1" x-model="editing.{{ $cf }}" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                                            {{ $label }}
                                        </label>
                                        @else
                                        <div>
                                            <label class="block text-[10px] font-medium text-gray-500 mb-1">{{ $label }}</label>
                                            <input type="{{ $type }}" name="{{ $cf }}" x-model="editing.{{ $cf }}" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-2 py-1.5 focus:ring-2 focus:ring-purple-500">
                                        </div>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                            @endif
                        </div>
                        <div class="flex justify-end gap-2 mt-5">
                            <button type="button" @click="editing = null" class="px-4 py-2 rounded-lg text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800">Cancel</button>
                            <button type="submit" class="px-5 py-2 rounded-lg bg-purple-600 hover:bg-purple-700 text-white text-sm font-bold shadow">Save Changes</button>
                        </div>
                    </form>
                </template>
            </div>
        </div>
    </div>

    <script>
        function productCatalog() {
            return {
                products: @json($productsJson),
                search: '', catFilter: '', statusFilter: '', sortKey: 'name', sortDir: 'asc',
                view: 'table', selected: [], editing: null,
                csrf: '{{ csrf_token() }}',
                updateBase: '{{ route('pos.products.update', ['id' => '__ID__']) }}',
                toggleBase: '{{ route('pos.products.toggle', ['id' => '__ID__']) }}',
                saleBase: '{{ route('pos.products.toggle-sale', ['id' => '__ID__']) }}',
                bulkUrl: '{{ route('pos.products.bulk') }}',
                bulkSaleUrl: '{{ route('pos.products.bulk-sale') }}',
                bulkSaleCat: '',
                labelsBase: '{{ route('pos.products.labels') }}',

                updateUrl(id) { return this.updateBase.replace('__ID__', id); },
                toggleUrl(id) { return this.toggleBase.replace('__ID__', id); },
                saleUrl(id) { return this.saleBase.replace('__ID__', id); },
                labelsUrl() { return this.selected.length > 0 ? this.labelsBase + '?ids=' + this.selected.join(',') : this.labelsBase; },

                fmt(n) { return (Math.round((Number(n) || 0) * 100) / 100).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 }); },
                initials(name) { return (name || '').substring(0, 2).toUpperCase(); },
                hue(name) { let h = 0; for (let i = 0; i < (name || '').length; i++) { h = (h * 31 + name.charCodeAt(i)) >>> 0; } return h % 360; },
                chipStyle(name) {
                    const h = this.hue(name);
                    return `background: linear-gradient(135deg, hsl(${h},70%,92%), hsl(${(h + 30) % 360},70%,88%)); color: hsl(${h},80%,20%); border-color: hsl(${h},55%,75%);`;
                },
                isLow(p) { return p.stock_quantity !== null && Number(p.stock_quantity) <= Number(p.low_stock_threshold ?? 10); },
                marginPct(p) { const c = Number(p.cost_price) || 0; const pr = Number(p.price) || 0; if (c <= 0 || pr <= 0) return null; return Math.round(((pr - c) / pr) * 100); },

                get categories() {
                    return [...new Set(this.products.map(p => p.category).filter(Boolean))].sort();
                },
                get filtered() {
                    let list = this.products.slice();
                    const q = this.search.trim().toLowerCase();
                    if (q) {
                        list = list.filter(p =>
                            (p.name || '').toLowerCase().includes(q) ||
                            (p.sku || '').toLowerCase().includes(q) ||
                            (p.barcode || '').toLowerCase().includes(q) ||
                            (p.category || '').toLowerCase().includes(q));
                    }
                    if (this.catFilter) list = list.filter(p => p.category === this.catFilter);
                    if (this.statusFilter === 'active') list = list.filter(p => p.is_active);
                    else if (this.statusFilter === 'inactive') list = list.filter(p => !p.is_active);
                    else if (this.statusFilter === 'low') list = list.filter(p => this.isLow(p));
                    const dir = this.sortDir === 'asc' ? 1 : -1;
                    const key = this.sortKey;
                    list.sort((a, b) => {
                        let av, bv;
                        if (key === 'margin') { av = this.marginPct(a) ?? -Infinity; bv = this.marginPct(b) ?? -Infinity; }
                        else if (key === 'price') { av = Number(a.price) || 0; bv = Number(b.price) || 0; }
                        else if (key === 'stock_quantity') { av = a.stock_quantity ?? -Infinity; bv = b.stock_quantity ?? -Infinity; }
                        else { av = (a[key] || '').toString().toLowerCase(); bv = (b[key] || '').toString().toLowerCase(); }
                        if (av < bv) return -1 * dir;
                        if (av > bv) return 1 * dir;
                        return 0;
                    });
                    return list;
                },
                get stats() {
                    const total = this.products.length;
                    const active = this.products.filter(p => p.is_active).length;
                    const lowStock = this.products.filter(p => this.isLow(p)).length;
                    const stockValue = this.products.reduce((s, p) => s + (Number(p.price) || 0) * (Number(p.stock_quantity) || 0), 0);
                    const margins = this.products.map(p => this.marginPct(p)).filter(m => m !== null);
                    const avgMargin = margins.length ? Math.round(margins.reduce((a, b) => a + b, 0) / margins.length) : null;
                    return { total, active, lowStock, stockValue, avgMargin };
                },
                get allVisibleSelected() {
                    const ids = this.filtered.map(p => p.id);
                    return ids.length > 0 && ids.every(id => this.selected.includes(id));
                },
                toggleAll(e) {
                    const ids = this.filtered.map(p => p.id);
                    if (e.target.checked) { this.selected = [...new Set([...this.selected, ...ids])]; }
                    else { this.selected = this.selected.filter(id => !ids.includes(id)); }
                },
                clearSelect() { this.selected = []; },
                openEdit(p) { this.editing = JSON.parse(JSON.stringify(p)); },
                postForm(action, fields) {
                    const f = document.createElement('form');
                    f.method = 'POST'; f.action = action;
                    const add = (n, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; f.appendChild(i); };
                    add('_token', this.csrf);
                    for (const [k, v] of Object.entries(fields)) {
                        if (Array.isArray(v)) v.forEach(val => add(k + '[]', val));
                        else add(k, v);
                    }
                    document.body.appendChild(f); f.submit();
                },
                toggleStatus(p) { this.postForm(this.toggleUrl(p.id), {}); },
                toggleSale(p) { this.postForm(this.saleUrl(p.id), {}); },
                deleteOne(p) { if (confirm('Delete "' + p.name + '"?')) this.postForm(this.updateUrl(p.id), { _method: 'DELETE' }); },
                doBulk(action) {
                    if (this.selected.length === 0) return;
                    const fields = { action: action, ids: this.selected };
                    if (action === 'delete') { if (!confirm('Delete ' + this.selected.length + ' product(s)? This cannot be undone.')) return; }
                    if (action === 'category') {
                        const cat = prompt('Set category for ' + this.selected.length + ' product(s) (leave blank to clear):', '');
                        if (cat === null) return;
                        fields.category_value = cat;
                    }
                    if (action === 'price') {
                        const v = prompt('Nayi price (Rs) — ' + this.selected.length + ' selected product(s) par lagegi:', '');
                        if (v === null) return;
                        const num = parseFloat(String(v).replace(/[^0-9.]/g, ''));
                        if (isNaN(num) || num < 0) { alert('Sahi price likhein (sirf number, masalan 550).'); return; }
                        if (!confirm(this.selected.length + ' product(s) ki price Rs ' + num + ' set karni hai?')) return;
                        fields.price_value = num;
                    }
                    if (action === 'price_percent') {
                        const v = prompt('Kitne percent? (masalan 10 = +10% izafa, -5 = 5% kami):', '');
                        if (v === null) return;
                        const num = parseFloat(v);
                        if (isNaN(num) || num === 0 || num < -90 || num > 500) { alert('Sahi percent likhein — masalan 10 ya -5 (range -90 se 500).'); return; }
                        if (!confirm(this.selected.length + ' product(s) ki price ' + (num > 0 ? '+' : '') + num + '% change karni hai?')) return;
                        fields.percent_value = num;
                    }
                    if (action === 'exempt_on' && !confirm(this.selected.length + ' product(s) ko TAX EXEMPT karna hai? Bill par in par tax nahi lagega.')) return;
                    if (action === 'exempt_off' && !confirm(this.selected.length + ' product(s) par tax dobara ON karna hai?')) return;
                    this.postForm(this.bulkUrl, fields);
                },
                doBulkSale(action) {
                    const scope = this.bulkSaleCat ? '"' + this.bulkSaleCat + '" category ke products' : 'SAB products';
                    const q = action === 'hide'
                        ? 'Kya waqai ' + scope + ' sale screen se hide karne hain? (Search se phir bhi mil jayenge)'
                        : 'Kya waqai ' + scope + ' sale screen par show karne hain?';
                    if (!confirm(q)) return;
                    const fields = { action: action };
                    if (this.bulkSaleCat) fields.category = this.bulkSaleCat;
                    this.postForm(this.bulkSaleUrl, fields);
                },
            };
        }
    </script>

    <div class="mt-4 text-xs text-gray-400 text-center">
        These products are exclusive to NestPOS (PRA). Digital Invoice and FBR POS products are managed separately in their own systems.
    </div>
</div>
</x-pos-layout>
