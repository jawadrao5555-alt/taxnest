<x-pos-layout>
@php
    $dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    $productsJson = $products->map(fn($p) => ['id' => $p->id, 'name' => $p->name, 'price' => (float) $p->price])->values();
@endphp
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6"
     x-data="{ products: {{ json_encode($productsJson, JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]' }} }">
    <a href="{{ route('pos.customize') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400 hover:text-purple-600 dark:hover:text-purple-400 transition mb-3">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        Back to Customize
    </a>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-2">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Deals</h1>
    </div>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">Combo deals at one promo price — pick the products, set the price, choose the days. Active deals appear automatically on the sale screen under the "Deals" tab.</p>

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 text-sm">{{ session('success') }}</div>
    @endif
    @if($errors->any())
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm">
        <ul class="list-disc pl-4">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
    @endif

    {{-- Add New Deal --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6"
         x-data="{ rows: [{ product_id: '', quantity: 1 }] }">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Add New Deal</h3>
        <form method="POST" action="{{ route('pos.deals.store') }}">
            @csrf
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Deal Name</label>
                    <input type="text" name="name" required maxlength="255" placeholder="e.g. Sunday Deal" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Deal Price (PKR)</label>
                    <input type="number" name="price" required step="0.01" min="0" placeholder="0.00" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Description <span class="text-gray-400">(optional)</span></label>
                    <input type="text" name="description" maxlength="255" placeholder="e.g. Burger + Fries + Drink" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
                </div>
            </div>

            <div class="mb-4">
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-2">Active Days <span class="text-gray-400">(none selected = every day)</span></label>
                <div class="flex flex-wrap gap-2">
                    @foreach($dayNames as $num => $label)
                    <label class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 text-xs font-semibold text-gray-700 dark:text-gray-300 cursor-pointer has-[:checked]:bg-purple-600 has-[:checked]:text-white has-[:checked]:border-purple-600 transition">
                        <input type="checkbox" name="active_days[]" value="{{ $num }}" class="sr-only">
                        {{ $label }}
                    </label>
                    @endforeach
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Start Date <span class="text-gray-400">(optional)</span></label>
                    <input type="date" name="starts_on" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">End Date <span class="text-gray-400">(optional)</span></label>
                    <input type="date" name="ends_on" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
                </div>
            </div>

            <div class="mb-4">
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-2">Deal Items (products included)</label>
                <template x-for="(row, idx) in rows" :key="idx">
                    <div class="flex items-center gap-2 mb-2">
                        <select :name="'items[' + idx + '][product_id]'" x-model="row.product_id" required class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
                            <option value="">Select product…</option>
                            <template x-for="p in products" :key="p.id">
                                <option :value="p.id" x-text="p.name + ' — Rs. ' + p.price" :selected="String(row.product_id) === String(p.id)"></option>
                            </template>
                        </select>
                        <input type="number" :name="'items[' + idx + '][quantity]'" x-model.number="row.quantity" required min="1" max="999" class="w-20 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500" title="Quantity">
                        <button type="button" @click="rows.splice(idx, 1)" x-show="rows.length > 1" class="p-2 rounded-lg text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition" title="Remove item">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                </template>
                <button type="button" @click="rows.push({ product_id: '', quantity: 1 })" class="mt-1 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Add Item
                </button>
            </div>

            <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-lg transition">Add Deal</button>
        </form>
    </div>

    {{-- Deals list --}}
    <div class="space-y-4">
        @forelse($deals as $deal)
        @php
            $dealDays = array_map('intval', (array) ($deal->active_days ?? []));
            $componentsText = $deal->items->map(fn($di) => $di->quantity . 'x ' . ($productNames[$di->pos_product_id] ?? 'Product #' . $di->pos_product_id))->implode(', ');
            $dealItemsJson = $deal->items->map(fn($di) => ['product_id' => $di->pos_product_id, 'quantity' => (int) $di->quantity])->values();
        @endphp
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5"
             x-data="{ editing: false, rows: {{ json_encode($dealItemsJson, JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]' }} }">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <h4 class="text-sm font-bold text-gray-900 dark:text-white">{{ $deal->name }}</h4>
                        @if($deal->is_active)
                            @if($deal->isActiveOn())
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400">LIVE TODAY</span>
                            @else
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400">Active</span>
                            @endif
                        @else
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">Inactive</span>
                        @endif
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 truncate">{{ $componentsText ?: 'No items' }}</p>
                    <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-0.5">
                        Days: {{ empty($dealDays) ? 'Every day' : collect($dealDays)->map(fn($d) => $dayNames[$d] ?? $d)->implode(', ') }}
                        @if($deal->starts_on || $deal->ends_on)
                            · {{ $deal->starts_on?->format('d M Y') ?? '…' }} → {{ $deal->ends_on?->format('d M Y') ?? '…' }}
                        @endif
                    </p>
                </div>
                <div class="flex items-center gap-3 shrink-0">
                    <span class="text-lg font-extrabold text-gray-900 dark:text-white">Rs. {{ number_format((float) $deal->price, 2) }}</span>
                    <button type="button" @click="editing = !editing" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition" x-text="editing ? 'Close' : 'Edit'"></button>
                    <form method="POST" action="{{ route('pos.deals.delete', $deal->id) }}" onsubmit="return confirm('Delete this deal? Sold bills are not affected.');">
                        @csrf @method('DELETE')
                        <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/40 transition">Delete</button>
                    </form>
                </div>
            </div>

            {{-- Edit form --}}
            <div x-show="editing" x-cloak class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800">
                <form method="POST" action="{{ route('pos.deals.update', $deal->id) }}">
                    @csrf @method('PUT')
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Deal Name</label>
                            <input type="text" name="name" required maxlength="255" value="{{ $deal->name }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Deal Price (PKR)</label>
                            <input type="number" name="price" required step="0.01" min="0" value="{{ $deal->price }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Description</label>
                            <input type="text" name="description" maxlength="255" value="{{ $deal->description }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-2">Active Days <span class="text-gray-400">(none = every day)</span></label>
                        <div class="flex flex-wrap gap-2">
                            @foreach($dayNames as $num => $label)
                            <label class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 text-xs font-semibold text-gray-700 dark:text-gray-300 cursor-pointer has-[:checked]:bg-purple-600 has-[:checked]:text-white has-[:checked]:border-purple-600 transition">
                                <input type="checkbox" name="active_days[]" value="{{ $num }}" @checked(in_array($num, $dealDays, true)) class="sr-only">
                                {{ $label }}
                            </label>
                            @endforeach
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Start Date</label>
                            <input type="date" name="starts_on" value="{{ $deal->starts_on?->format('Y-m-d') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">End Date</label>
                            <input type="date" name="ends_on" value="{{ $deal->ends_on?->format('Y-m-d') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div class="flex items-end pb-1">
                            <label class="inline-flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="is_active" value="1" @checked($deal->is_active) class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                                <span class="text-xs font-medium text-gray-600 dark:text-gray-400">Active</span>
                            </label>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-2">Deal Items</label>
                        <template x-for="(row, idx) in rows" :key="idx">
                            <div class="flex items-center gap-2 mb-2">
                                <select :name="'items[' + idx + '][product_id]'" x-model="row.product_id" required class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                    <option value="">Select product…</option>
                                    <template x-for="p in products" :key="p.id">
                                        <option :value="p.id" x-text="p.name + ' — Rs. ' + p.price" :selected="String(row.product_id) === String(p.id)"></option>
                                    </template>
                                </select>
                                <input type="number" :name="'items[' + idx + '][quantity]'" x-model.number="row.quantity" required min="1" max="999" class="w-20 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500" title="Quantity">
                                <button type="button" @click="rows.splice(idx, 1)" x-show="rows.length > 1" class="p-2 rounded-lg text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition" title="Remove item">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                        </template>
                        <button type="button" @click="rows.push({ product_id: '', quantity: 1 })" class="mt-1 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Add Item
                        </button>
                    </div>
                    <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-lg transition">Save Changes</button>
                </form>
            </div>
        </div>
        @empty
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-10 text-center">
            <p class="text-sm text-gray-500 dark:text-gray-400">No deals yet — create your first deal above. It will appear on the sale screen automatically on its active days.</p>
        </div>
        @endforelse
    </div>
</div>
</x-pos-layout>
