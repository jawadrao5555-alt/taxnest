<x-fbr-pos-layout>
{{-- FBR POS Deals (Task 1273) — port of resources/views/pos/deals.blade.php.
     Differences vs PRA: blue accent (FBR panel language), fbrpos.* routes,
     Product.default_price, product_id column (shared products table). --}}
@php
    $dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    $productsJson = $products->map(fn($p) => ['id' => $p->id, 'name' => $p->name, 'price' => (float) ($p->default_price ?? 0)])->values();
@endphp
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6"
     x-data="{ products: {{ json_encode($productsJson, JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]' }} }">
    <a href="{{ route('fbrpos.customize') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400 hover:text-blue-600 dark:hover:text-blue-400 transition mb-3">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        {{ __('pos.back_to_customize') }}
    </a>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-2">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.deals_title') }}</h1>
    </div>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">{{ __('pos.deals_intro') }}</p>
    <p class="text-xs text-blue-600 dark:text-blue-400 mb-6">{{ __('pos.fbr_deals_item_level_note') }}</p>

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm">{{ session('error') }}</div>
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
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">{{ __('pos.add_new_deal') }}</h3>
        <form method="POST" action="{{ route('fbrpos.deals.store') }}">
            @csrf
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.deal_name') }}</label>
                    <input type="text" name="name" required maxlength="255" placeholder="{{ __('pos.ph_deal_name_eg') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.deal_price_pkr') }}</label>
                    <input type="number" name="price" required step="0.01" min="1" placeholder="0.00" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.description_label') }} <span class="text-gray-400">{{ __('pos.paren_optional') }}</span></label>
                    <input type="text" name="description" maxlength="255" placeholder="{{ __('pos.ph_deal_desc_eg') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>

            <div class="mb-4">
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-2">{{ __('pos.active_days') }} <span class="text-gray-400">{{ __('pos.none_selected_every_day') }}</span></label>
                <div class="flex flex-wrap gap-2">
                    @foreach($dayNames as $num => $label)
                    <label class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 text-xs font-semibold text-gray-700 dark:text-gray-300 cursor-pointer has-[:checked]:bg-blue-600 has-[:checked]:text-white has-[:checked]:border-blue-600 transition">
                        <input type="checkbox" name="active_days[]" value="{{ $num }}" class="sr-only">
                        {{ $label }}
                    </label>
                    @endforeach
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.start_date') }} <span class="text-gray-400">{{ __('pos.paren_optional') }}</span></label>
                    <input type="date" name="starts_on" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.end_date') }} <span class="text-gray-400">{{ __('pos.paren_optional') }}</span></label>
                    <input type="date" name="ends_on" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>

            <div class="mb-4">
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-2">{{ __('pos.deal_items_included') }}</label>
                <template x-for="(row, idx) in rows" :key="idx">
                    <div class="flex items-center gap-2 mb-2">
                        <select :name="'items[' + idx + '][product_id]'" x-model="row.product_id" required class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value="">{{ __('pos.select_product_dots') }}</option>
                            <template x-for="p in products" :key="p.id">
                                <option :value="p.id" x-text="p.name + ' — Rs. ' + p.price" :selected="String(row.product_id) === String(p.id)"></option>
                            </template>
                        </select>
                        <input type="number" :name="'items[' + idx + '][quantity]'" x-model.number="row.quantity" required min="1" max="999" class="w-20 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500" title="{{ __('pos.quantity_label') }}">
                        <button type="button" @click="rows.splice(idx, 1)" x-show="rows.length > 1" class="p-2 rounded-lg text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition" title="{{ __('pos.remove_item') }}">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                </template>
                <button type="button" @click="rows.push({ product_id: '', quantity: 1 })" class="mt-1 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    {{ __('pos.add_item') }}
                </button>
            </div>

            <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition">{{ __('pos.add_deal') }}</button>
        </form>
    </div>

    {{-- Deals list --}}
    <div class="space-y-4">
        @forelse($deals as $deal)
        @php
            $dealDays = array_map('intval', (array) ($deal->active_days ?? []));
            $componentsText = $deal->items->map(fn($di) => $di->quantity . 'x ' . ($productNames[$di->product_id] ?? 'Product #' . $di->product_id))->implode(', ');
            $dealItemsJson = $deal->items->map(fn($di) => ['product_id' => $di->product_id, 'quantity' => (int) $di->quantity])->values();
        @endphp
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5"
             x-data="{ editing: false, rows: {{ json_encode($dealItemsJson, JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]' }} }">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <h4 class="text-sm font-bold text-gray-900 dark:text-white">{{ $deal->name }}</h4>
                        @if($deal->is_active)
                            @if($deal->isActiveOn())
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400">{{ __('pos.live_today') }}</span>
                            @else
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">{{ __('pos.active_word') }}</span>
                            @endif
                        @else
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">{{ __('pos.inactive_word') }}</span>
                        @endif
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 truncate">{{ $componentsText ?: __('pos.no_items') }}</p>
                    <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-0.5">
                        {{ __('pos.days_colon') }} {{ empty($dealDays) ? __('pos.every_day') : collect($dealDays)->map(fn($d) => $dayNames[$d] ?? $d)->implode(', ') }}
                        @if($deal->starts_on || $deal->ends_on)
                            · {{ $deal->starts_on?->format('d M Y') ?? '…' }} → {{ $deal->ends_on?->format('d M Y') ?? '…' }}
                        @endif
                    </p>
                </div>
                <div class="flex items-center gap-3 shrink-0">
                    <span class="text-lg font-extrabold text-gray-900 dark:text-white">Rs. {{ number_format((float) $deal->price, 2) }}</span>
                    <button type="button" @click="editing = !editing" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition" x-text="editing ? @js(__('pos.close')) : @js(__('pos.edit'))"></button>
                    <form method="POST" action="{{ route('fbrpos.deals.delete', $deal->id) }}" onsubmit="return confirm({{ Js::from(__('pos.confirm_delete_deal')) }});">
                        @csrf @method('DELETE')
                        <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/40 transition">{{ __('pos.delete') }}</button>
                    </form>
                </div>
            </div>

            {{-- Edit form --}}
            <div x-show="editing" x-cloak class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800">
                <form method="POST" action="{{ route('fbrpos.deals.update', $deal->id) }}">
                    @csrf @method('PUT')
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.deal_name') }}</label>
                            <input type="text" name="name" required maxlength="255" value="{{ $deal->name }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.deal_price_pkr') }}</label>
                            <input type="number" name="price" required step="0.01" min="1" value="{{ $deal->price }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.description_label') }}</label>
                            <input type="text" name="description" maxlength="255" value="{{ $deal->description }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-2">{{ __('pos.active_days') }} <span class="text-gray-400">{{ __('pos.none_every_day') }}</span></label>
                        <div class="flex flex-wrap gap-2">
                            @foreach($dayNames as $num => $label)
                            <label class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 text-xs font-semibold text-gray-700 dark:text-gray-300 cursor-pointer has-[:checked]:bg-blue-600 has-[:checked]:text-white has-[:checked]:border-blue-600 transition">
                                <input type="checkbox" name="active_days[]" value="{{ $num }}" @checked(in_array($num, $dealDays, true)) class="sr-only">
                                {{ $label }}
                            </label>
                            @endforeach
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.start_date') }}</label>
                            <input type="date" name="starts_on" value="{{ $deal->starts_on?->format('Y-m-d') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.end_date') }}</label>
                            <input type="date" name="ends_on" value="{{ $deal->ends_on?->format('Y-m-d') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div class="flex items-end pb-1">
                            <label class="inline-flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="is_active" value="1" @checked($deal->is_active) class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                <span class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('pos.active_word') }}</span>
                            </label>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-2">{{ __('pos.deal_items') }}</label>
                        <template x-for="(row, idx) in rows" :key="idx">
                            <div class="flex items-center gap-2 mb-2">
                                <select :name="'items[' + idx + '][product_id]'" x-model="row.product_id" required class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">{{ __('pos.select_product_dots') }}</option>
                                    <template x-for="p in products" :key="p.id">
                                        <option :value="p.id" x-text="p.name + ' — Rs. ' + p.price" :selected="String(row.product_id) === String(p.id)"></option>
                                    </template>
                                </select>
                                <input type="number" :name="'items[' + idx + '][quantity]'" x-model.number="row.quantity" required min="1" max="999" class="w-20 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500" title="{{ __('pos.quantity_label') }}">
                                <button type="button" @click="rows.splice(idx, 1)" x-show="rows.length > 1" class="p-2 rounded-lg text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition" title="{{ __('pos.remove_item') }}">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                        </template>
                        <button type="button" @click="rows.push({ product_id: '', quantity: 1 })" class="mt-1 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            {{ __('pos.add_item') }}
                        </button>
                    </div>
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition">{{ __('pos.save_changes') }}</button>
                </form>
            </div>
        </div>
        @empty
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-10 text-center">
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('pos.no_deals_yet') }}</p>
        </div>
        @endforelse
    </div>
</div>
</x-fbr-pos-layout>
