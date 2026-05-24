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
                    <label class="flex items-center gap-1.5 cursor-pointer text-[11px] font-semibold px-3 py-1.5 rounded-xl border-2 transition-all peer-focus-visible:ring-2 peer-focus-visible:ring-offset-2 peer-focus-visible:ring-purple-500 dark:peer-focus-visible:ring-offset-gray-900" :class="mode === 'upload' ? 'bg-gradient-to-br from-purple-100 to-purple-200 dark:from-purple-900/40 dark:to-purple-800/30 border-purple-400 text-purple-700 dark:text-purple-300 shadow-sm shadow-purple-200 scale-105' : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-500 hover:border-purple-300'">
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
            @if(count($categoryFields) > 0)
            <div class="col-span-full border-t border-gray-200 dark:border-gray-700 pt-3 mt-1">
                <p class="text-xs font-bold uppercase tracking-widest text-purple-600 dark:text-purple-400 mb-3">{{ ucfirst($posType) }} Fields</p>
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
                    ings: @js(($ingredients ?? collect())->map(fn($i)=>['id'=>$i->id,'name'=>$i->name,'unit'=>$i->unit])->values()),
                    add() { this.rows.push({ mode:'existing', ingredient_id:'', new_name:'', new_unit:'KGS', new_cost:'', quantity_needed:'' }); },
                    toggle() { this.open = !this.open; if (this.open && this.rows.length === 0) this.add(); }
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

                <div x-show="open" x-transition class="mt-3 space-y-2" x-cloak>
                    <div class="text-[11px] text-gray-600 dark:text-gray-400 bg-purple-50/50 dark:bg-purple-900/10 border border-purple-200 dark:border-purple-800 rounded-lg px-3 py-2">
                        <strong class="text-purple-700 dark:text-purple-300">Tip:</strong> "Qty Needed" = ek product banane ke liye kitna ingredient lagta hai (e.g. Burger ke liye 0.2 kg meat). Naya ingredient ho to <strong>"New"</strong> select kar ke usi waqt naam, unit, cost dijiye — alag se Ingredients page jane ki zarurat nahi.
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

                            {{-- Existing ingredient picker (only renders when mode=existing → not submitted otherwise) --}}
                            <template x-if="row.mode === 'existing'">
                                <div class="col-span-12 sm:col-span-5">
                                    <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-600 dark:text-gray-400 mb-1">Pick Ingredient</label>
                                    <select :name="`ingredients[${idx}][ingredient_id]`" x-model="row.ingredient_id"
                                            class="w-full text-xs rounded-md border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-900 dark:text-white px-2 py-1.5 focus:ring-2 focus:ring-purple-500">
                                        <option value="">— select —</option>
                                        <template x-for="ing in ings" :key="ing.id">
                                            <option :value="ing.id" x-text="ing.name + ' (' + ing.unit + ')'"></option>
                                        </template>
                                    </select>
                                    <template x-if="ings.length === 0">
                                        <p class="text-[10px] text-amber-600 dark:text-amber-400 mt-1">Koi ingredient nahi — niche "+ New" choose karke create karein.</p>
                                    </template>
                                </div>
                            </template>

                            {{-- New ingredient inline (only renders when mode=new) --}}
                            <template x-if="row.mode === 'new'">
                                <div class="col-span-12 sm:col-span-5 grid grid-cols-3 gap-1.5">
                                    <div>
                                        <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-600 dark:text-gray-400 mb-1">New Name *</label>
                                        <input type="text" :name="`ingredients[${idx}][new_name]`" x-model="row.new_name" placeholder="e.g. Chicken Breast" class="w-full text-xs rounded-md border border-emerald-300 dark:border-emerald-700 bg-emerald-50/30 dark:bg-emerald-900/10 text-gray-900 dark:text-white px-2 py-1.5 focus:ring-2 focus:ring-emerald-500">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-600 dark:text-gray-400 mb-1">Unit *</label>
                                        <select :name="`ingredients[${idx}][new_unit]`" x-model="row.new_unit" class="w-full text-xs rounded-md border border-emerald-300 dark:border-emerald-700 bg-emerald-50/30 dark:bg-emerald-900/10 text-gray-900 dark:text-white px-1.5 py-1.5">
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
                                        <input type="number" :name="`ingredients[${idx}][new_cost]`" x-model="row.new_cost" step="0.01" min="0" placeholder="0" class="w-full text-xs rounded-md border border-emerald-300 dark:border-emerald-700 bg-emerald-50/30 dark:bg-emerald-900/10 text-gray-900 dark:text-white px-2 py-1.5 focus:ring-2 focus:ring-emerald-500">
                                    </div>
                                </div>
                            </template>

                            {{-- Quantity needed per 1 product --}}
                            <div class="col-span-8 sm:col-span-4">
                                <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-600 dark:text-gray-400 mb-1">Qty Needed (per 1 product) *</label>
                                <input type="number" :name="`ingredients[${idx}][quantity_needed]`" x-model="row.quantity_needed" step="0.0001" min="0" placeholder="e.g. 0.2"
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
                </div>
            </div>

            <div class="flex items-end col-span-full">
                <button type="submit" class="w-full bg-gradient-to-r from-purple-500 to-purple-700 text-white px-5 py-2.5 rounded-lg text-sm font-bold shadow-md hover:shadow-lg transition">Save Product (+ Recipe if added)</button>
            </div>
        </form>
    </div>

    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-md overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm table-cards">
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
                                    <img src="{{ asset('storage/products/' . $product->image) }}" alt="{{ $product->name }}" class="w-10 h-10 rounded-lg object-cover bg-gray-100 dark:bg-gray-700 flex-shrink-0 border border-gray-200 dark:border-gray-700 shadow-sm" onerror="this.style.display='none'">
                                @else
                                    {{-- name-only mode: deterministic-color initials chip (hash → unique HSL hue per product).
                                         Text uses fixed L=20% with high saturation → guaranteed ≥4.5:1 contrast (WCAG AA) on bg L=92%. --}}
                                    @php
                                        $hue = crc32($product->name) % 360;
                                        $bgStyle = "background: linear-gradient(135deg, hsl({$hue}, 70%, 92%) 0%, hsl(" . (($hue + 30) % 360) . ", 70%, 88%) 100%); color: hsl({$hue}, 80%, 20%); border-color: hsl({$hue}, 55%, 75%); box-shadow: 0 2px 6px -2px hsl({$hue}, 60%, 70%);";
                                    @endphp
                                    <div class="w-10 h-10 rounded-lg flex-shrink-0 flex items-center justify-center text-[12px] font-extrabold border tracking-tight" style="{{ $bgStyle }}">
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
                                @php
                                    $editHash = crc32($product->name);
                                    $editHue = $editHash % 360;
                                @endphp
                                <div x-data="{ emode: 'keep' }">
                                    <div class="flex items-center gap-2 mb-1.5">
                                        @if($product->image)
                                        <img src="{{ asset('storage/products/' . $product->image) }}" class="w-9 h-9 rounded-lg object-cover border-2 border-purple-200 dark:border-purple-800 shadow-sm" onerror="this.style.display='none'">
                                        @else
                                        <div class="w-9 h-9 rounded-lg flex items-center justify-center text-[10px] font-extrabold border-2 shadow-sm" style="background: hsl({{ $editHue }}, 65%, 92%); color: hsl({{ $editHue }}, 80%, 20%); border-color: hsl({{ $editHue }}, 55%, 75%);">{{ strtoupper(mb_substr($product->name, 0, 2)) }}</div>
                                        @endif
                                        <span class="text-[9px] uppercase tracking-wider font-bold text-gray-400">Image Mode</span>
                                    </div>
                                    <div class="flex flex-wrap gap-1 mb-1.5">
                                        <label class="cursor-pointer text-[10px] font-bold px-2 py-1 rounded-lg border-2 transition-all flex items-center gap-1 peer-focus-visible:ring-2 peer-focus-visible:ring-offset-1 peer-focus-visible:ring-purple-500" :class="emode === 'keep' ? 'bg-gradient-to-br from-gray-100 to-gray-200 dark:from-gray-700 dark:to-gray-800 border-gray-400 text-gray-800 dark:text-gray-100 shadow-sm scale-105' : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-500 hover:border-gray-400'">
                                            <input type="radio" name="image_mode" value="keep" x-model="emode" class="sr-only peer" checked>
                                            <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                            Keep
                                        </label>
                                        <label class="cursor-pointer text-[10px] font-bold px-2 py-1 rounded-lg border-2 transition-all flex items-center gap-1 peer-focus-visible:ring-2 peer-focus-visible:ring-offset-1 peer-focus-visible:ring-purple-500" :class="emode === 'upload' ? 'bg-gradient-to-br from-purple-100 to-purple-200 dark:from-purple-900/40 dark:to-purple-800/30 border-purple-400 text-purple-700 dark:text-purple-300 shadow-sm scale-105' : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-500 hover:border-purple-300'">
                                            <input type="radio" name="image_mode" value="upload" x-model="emode" class="sr-only peer">
                                            <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                                            Upload
                                        </label>
                                        <label class="cursor-pointer text-[10px] font-bold px-2 py-1 rounded-lg border-2 transition-all flex items-center gap-1 peer-focus-visible:ring-2 peer-focus-visible:ring-offset-1 peer-focus-visible:ring-purple-500" :class="emode === 'auto' ? 'bg-gradient-to-br from-amber-100 to-orange-200 dark:from-amber-900/40 dark:to-orange-800/30 border-amber-400 text-amber-700 dark:text-amber-300 shadow-sm scale-105' : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-500 hover:border-amber-300'">
                                            <input type="radio" name="image_mode" value="auto" x-model="emode" class="sr-only peer">
                                            <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                            Auto
                                        </label>
                                        <label class="cursor-pointer text-[10px] font-bold px-2 py-1 rounded-lg border-2 transition-all flex items-center gap-1 peer-focus-visible:ring-2 peer-focus-visible:ring-offset-1 peer-focus-visible:ring-purple-500" :class="emode === 'remove' ? 'bg-gradient-to-br from-red-100 to-rose-200 dark:from-red-900/40 dark:to-rose-800/30 border-red-400 text-red-700 dark:text-red-300 shadow-sm scale-105' : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-500 hover:border-red-300'">
                                            <input type="radio" name="image_mode" value="remove" x-model="emode" class="sr-only peer">
                                            <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22"/></svg>
                                            No Image
                                        </label>
                                    </div>
                                    <input type="file" name="image" accept="image/jpeg,image/jpg,image/png,image/webp"
                                        x-show="emode === 'upload'" x-transition.opacity
                                        class="w-full text-xs text-gray-500 file:mr-2 file:py-1 file:px-2 file:rounded-lg file:border-0 file:text-[10px] file:font-semibold file:bg-purple-50 file:text-purple-700">
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
