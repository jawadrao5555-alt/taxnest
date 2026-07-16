<x-pos-layout>
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Kitchen Settings</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Configure how orders flow to the kitchen</p>
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
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Kitchen Display System (KDS)</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Show orders on the KDS screen when held</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="hidden" name="kds_enabled" value="0">
                    <input type="checkbox" name="kds_enabled" value="1" {{ $company->kds_enabled ? 'checked' : '' }} class="sr-only peer">
                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-300 dark:peer-focus:ring-purple-800 rounded-full peer dark:bg-gray-600 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-500 peer-checked:bg-purple-600"></div>
                </label>
            </div>
            <div class="p-5 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Kitchen Printer</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Enable kitchen order ticket (KOT) printing</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="hidden" name="kitchen_printer_enabled" value="0">
                    <input type="checkbox" name="kitchen_printer_enabled" value="1" {{ $company->kitchen_printer_enabled ? 'checked' : '' }} class="sr-only peer">
                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-300 dark:peer-focus:ring-purple-800 rounded-full peer dark:bg-gray-600 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-500 peer-checked:bg-purple-600"></div>
                </label>
            </div>
            <div class="p-5 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Print KOT on Hold</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Automatically print kitchen ticket when order is held</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="hidden" name="print_on_hold" value="0">
                    <input type="checkbox" name="print_on_hold" value="1" {{ $company->print_on_hold ? 'checked' : '' }} class="sr-only peer">
                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-300 dark:peer-focus:ring-purple-800 rounded-full peer dark:bg-gray-600 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-500 peer-checked:bg-purple-600"></div>
                </label>
            </div>
            <div class="p-5 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Print Receipt on Pay</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Automatically print customer receipt after payment</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="hidden" name="print_on_pay" value="0">
                    <input type="checkbox" name="print_on_pay" value="1" {{ $company->print_on_pay ? 'checked' : '' }} class="sr-only peer">
                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-300 dark:peer-focus:ring-purple-800 rounded-full peer dark:bg-gray-600 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-500 peer-checked:bg-purple-600"></div>
                </label>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-200 dark:border-gray-700 p-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">Order Flow</h3>
            <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                <span class="px-2 py-1 bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 rounded-lg font-medium">HOLD</span>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                <span class="px-2 py-1 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 rounded-lg font-medium">KDS</span>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                <span class="px-2 py-1 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 rounded-lg font-medium">READY</span>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                <span class="px-2 py-1 bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400 rounded-lg font-medium">PAY</span>
            </div>
            <p class="text-xs text-gray-400 mt-2">Inventory deducts ONLY on payment. KOT prints on hold (if enabled).</p>
        </div>

        <button type="submit" class="w-full py-2.5 rounded-xl text-sm font-bold bg-purple-600 hover:bg-purple-700 text-white shadow-sm transition-all">
            Save Kitchen Settings
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
            <h2 class="text-lg font-bold text-gray-900 dark:text-white">Counters / Stations</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Split one order's kitchen ticket across counters (e.g. Kitchen, Ice Cream, Shakes) by product category. Each counter prints only its own items. Anything unassigned goes to the main Kitchen.</p>
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
            No product categories found yet — set categories on your products first, then assign them to counters here.
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
                            {{ collect($st->categories ?? [])->isEmpty() ? 'No categories assigned' : collect($st->categories)->implode(', ') }}
                            <span class="mx-1">·</span>
                            Printer: {{ $st->printer_name ?: 'company KOT printer' }}
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" @click="editId = editId === {{ $st->id }} ? null : {{ $st->id }}"
                                class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-purple-300 text-purple-700 hover:bg-purple-50 dark:border-purple-700 dark:text-purple-300 dark:hover:bg-purple-900/20">Edit</button>
                        <form method="POST" action="{{ route('pos.restaurant.stations.delete', $st->id) }}"
                              onsubmit="return confirm('Remove counter \'{{ addslashes($st->name) }}\'? Its categories will print with the main Kitchen again.');">
                            @csrf
                            <button type="submit" class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-red-300 text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-300 dark:hover:bg-red-900/20">Remove</button>
                        </form>
                    </div>
                </div>

                <form method="POST" action="{{ route('pos.restaurant.stations.update', $st->id) }}" x-show="editId === {{ $st->id }}" x-cloak class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700 space-y-3">
                    @csrf
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Counter Name</label>
                            <input type="text" name="name" value="{{ $st->name }}" required maxlength="60"
                                   class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Printer (Desktop Agent)</label>
                            <select name="printer_name" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm">
                                <option value="">— Use company KOT printer —</option>
                                @foreach(($printers ?? collect()) as $p)
                                <option value="{{ $p }}" {{ $st->printer_name === $p ? 'selected' : '' }}>{{ $p }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Product Categories</label>
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
                            Active
                        </label>
                        <button type="submit" class="px-4 py-2 text-xs font-bold rounded-lg bg-purple-600 hover:bg-purple-700 text-white">Save Counter</button>
                    </div>
                </form>
            </div>
            @endforeach
        </div>

        <div class="mt-3">
            <button type="button" @click="addOpen = !addOpen" x-show="!addOpen"
                    class="w-full py-2.5 rounded-xl text-sm font-bold border-2 border-dashed border-purple-300 dark:border-purple-700 text-purple-700 dark:text-purple-300 hover:bg-purple-50 dark:hover:bg-purple-900/20">
                + Add Counter
            </button>
            <form method="POST" action="{{ route('pos.restaurant.stations.store') }}" x-show="addOpen" x-cloak
                  class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                @csrf
                <h3 class="text-sm font-bold text-gray-900 dark:text-white">New Counter</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Counter Name</label>
                        <input type="text" name="name" value="{{ old('name') }}" required maxlength="60" placeholder="e.g. Ice Cream Counter"
                               class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Printer (Desktop Agent)</label>
                        <select name="printer_name" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm">
                            <option value="">— Use company KOT printer —</option>
                            @foreach(($printers ?? collect()) as $p)
                            <option value="{{ $p }}" {{ old('printer_name') === $p ? 'selected' : '' }}>{{ $p }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Product Categories</label>
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
                    <p class="text-[11px] text-gray-400 mt-1">A category can belong to only one active counter. Unassigned categories print with the main Kitchen.</p>
                </div>
                <div class="flex items-center justify-between">
                    <label class="inline-flex items-center gap-2 text-xs font-semibold text-gray-700 dark:text-gray-300 cursor-pointer">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" checked class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                        Active
                    </label>
                    <div class="flex items-center gap-2">
                        <button type="button" @click="addOpen = false" class="px-4 py-2 text-xs font-semibold rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300">Cancel</button>
                        <button type="submit" class="px-4 py-2 text-xs font-bold rounded-lg bg-purple-600 hover:bg-purple-700 text-white">Add Counter</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    @endif
</div>
</x-pos-layout>
