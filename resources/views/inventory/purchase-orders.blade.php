<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Purchase Orders</h2>
            <button onclick="document.getElementById('addPOModal').classList.remove('hidden')" class="inline-flex items-center px-3 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">
                <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New PO
            </button>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="mb-4 p-4 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300 rounded-lg">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="mb-4 p-4 bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300 rounded-lg">{{ session('error') }}</div>
            @endif

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
                <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                    <form method="GET" action="{{ route('purchase-orders.index') }}" class="flex flex-col sm:flex-row gap-3">
                        <input type="text" name="search" value="{{ $search }}" placeholder="Search PO number or supplier..."
                            class="flex-1 rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                        <select name="status" class="rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                            <option value="">All Status</option>
                            <option value="draft" {{ $statusFilter == 'draft' ? 'selected' : '' }}>Draft</option>
                            <option value="ordered" {{ $statusFilter == 'ordered' ? 'selected' : '' }}>Ordered</option>
                            <option value="received" {{ $statusFilter == 'received' ? 'selected' : '' }}>Received</option>
                            <option value="cancelled" {{ $statusFilter == 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                        </select>
                        <button type="submit" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">Filter</button>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-50 dark:bg-gray-900/50 text-gray-600 dark:text-gray-400 text-xs uppercase tracking-wider">
                            <tr>
                                <th class="px-4 py-3">PO Number</th>
                                <th class="px-4 py-3">Supplier</th>
                                <th class="px-4 py-3">Branch</th>
                                <th class="px-4 py-3">Order Date</th>
                                <th class="px-4 py-3">Items</th>
                                <th class="px-4 py-3 text-right">Total</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($purchaseOrders as $po)
                                @php
                                    $statusColors = [
                                        'draft' => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
                                        'ordered' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
                                        'partial' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
                                        'received' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
                                        'cancelled' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
                                    ];
                                @endphp
                                <tr class="{{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/50' : '' }}">
                                    <td class="px-4 py-3 font-mono font-medium text-gray-900 dark:text-gray-100">{{ $po->po_number }}</td>
                                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $po->supplier->name ?? '-' }}</td>
                                    <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-400">{{ $po->branch->name ?? 'Main' }}</td>
                                    <td class="px-4 py-3 text-xs text-gray-500">{{ $po->order_date->format('d M Y') }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-400">{{ $po->items->count() }}</td>
                                    <td class="px-4 py-3 text-right font-medium text-gray-700 dark:text-gray-300">Rs {{ number_format($po->total_amount, 0) }}</td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-0.5 text-xs font-medium rounded-full {{ $statusColors[$po->status] ?? 'bg-gray-100 text-gray-700' }}">
                                            {{ ucfirst($po->status) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-1.5">
                                            @if($po->status !== 'received' && $po->status !== 'cancelled')
                                                <form method="POST" action="{{ route('purchase-orders.receive', $po->id) }}" onsubmit="return confirm('Mark as received? This will add items to inventory.')" class="inline">
                                                    @csrf
                                                    <button type="submit" class="text-green-600 hover:text-green-800 dark:text-green-400" title="Receive Stock">
                                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                    </button>
                                                </form>
                                                <form method="POST" action="{{ route('purchase-orders.cancel', $po->id) }}" onsubmit="return confirm('Cancel this PO?')" class="inline">
                                                    @csrf
                                                    <button type="submit" class="text-red-600 hover:text-red-800 dark:text-red-400" title="Cancel">
                                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                    </button>
                                                </form>
                                            @else
                                                <span class="text-xs text-gray-400">{{ $po->received_date ? 'Received ' . $po->received_date->format('d M') : '' }}</span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">No purchase orders yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($purchaseOrders->hasPages())
                    <div class="p-4 border-t border-gray-200 dark:border-gray-700">{{ $purchaseOrders->links() }}</div>
                @endif
            </div>
        </div>
    </div>

    <div id="addPOModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-black/50" onclick="document.getElementById('addPOModal').classList.add('hidden')"></div>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl max-w-2xl w-full relative z-10 max-h-[90vh] overflow-y-auto">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center sticky top-0 bg-white dark:bg-gray-800 z-10">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">New Purchase Order</h3>
                    <button onclick="document.getElementById('addPOModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
                <form method="POST" action="{{ route('purchase-orders.store') }}" class="p-6 space-y-4" id="poForm">
                    @csrf
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Supplier *</label>
                            <select name="supplier_id" required class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                                <option value="">Select Supplier</option>
                                @foreach($suppliers as $sup)
                                    <option value="{{ $sup->id }}">{{ $sup->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Branch</label>
                            <select name="branch_id" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                                <option value="">Main / Default</option>
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Order Date *</label>
                            <input type="date" name="order_date" required value="{{ date('Y-m-d') }}" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Expected Delivery</label>
                            <input type="date" name="expected_date" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm">
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Items *</label>
                            <button type="button" onclick="addPOItem()" class="text-xs text-emerald-600 hover:text-emerald-700 font-medium">+ Add Item</button>
                        </div>
                        <div id="poItems" class="space-y-2">
                            <div class="grid grid-cols-12 gap-2 po-item">
                                <div class="col-span-5">
                                    <select name="items[0][product_id]" required class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-xs">
                                        <option value="">Product</option>
                                        @foreach($products as $p)
                                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-span-3">
                                    <input type="number" name="items[0][quantity]" required step="0.01" min="0.01" placeholder="Qty" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-xs">
                                </div>
                                <div class="col-span-3">
                                    <input type="number" name="items[0][unit_price]" required step="0.01" min="0" placeholder="Price" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-xs">
                                </div>
                                <div class="col-span-1 flex items-center justify-center">
                                    <button type="button" onclick="this.closest('.po-item').remove()" class="text-red-400 hover:text-red-600">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Notes</label>
                        <textarea name="notes" rows="2" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-sm"></textarea>
                    </div>

                    <div class="flex justify-end gap-3">
                        <button type="button" onclick="document.getElementById('addPOModal').classList.add('hidden')" class="px-4 py-2 bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-lg text-sm font-medium">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">Create PO</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        let poItemCount = 1;
        function addPOItem() {
            let idx = poItemCount++;
            let html = `<div class="grid grid-cols-12 gap-2 po-item">
                <div class="col-span-5">
                    <select name="items[${idx}][product_id]" required class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-xs">
                        <option value="">Product</option>
                        @foreach($products as $p)
                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-span-3">
                    <input type="number" name="items[${idx}][quantity]" required step="0.01" min="0.01" placeholder="Qty" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-xs">
                </div>
                <div class="col-span-3">
                    <input type="number" name="items[${idx}][unit_price]" required step="0.01" min="0" placeholder="Price" class="w-full rounded-lg border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100 shadow-sm text-xs">
                </div>
                <div class="col-span-1 flex items-center justify-center">
                    <button type="button" onclick="this.closest('.po-item').remove()" class="text-red-400 hover:text-red-600">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>`;
            document.getElementById('poItems').insertAdjacentHTML('beforeend', html);
        }
    </script>
</x-app-layout>
