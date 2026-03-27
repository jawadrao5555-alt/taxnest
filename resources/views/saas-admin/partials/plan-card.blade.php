<div class="bg-gray-900 border border-gray-800 rounded-xl p-5" x-data="{ editing: false }">
    <div class="flex items-center justify-between mb-3">
        <div class="flex items-center gap-2">
            <h3 class="text-lg font-bold text-white">{{ $plan->name }}</h3>
            @if($plan->is_trial)<span class="text-[10px] px-1.5 py-0.5 bg-blue-900/50 text-blue-300 rounded font-bold">TRIAL</span>@endif
            @php $badgeColors = ['di' => 'bg-emerald-900/50 text-emerald-300', 'pos' => 'bg-purple-900/50 text-purple-300', 'fbrpos' => 'bg-blue-900/50 text-blue-300']; @endphp
            <span class="text-[10px] px-1.5 py-0.5 rounded font-bold {{ $badgeColors[$plan->product_type] ?? 'bg-gray-900/50 text-gray-300' }}">{{ strtoupper($plan->product_type) }}</span>
        </div>
        <button @click="editing = !editing" class="text-xs px-2 py-1 rounded transition" :class="editing ? 'bg-red-600/20 text-red-400 hover:bg-red-600/30' : 'bg-indigo-600/20 text-indigo-400 hover:bg-indigo-600/30'" x-text="editing ? 'Cancel' : 'Edit'"></button>
    </div>

    <div x-show="!editing">
        <div class="text-2xl font-bold text-{{ $color }}-400 mb-3">PKR {{ number_format($plan->price, 0) }}<span class="text-sm text-gray-500 dark:text-gray-400 font-normal">{{ $plan->product_type === 'pos' ? '/yr' : '/mo' }}</span></div>

        <div class="space-y-1.5 text-sm">
            <div class="flex justify-between"><span class="text-gray-400">Invoices{{ $plan->product_type === 'pos' ? '' : '/mo' }}</span><span class="text-white">{{ $plan->invoice_limit > 0 ? number_format($plan->invoice_limit) : ($plan->invoice_limit == -1 ? 'Unlimited' : '0') }}</span></div>
            <div class="flex justify-between"><span class="text-gray-400">Users</span><span class="text-white">{{ ($plan->max_users ?? 0) == -1 ? 'Unlimited' : ($plan->max_users ?? ($plan->user_limit ?? 'N/A')) }}</span></div>
            <div class="flex justify-between"><span class="text-gray-400">Terminals</span><span class="text-white">{{ ($plan->max_terminals ?? 0) == -1 ? 'Unlimited' : ($plan->max_terminals ?? 'N/A') }}</span></div>
            <div class="flex justify-between"><span class="text-gray-400">Products</span><span class="text-white">{{ ($plan->max_products ?? 0) == -1 ? 'Unlimited' : ($plan->max_products ?? 'N/A') }}</span></div>
            <div class="flex justify-between"><span class="text-gray-400">Inventory</span><span class="{{ $plan->inventory_enabled ? 'text-emerald-400' : 'text-red-400' }}">{{ $plan->inventory_enabled ? 'Yes' : 'No' }}</span></div>
            <div class="flex justify-between"><span class="text-gray-400">Reports</span><span class="{{ $plan->reports_enabled ? 'text-emerald-400' : 'text-red-400' }}">{{ $plan->reports_enabled ? 'Yes' : 'No' }}</span></div>
        </div>
        @if($plan->features && is_array($plan->features) && count($plan->features))
        <div class="mt-3 pt-3 border-t border-gray-800">
            <p class="text-[10px] text-gray-500 dark:text-gray-400 uppercase mb-1.5">Landing Page Features</p>
            <div class="space-y-1">
                @foreach($plan->features as $feature)
                <div class="flex items-center gap-1.5 text-xs text-gray-300">
                    <svg class="w-3 h-3 text-{{ $color }}-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    {{ $feature }}
                </div>
                @endforeach
            </div>
        </div>
        @endif
    </div>

    <form x-show="editing" method="POST" action="{{ route('saas.admin.plans.update', $plan->id) }}" class="space-y-3">
        @csrf
        @method('PUT')
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            <div>
                <label class="text-[10px] text-gray-500 dark:text-gray-400 uppercase">Name</label>
                <input type="text" name="name" value="{{ $plan->name }}" required class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-1.5 focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="text-[10px] text-gray-500 dark:text-gray-400 uppercase">Product Type</label>
                <select name="product_type" required class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-1.5 focus:ring-2 focus:ring-indigo-500">
                    <option value="di" {{ $plan->product_type === 'di' ? 'selected' : '' }}>Digital Invoice</option>
                    <option value="pos" {{ $plan->product_type === 'pos' ? 'selected' : '' }}>PRA POS</option>
                    <option value="fbrpos" {{ $plan->product_type === 'fbrpos' ? 'selected' : '' }}>FBR POS</option>
                </select>
            </div>
            <div>
                <label class="text-[10px] text-gray-500 dark:text-gray-400 uppercase">Price (PKR{{ $plan->product_type === 'pos' ? '/yr' : '/mo' }})</label>
                <input type="number" name="price" value="{{ intval($plan->price) }}" step="1" required class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-1.5 focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="text-[10px] text-gray-500 dark:text-gray-400 uppercase">Invoice Limit</label>
                <input type="number" name="invoice_limit" value="{{ $plan->invoice_limit }}" required class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-1.5 focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="text-[10px] text-gray-500 dark:text-gray-400 uppercase">Max Terminals</label>
                <input type="number" name="max_terminals" value="{{ $plan->max_terminals }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-1.5 focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="text-[10px] text-gray-500 dark:text-gray-400 uppercase">Max Users</label>
                <input type="number" name="max_users" value="{{ $plan->max_users }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-1.5 focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="text-[10px] text-gray-500 dark:text-gray-400 uppercase">Max Products</label>
                <input type="number" name="max_products" value="{{ $plan->max_products }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-1.5 focus:ring-2 focus:ring-indigo-500">
            </div>
            <div class="flex items-center gap-4 pt-4">
                <label class="flex items-center gap-1.5 text-sm text-gray-400"><input type="checkbox" name="inventory_enabled" value="1" {{ $plan->inventory_enabled ? 'checked' : '' }} class="rounded bg-gray-800 border-gray-600 text-indigo-500"> Inventory</label>
                <label class="flex items-center gap-1.5 text-sm text-gray-400"><input type="checkbox" name="reports_enabled" value="1" {{ $plan->reports_enabled ? 'checked' : '' }} class="rounded bg-gray-800 border-gray-600 text-indigo-500"> Reports</label>
            </div>
        </div>
        <div>
            <label class="text-[10px] text-gray-500 dark:text-gray-400 uppercase mb-1 block">Features (one per line — shown on landing page)</label>
            <textarea name="features_text" rows="4" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">{{ $plan->features && is_array($plan->features) ? implode("\n", $plan->features) : '' }}</textarea>
        </div>
        <button type="submit" class="w-full px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">Save Changes</button>
    </form>
</div>
