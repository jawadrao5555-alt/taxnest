<x-admin-layout>
<div class="p-4 sm:p-6 max-w-7xl mx-auto" x-data="{ activeTab: 'di' }">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white">Subscription Plans</h1>
            <p class="text-sm text-gray-400 mt-1">Changes auto-reflect on all landing & billing pages</p>
        </div>
    </div>

    @if(session('success'))
    <div class="bg-emerald-900/30 border border-emerald-700 rounded-lg p-3 mb-6 text-sm text-emerald-300">{{ session('success') }}</div>
    @endif

    {{-- Task 1455: a ladder that is already broken must not be invisible. The
         editor only refuses NEW breakage, so anything standing is shown here. --}}
    @if(!empty($ladderWarnings))
    <div class="bg-amber-900/30 border border-amber-700 rounded-lg p-4 mb-6">
        <div class="flex items-center gap-2 mb-2">
            <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            <h3 class="text-sm font-semibold text-amber-200">Package ladder needs attention</h3>
        </div>
        <p class="text-xs text-amber-300/80 mb-2">These packages already contradict each other, so the public cards and the comparison table are quietly dropping claims. Fix them here — the deploy gate checks the same thing.</p>
        <ul class="space-y-1 text-xs text-amber-100/90">
            @foreach($ladderWarnings as $productType => $problems)
                @foreach($problems as $problem)
                <li class="flex gap-1.5">
                    <span class="text-amber-400 font-semibold shrink-0">{{ \App\Services\PlanLadderGuard::LABELS[$productType] ?? $productType }}:</span>
                    <span>{{ $problem }}</span>
                </li>
                @endforeach
            @endforeach
        </ul>
    </div>
    @endif

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6">
        <div class="flex items-start justify-between gap-4 mb-4">
            <div>
                <h2 class="text-sm font-semibold text-white">PRA POS Paid Add-ons</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Set each add-on's annual and quarterly price. Defaults are Rs 12,000/year and Rs 3,000/quarter.</p>
            </div>
            <span class="text-[10px] uppercase tracking-wide text-amber-300 bg-amber-900/30 border border-amber-700/50 rounded px-2 py-1 whitespace-nowrap">Admin editable</span>
        </div>

        <form method="POST" action="{{ route('saas.admin.plans.addons.update') }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                @foreach($posAddons as $code => $addon)
                <div class="rounded-lg border border-gray-800 bg-gray-800/40 p-3">
                    <div class="mb-2">
                        <p class="text-sm font-semibold text-white">{{ $addon['label'] }}</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ $addon['description'] }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="text-[10px] text-gray-500 uppercase">
                            Annual (PKR)
                            <input type="number" name="addons[{{ $code }}][annual]" value="{{ old("addons.{$code}.annual", $addon['annual_price']) }}" min="0" max="999999999" step="1" required class="mt-1 w-full bg-gray-900 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                        </label>
                        <label class="text-[10px] text-gray-500 uppercase">
                            Quarterly (PKR)
                            <input type="number" name="addons[{{ $code }}][quarterly]" value="{{ old("addons.{$code}.quarterly", $addon['quarterly_price']) }}" min="0" max="999999999" step="1" required class="mt-1 w-full bg-gray-900 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                        </label>
                    </div>
                </div>
                @endforeach
            </div>

            <div class="flex items-start gap-2 rounded-lg border border-indigo-800/50 bg-indigo-900/20 px-3 py-2 text-[11px] text-indigo-200">
                <span class="font-semibold shrink-0">Custom Access:</span>
                <span>Not a paid add-on. It is included in Business, Pro, Pro Max and Unlimited; Starter does not include it.</span>
            </div>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">Save Add-on Rates</button>
        </form>
    </div>

    <div class="flex flex-wrap items-center gap-2 mb-6">
        <button @click="activeTab = 'di'" :class="activeTab === 'di' ? 'bg-emerald-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white'" class="px-4 py-2 rounded-lg text-sm font-semibold transition">Digital Invoice Plans</button>
        <button @click="activeTab = 'pos'" :class="activeTab === 'pos' ? 'bg-purple-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white'" class="px-4 py-2 rounded-lg text-sm font-semibold transition">PRA POS Plans</button>
        <button @click="activeTab = 'fbrpos'" :class="activeTab === 'fbrpos' ? 'bg-blue-600 text-white' : 'bg-gray-800 text-gray-400 hover:text-white'" class="px-4 py-2 rounded-lg text-sm font-semibold transition">FBR POS Plans</button>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6" x-data="{ showForm: false }">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-white">Create New Plan</h3>
            <button @click="showForm = !showForm" class="text-xs text-indigo-400 hover:underline" x-text="showForm ? 'Hide' : 'Show Form'"></button>
        </div>
        <form x-show="showForm" method="POST" action="{{ route('saas.admin.plans.store') }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <input type="text" name="name" placeholder="Plan Name" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                <div>
                    <select name="product_type" required class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                        <option value="di">Digital Invoice</option>
                        <option value="pos">PRA POS</option>
                        <option value="fbrpos">FBR POS</option>
                    </select>
                </div>
                <input type="number" name="price" placeholder="Price (PKR)" step="1" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                <input type="number" name="invoice_limit" placeholder="Invoice Limit (-1=unlimited)" required class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                <input type="number" name="max_terminals" placeholder="Max Terminals (-1=unlimited)" class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                <input type="number" name="max_users" placeholder="Max Users (-1=unlimited)" class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                <input type="number" name="max_products" placeholder="Max Products (-1=unlimited)" class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                <div class="flex items-center gap-4">
                    <label class="flex items-center gap-1.5 text-sm text-gray-400"><input type="checkbox" name="inventory_enabled" value="1" checked class="rounded bg-gray-800 border-gray-600 text-indigo-500"> Inventory</label>
                    <label class="flex items-center gap-1.5 text-sm text-gray-400"><input type="checkbox" name="reports_enabled" value="1" checked class="rounded bg-gray-800 border-gray-600 text-indigo-500"> Reports</label>
                </div>
            </div>
            <div>
                <label class="text-[10px] text-gray-500 dark:text-gray-400 uppercase mb-1 block">Features (one per line — shown on landing page)</label>
                <textarea name="features_text" rows="4" placeholder="e.g. POS Billing&#10;Thermal Receipt&#10;PRA Integration" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500"></textarea>
            </div>
            <label class="flex items-start gap-1.5 text-[11px] text-amber-300/90">
                <input type="checkbox" name="ladder_override" value="1" class="mt-0.5 rounded bg-gray-800 border-gray-600 text-amber-500">
                <span>Save anyway (breaks the ladder) — only tick this if the warning is expected.</span>
            </label>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">Create Plan</button>
        </form>
    </div>

    <div x-show="activeTab === 'di'">
        <div class="flex items-center gap-2 mb-4">
            <div class="w-2 h-2 rounded-full bg-emerald-500"></div>
            <h2 class="text-lg font-bold text-white">Digital Invoice Plans</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">({{ $diPlans->count() }} plans — prices are monthly)</span>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($diPlans as $plan)
            @include('saas-admin.partials.plan-card', ['plan' => $plan, 'color' => 'emerald'])
            @endforeach
        </div>
    </div>

    <div x-show="activeTab === 'pos'" x-cloak>
        <div class="flex items-center gap-2 mb-4">
            <div class="w-2 h-2 rounded-full bg-purple-500"></div>
            <h2 class="text-lg font-bold text-white">PRA POS Plans</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">({{ $posPlans->count() }} plans — prices are annual)</span>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($posPlans as $plan)
            @include('saas-admin.partials.plan-card', ['plan' => $plan, 'color' => 'purple'])
            @endforeach
        </div>
    </div>

    <div x-show="activeTab === 'fbrpos'" x-cloak>
        <div class="flex items-center gap-2 mb-4">
            <div class="w-2 h-2 rounded-full bg-blue-500"></div>
            <h2 class="text-lg font-bold text-white">FBR POS Plans</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">({{ $fbrposPlans->count() }} plans — prices are monthly)</span>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($fbrposPlans as $plan)
            @include('saas-admin.partials.plan-card', ['plan' => $plan, 'color' => 'blue'])
            @endforeach
        </div>
    </div>

</div>
</x-admin-layout>
