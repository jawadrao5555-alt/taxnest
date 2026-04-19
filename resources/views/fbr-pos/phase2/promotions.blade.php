<x-fbr-pos-layout>
<div class="max-w-6xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-1">Promotions</h1>
    <p class="text-sm text-gray-500 mb-6">% off, fixed off, promo codes — applied at checkout</p>

    @if(session('success'))<div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded mb-4">{{ session('success') }}</div>@endif

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-5 mb-5">
        <h2 class="font-bold mb-3 dark:text-white">+ New Promotion</h2>
        <form method="POST" action="{{ route('fbrpos.phase2.promotions.store') }}" class="grid sm:grid-cols-3 gap-3">
            @csrf
            <div><label class="block text-xs font-medium text-gray-600 mb-1">Name *</label>
                <input type="text" name="name" required placeholder="Eid Sale 2026" class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white"></div>
            <div><label class="block text-xs font-medium text-gray-600 mb-1">Promo Code (optional)</label>
                <input type="text" name="code" placeholder="EID20" class="w-full border rounded-lg px-3 py-2 text-sm uppercase dark:bg-gray-700 dark:text-white"></div>
            <div><label class="block text-xs font-medium text-gray-600 mb-1">Type *</label>
                <select name="type" required class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white">
                    <option value="percent">% Off</option>
                    <option value="fixed">Fixed PKR Off</option>
                </select></div>
            <div><label class="block text-xs font-medium text-gray-600 mb-1">Value *</label>
                <input type="number" name="value" required step="0.01" min="0" placeholder="20" class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white"></div>
            <div><label class="block text-xs font-medium text-gray-600 mb-1">Min Cart (Rs)</label>
                <input type="number" name="min_amount" step="0.01" min="0" placeholder="0" class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white"></div>
            <div><label class="block text-xs font-medium text-gray-600 mb-1">Max Discount (Rs)</label>
                <input type="number" name="max_discount" step="0.01" min="0" placeholder="optional" class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white"></div>
            <div><label class="block text-xs font-medium text-gray-600 mb-1">Valid From</label>
                <input type="date" name="valid_from" class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white"></div>
            <div><label class="block text-xs font-medium text-gray-600 mb-1">Valid Until</label>
                <input type="date" name="valid_until" class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white"></div>
            <div><label class="block text-xs font-medium text-gray-600 mb-1">Usage Limit</label>
                <input type="number" name="usage_limit" min="1" placeholder="unlimited" class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white"></div>
            <div class="sm:col-span-3">
                <button class="bg-blue-600 text-white rounded-lg px-5 py-2 text-sm font-semibold hover:bg-blue-700">Create Promotion</button>
            </div>
        </form>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700 text-left">
                <tr><th class="px-4 py-3">Name</th><th>Code</th><th>Type</th><th>Value</th><th>Min</th><th>Used</th><th>Status</th><th class="text-right pr-4">Actions</th></tr>
            </thead>
            <tbody>
            @forelse($promos as $p)
                <tr class="border-t dark:border-gray-700">
                    <td class="px-4 py-3 font-semibold dark:text-white">{{ $p->name }}</td>
                    <td class="dark:text-gray-300">{{ $p->code ? '['.$p->code.']' : 'auto' }}</td>
                    <td class="dark:text-gray-300">{{ $p->type === 'percent' ? '%' : 'Rs' }}</td>
                    <td class="dark:text-gray-300">{{ rtrim(rtrim($p->value, '0'), '.') }}{{ $p->type === 'percent' ? '%' : '' }}</td>
                    <td class="dark:text-gray-300">{{ number_format($p->min_amount, 0) }}</td>
                    <td class="dark:text-gray-300">{{ $p->usage_count }}{{ $p->usage_limit ? '/'.$p->usage_limit : '' }}</td>
                    <td>@if($p->is_active)<span class="px-2 py-1 bg-emerald-100 text-emerald-700 rounded text-xs">Active</span>@else<span class="px-2 py-1 bg-gray-100 text-gray-700 rounded text-xs">Off</span>@endif</td>
                    <td class="text-right pr-4 py-3">
                        <form method="POST" action="{{ route('fbrpos.phase2.promotions.toggle', $p->id) }}" class="inline">@csrf
                            <button class="text-blue-600 hover:underline text-sm">{{ $p->is_active ? 'Off' : 'On' }}</button></form>
                        <form method="POST" action="{{ route('fbrpos.phase2.promotions.delete', $p->id) }}" class="inline ml-2" onsubmit="return confirm('Delete?')">@csrf @method('DELETE')
                            <button class="text-red-600 hover:underline text-sm">Delete</button></form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-8 text-center text-gray-500">No promotions yet</td></tr>
            @endforelse
            </tbody>
        </table>
        @if(method_exists($promos, 'links')) <div class="p-4">{{ $promos->links() }}</div> @endif
    </div>
</div>
</x-fbr-pos-layout>
