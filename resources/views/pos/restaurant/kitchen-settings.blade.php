<x-pos-layout>
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.kitchen_settings') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.kitchen_settings_subtitle') }}</p>
    </div>

    @if(session('success'))
    <div class="mb-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-xl p-3 text-sm text-green-700 dark:text-green-400">
        {{ session('success') }}
    </div>
    @endif

    <form method="POST" action="{{ route('pos.restaurant.kitchen-settings.update') }}" class="space-y-6">
        @csrf
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-200 dark:border-gray-700 divide-y divide-gray-200 dark:divide-gray-700">
            <div class="p-5 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.kds_title') }}</h3>
                    <p class="text-xs text-gray-500 mt-0.5">{{ __('pos.kds_hint') }}</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="hidden" name="kds_enabled" value="0">
                    <input type="checkbox" name="kds_enabled" value="1" {{ $company->kds_enabled ? 'checked' : '' }} class="sr-only peer">
                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-300 dark:peer-focus:ring-purple-800 rounded-full peer dark:bg-gray-600 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-500 peer-checked:bg-purple-600"></div>
                </label>
            </div>
            <div class="p-5 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.kitchen_printer') }}</h3>
                    <p class="text-xs text-gray-500 mt-0.5">{{ __('pos.kitchen_printer_hint') }}</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="hidden" name="kitchen_printer_enabled" value="0">
                    <input type="checkbox" name="kitchen_printer_enabled" value="1" {{ $company->kitchen_printer_enabled ? 'checked' : '' }} class="sr-only peer">
                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-300 dark:peer-focus:ring-purple-800 rounded-full peer dark:bg-gray-600 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-500 peer-checked:bg-purple-600"></div>
                </label>
            </div>
            <div class="p-5 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.print_kot_on_hold') }}</h3>
                    <p class="text-xs text-gray-500 mt-0.5">{{ __('pos.print_kot_on_hold_hint') }}</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="hidden" name="print_on_hold" value="0">
                    <input type="checkbox" name="print_on_hold" value="1" {{ $company->print_on_hold ? 'checked' : '' }} class="sr-only peer">
                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-300 dark:peer-focus:ring-purple-800 rounded-full peer dark:bg-gray-600 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-500 peer-checked:bg-purple-600"></div>
                </label>
            </div>
            <div class="p-5 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.dine_in_auto_kot_title') }}</h3>
                    <p class="text-xs text-gray-500 mt-0.5">{{ __('pos.dine_in_auto_kot_hint') }}</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="hidden" name="dine_in_auto_kot" value="0">
                    <input type="checkbox" name="dine_in_auto_kot" value="1" {{ ($company->dine_in_auto_kot ?? false) ? 'checked' : '' }} class="sr-only peer">
                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-300 dark:peer-focus:ring-purple-800 rounded-full peer dark:bg-gray-600 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-500 peer-checked:bg-purple-600"></div>
                </label>
            </div>
            <div class="p-5 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.always_full_kot') }}</h3>
                    <p class="text-xs text-gray-500 mt-0.5">{{ __('pos.always_full_kot_hint') }}</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="hidden" name="pos_kot_full_mode" value="0">
                    <input type="checkbox" name="pos_kot_full_mode" value="1" {{ ($company->pos_kot_full_mode ?? false) ? 'checked' : '' }} class="sr-only peer">
                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-300 dark:peer-focus:ring-purple-800 rounded-full peer dark:bg-gray-600 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-500 peer-checked:bg-purple-600"></div>
                </label>
            </div>
            <div class="p-5 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.delivery_kot_after_payment_title') }}</h3>
                    <p class="text-xs text-gray-500 mt-0.5">{{ __('pos.delivery_kot_after_payment_hint') }}</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="hidden" name="delivery_kot_after_payment" value="0">
                    <input type="checkbox" name="delivery_kot_after_payment" value="1" {{ ($company->delivery_kot_after_payment ?? false) ? 'checked' : '' }} class="sr-only peer">
                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-300 dark:peer-focus:ring-purple-800 rounded-full peer dark:bg-gray-600 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-500 peer-checked:bg-purple-600"></div>
                </label>
            </div>
            <div class="p-5 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.print_receipt_on_pay') }}</h3>
                    <p class="text-xs text-gray-500 mt-0.5">{{ __('pos.print_receipt_on_pay_hint') }}</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="hidden" name="print_on_pay" value="0">
                    <input type="checkbox" name="print_on_pay" value="1" {{ $company->print_on_pay ? 'checked' : '' }} class="sr-only peer">
                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-300 dark:peer-focus:ring-purple-800 rounded-full peer dark:bg-gray-600 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-500 peer-checked:bg-purple-600"></div>
                </label>
            </div>
        </div>

        {{-- KOT Print Style (customer feedback 27 Jul 2026, Pizza Master): paper-saving
             toggles + print position. Defaults keep the ticket exactly as before. --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-200 dark:border-gray-700">
            <div class="p-5 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.kot_print_style') }}</h3>
                <p class="text-xs text-gray-500 mt-0.5">{{ __('pos.kot_print_style_hint') }}</p>
            </div>
            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                <div class="p-5 flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.compact_kot') }}</h3>
                        <p class="text-xs text-gray-500 mt-0.5">{{ __('pos.compact_kot_hint') }}</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="hidden" name="kot_compact" value="0">
                        <input type="checkbox" name="kot_compact" value="1" {{ ($company->kot_compact ?? false) ? 'checked' : '' }} class="sr-only peer">
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-300 dark:peer-focus:ring-purple-800 rounded-full peer dark:bg-gray-600 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-500 peer-checked:bg-purple-600"></div>
                    </label>
                </div>
                <div class="p-5 flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.show_customer_name') }}</h3>
                        <p class="text-xs text-gray-500 mt-0.5">{{ __('pos.show_customer_name_hint') }}</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="hidden" name="kot_show_customer" value="0">
                        <input type="checkbox" name="kot_show_customer" value="1" {{ ($company->kot_show_customer ?? true) ? 'checked' : '' }} class="sr-only peer">
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-300 dark:peer-focus:ring-purple-800 rounded-full peer dark:bg-gray-600 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-500 peer-checked:bg-purple-600"></div>
                    </label>
                </div>
                <div class="p-5 flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.show_order_by_item_count') }}</h3>
                        <p class="text-xs text-gray-500 mt-0.5">{{ __('pos.show_order_by_item_count_hint') }}</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="hidden" name="kot_show_orderby" value="0">
                        <input type="checkbox" name="kot_show_orderby" value="1" {{ ($company->kot_show_orderby ?? true) ? 'checked' : '' }} class="sr-only peer">
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-300 dark:peer-focus:ring-purple-800 rounded-full peer dark:bg-gray-600 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-500 peer-checked:bg-purple-600"></div>
                    </label>
                </div>
                <div class="p-5 flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.show_barcode') }}</h3>
                        <p class="text-xs text-gray-500 mt-0.5">{{ __('pos.show_barcode_hint') }}</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="hidden" name="kot_show_barcode" value="0">
                        <input type="checkbox" name="kot_show_barcode" value="1" {{ ($company->kot_show_barcode ?? true) ? 'checked' : '' }} class="sr-only peer">
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-300 dark:peer-focus:ring-purple-800 rounded-full peer dark:bg-gray-600 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-500 peer-checked:bg-purple-600"></div>
                    </label>
                </div>
                <div class="p-5 flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.show_business_name_bottom') }}</h3>
                        <p class="text-xs text-gray-500 mt-0.5">{{ __('pos.show_business_name_bottom_hint') }}</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="hidden" name="kot_show_footer" value="0">
                        <input type="checkbox" name="kot_show_footer" value="1" {{ ($company->kot_show_footer ?? true) ? 'checked' : '' }} class="sr-only peer">
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-300 dark:peer-focus:ring-purple-800 rounded-full peer dark:bg-gray-600 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-500 peer-checked:bg-purple-600"></div>
                    </label>
                </div>
                <div class="p-5 flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.show_kitchen_notes_box') }}</h3>
                        <p class="text-xs text-gray-500 mt-0.5">{{ __('pos.show_kitchen_notes_box_hint') }}</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="hidden" name="kot_show_kitchen_notes" value="0">
                        <input type="checkbox" name="kot_show_kitchen_notes" value="1" {{ ($company->kot_show_kitchen_notes ?? true) ? 'checked' : '' }} class="sr-only peer">
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-300 dark:peer-focus:ring-purple-800 rounded-full peer dark:bg-gray-600 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-500 peer-checked:bg-purple-600"></div>
                    </label>
                </div>
                <div class="p-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-900 dark:text-white mb-1">{{ __('pos.print_position') }}</label>
                            <select name="kot_align_center" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm">
                                <option value="0" {{ !($company->kot_align_center ?? false) ? 'selected' : '' }}>{{ __('pos.print_pos_left_edge') }}</option>
                                <option value="1" {{ ($company->kot_align_center ?? false) ? 'selected' : '' }}>{{ __('pos.print_pos_center') }}</option>
                            </select>
                            <p class="text-[11px] text-amber-600 dark:text-amber-400 mt-1">{{ __('pos.print_pos_center_warn') }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-900 dark:text-white mb-1">{{ __('pos.left_margin_mm') }}</label>
                            <input type="number" name="kot_left_margin_mm" min="0" max="30" step="1" value="{{ (int) ($company->kot_left_margin_mm ?? 0) }}"
                                   class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm">
                            <p class="text-[11px] text-gray-400 mt-1">{{ __('pos.left_margin_mm_hint') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-200 dark:border-gray-700 p-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">{{ __('pos.order_flow') }}</h3>
            <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                <span class="px-2 py-1 bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 rounded-lg font-medium">HOLD</span>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                <span class="px-2 py-1 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 rounded-lg font-medium">KDS</span>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                <span class="px-2 py-1 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 rounded-lg font-medium">READY</span>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                <span class="px-2 py-1 bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400 rounded-lg font-medium">PAY</span>
            </div>
            <p class="text-xs text-gray-400 mt-2">{{ __('pos.order_flow_hint') }}</p>
        </div>

        <button type="submit" class="w-full py-2.5 rounded-xl text-sm font-bold bg-purple-600 hover:bg-purple-700 text-white shadow-sm transition-all">
            {{ __('pos.save_kitchen_settings') }}
        </button>
    </form>

    @php $posUser = auth('pos')->user(); @endphp
    @if($posUser && !$posUser->isPosCashier())
    {{-- ── Counter/Station KOT routing (owner, Jul 2026) ─────────────────────
         Each counter claims product categories; one order's KOT splits so every
         counter prints/sees ONLY its own items. Unassigned categories, manual
         items and services always go to the main Kitchen. Zero counters =
         classic single KOT (feature dormant). --}}
    <div class="mt-8" x-data="{ addOpen: {{ $errors->any() ? 'true' : 'false' }}, editId: null }">
        <div class="mb-4">
            <h2 class="text-lg font-bold text-gray-900 dark:text-white">{{ __('pos.counters_stations') }}</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.counters_stations_hint') }}</p>
        </div>

        @if($errors->any())
        <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl p-3 text-sm text-red-700 dark:text-red-400">
            <ul class="list-disc list-inside space-y-0.5">
                @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
        @endif

        @if(($categories ?? collect())->isEmpty())
        <div class="mb-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl p-3 text-xs text-amber-700 dark:text-amber-400">
            {{ __('pos.no_product_categories_yet') }}
        </div>
        @endif

        <div class="space-y-3">
            @foreach(($stations ?? collect()) as $st)
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-200 dark:border-gray-700 p-4">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-bold text-gray-900 dark:text-white">{{ $st->name }}</span>
                            @if(!$st->is_active)
                            <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-300 font-semibold">OFF</span>
                            @endif
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            {{ collect($st->categories ?? [])->isEmpty() ? __('pos.no_categories_assigned') : collect($st->categories)->implode(', ') }}
                            <span class="mx-1">·</span>
                            {{ __('pos.printer_colon') }} {{ $st->printer_name ?: __('pos.company_kot_printer') }}
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" @click="editId = editId === {{ $st->id }} ? null : {{ $st->id }}"
                                class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-purple-300 text-purple-700 hover:bg-purple-50 dark:border-purple-700 dark:text-purple-300 dark:hover:bg-purple-900/20">{{ __('pos.edit') }}</button>
                        <form method="POST" action="{{ route('pos.restaurant.stations.delete', $st->id) }}"
                              onsubmit="return confirm({{ Js::from(__('pos.confirm_remove_counter', ['name' => $st->name])) }});">
                            @csrf
                            <button type="submit" class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-red-300 text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-300 dark:hover:bg-red-900/20">{{ __('pos.remove_word') }}</button>
                        </form>
                    </div>
                </div>

                <form method="POST" action="{{ route('pos.restaurant.stations.update', $st->id) }}" x-show="editId === {{ $st->id }}" x-cloak class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700 space-y-3">
                    @csrf
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.counter_name') }}</label>
                            <input type="text" name="name" value="{{ $st->name }}" required maxlength="60"
                                   class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.printer_desktop_agent') }}</label>
                            <select name="printer_name" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm">
                                <option value="">{{ __('pos.use_company_kot_printer') }}</option>
                                @foreach(($printers ?? collect()) as $p)
                                <option value="{{ $p }}" {{ $st->printer_name === $p ? 'selected' : '' }}>{{ $p }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.product_categories') }}</label>
                        <div class="flex flex-wrap gap-2">
                            @foreach(($categories ?? collect()) as $cat)
                            <label class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 text-xs text-gray-700 dark:text-gray-300 cursor-pointer hover:border-purple-400">
                                <input type="checkbox" name="categories[]" value="{{ $cat }}"
                                       {{ collect($st->categories ?? [])->contains(fn($c) => mb_strtolower(trim($c)) === mb_strtolower(trim($cat))) ? 'checked' : '' }}
                                       class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-3.5 h-3.5">
                                {{ $cat }}
                            </label>
                            @endforeach
                        </div>
                    </div>
                    <div class="flex items-center justify-between">
                        <label class="inline-flex items-center gap-2 text-xs font-semibold text-gray-700 dark:text-gray-300 cursor-pointer">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" {{ $st->is_active ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                            {{ __('pos.active_word') }}
                        </label>
                        <button type="submit" class="px-4 py-2 text-xs font-bold rounded-lg bg-purple-600 hover:bg-purple-700 text-white">{{ __('pos.save_counter') }}</button>
                    </div>
                </form>
            </div>
            @endforeach
        </div>

        <div class="mt-3">
            <button type="button" @click="addOpen = !addOpen" x-show="!addOpen"
                    class="w-full py-2.5 rounded-xl text-sm font-bold border-2 border-dashed border-purple-300 dark:border-purple-700 text-purple-700 dark:text-purple-300 hover:bg-purple-50 dark:hover:bg-purple-900/20">
                {{ __('pos.add_counter_btn') }}
            </button>
            <form method="POST" action="{{ route('pos.restaurant.stations.store') }}" x-show="addOpen" x-cloak
                  class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                @csrf
                <h3 class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.new_counter') }}</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.counter_name') }}</label>
                        <input type="text" name="name" value="{{ old('name') }}" required maxlength="60" placeholder="{{ __('pos.ph_eg_ice_cream_counter') }}"
                               class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.printer_desktop_agent') }}</label>
                        <select name="printer_name" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm">
                            <option value="">{{ __('pos.use_company_kot_printer') }}</option>
                            @foreach(($printers ?? collect()) as $p)
                            <option value="{{ $p }}" {{ old('printer_name') === $p ? 'selected' : '' }}>{{ $p }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.product_categories') }}</label>
                    <div class="flex flex-wrap gap-2">
                        @foreach(($categories ?? collect()) as $cat)
                        <label class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 text-xs text-gray-700 dark:text-gray-300 cursor-pointer hover:border-purple-400">
                            <input type="checkbox" name="categories[]" value="{{ $cat }}"
                                   {{ collect(old('categories', []))->contains($cat) ? 'checked' : '' }}
                                   class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-3.5 h-3.5">
                            {{ $cat }}
                        </label>
                        @endforeach
                    </div>
                    <p class="text-[11px] text-gray-400 mt-1">{{ __('pos.category_one_counter_hint') }}</p>
                </div>
                <div class="flex items-center justify-between">
                    <label class="inline-flex items-center gap-2 text-xs font-semibold text-gray-700 dark:text-gray-300 cursor-pointer">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" checked class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                        {{ __('pos.active_word') }}
                    </label>
                    <div class="flex items-center gap-2">
                        <button type="button" @click="addOpen = false" class="px-4 py-2 text-xs font-semibold rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300">{{ __('pos.cancel') }}</button>
                        <button type="submit" class="px-4 py-2 text-xs font-bold rounded-lg bg-purple-600 hover:bg-purple-700 text-white">{{ __('pos.add_counter') }}</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    @endif
</div>
</x-pos-layout>
