<x-admin-layout>
<div class="p-4 sm:p-6 max-w-5xl mx-auto">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">Sales &amp; Discounts</h1>
        <p class="text-sm text-gray-400 mt-1">Run a limited-time discount across your plans. Sales auto-expire on their end date — no manual switch-off needed. Prices update instantly on every landing &amp; billing page.</p>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6">
        <h3 class="text-sm font-semibold text-white mb-4">Create a Sale</h3>
        <form method="POST" action="{{ route('saas.admin.sales.store') }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <div>
                    <label class="text-[10px] text-gray-500 uppercase">Name (optional)</label>
                    <input type="text" name="name" value="{{ old('name') }}" placeholder="e.g. Eid Sale" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="text-[10px] text-gray-500 uppercase">Applies To</label>
                    <select name="scope" required class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                        <option value="all">All Products</option>
                        <option value="di">Digital Invoice only</option>
                        <option value="pos">PRA POS only</option>
                        <option value="fbrpos">FBR POS only</option>
                    </select>
                </div>
                <div>
                    <label class="text-[10px] text-gray-500 uppercase">Discount %</label>
                    <input type="number" name="discount_percent" value="{{ old('discount_percent') }}" min="1" max="100" step="0.01" placeholder="e.g. 30" required class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="text-[10px] text-gray-500 uppercase">Start Date (blank = now)</label>
                    <input type="date" name="starts_at" value="{{ old('starts_at') }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="text-[10px] text-gray-500 uppercase">End Date (blank = no expiry)</label>
                    <input type="date" name="ends_at" value="{{ old('ends_at') }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                </div>
            </div>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">Launch Sale</button>
        </form>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-800"><h3 class="text-sm font-semibold text-white">All Sales</h3></div>
        @if($campaigns->isEmpty())
        <div class="p-6 text-sm text-gray-500 text-center">No sales yet. Create one above.</div>
        @else
        <div class="divide-y divide-gray-800">
            @foreach($campaigns as $c)
            @php
                $scopeLabels = ['all' => 'All Products', 'di' => 'Digital Invoice', 'pos' => 'PRA POS', 'fbrpos' => 'FBR POS'];
                $status = $c->status_label;
                $statusColors = [
                    'Live' => 'bg-emerald-900/40 text-emerald-300',
                    'Scheduled' => 'bg-amber-900/40 text-amber-300',
                    'Expired' => 'bg-gray-800 text-gray-400',
                    'Paused' => 'bg-gray-800 text-gray-400',
                ];
            @endphp
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="text-white font-semibold text-sm">{{ $c->name ?: (($scopeLabels[$c->scope] ?? $c->scope) . ' Sale') }}</span>
                        <span class="text-[10px] px-1.5 py-0.5 rounded font-bold {{ $statusColors[$status] ?? 'bg-gray-800 text-gray-400' }}">{{ strtoupper($status) }}</span>
                    </div>
                    <div class="text-xs text-gray-400 mt-0.5">
                        {{ rtrim(rtrim(number_format($c->discount_percent, 2), '0'), '.') }}% OFF · {{ $scopeLabels[$c->scope] ?? $c->scope }}
                        · {{ $c->starts_at ? $c->starts_at->format('d M Y') : 'now' }} → {{ $c->ends_at ? $c->ends_at->format('d M Y') : 'no expiry' }}
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <form method="POST" action="{{ route('saas.admin.sales.toggle', $c->id) }}">
                        @csrf
                        <button class="text-xs px-3 py-1.5 rounded-lg font-semibold transition {{ $c->is_active ? 'bg-amber-600/20 text-amber-300 hover:bg-amber-600/30' : 'bg-emerald-600/20 text-emerald-300 hover:bg-emerald-600/30' }}">{{ $c->is_active ? 'Pause' : 'Resume' }}</button>
                    </form>
                    <form method="POST" action="{{ route('saas.admin.sales.destroy', $c->id) }}" onsubmit="return confirm('Delete this sale?')">
                        @csrf
                        @method('DELETE')
                        <button class="text-xs px-3 py-1.5 rounded-lg font-semibold bg-red-600/20 text-red-300 hover:bg-red-600/30 transition">Delete</button>
                    </form>
                </div>
            </div>
            @endforeach
        </div>
        @endif
    </div>
</div>
</x-admin-layout>
