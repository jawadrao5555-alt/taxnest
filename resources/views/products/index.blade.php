<x-app-layout>
    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Products</h2>
                <a href="/products/create" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition shadow-sm">
                    + New Product
                </a>
            </div>

            @if(session('success'))
                <div class="mb-4 p-4 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-xl">
                    {{ session('success') }}
                </div>
            @endif

            <div class="mb-6">
                <form method="GET" action="/products" class="flex flex-col sm:flex-row gap-3">
                    <input type="text" name="search" value="{{ $search }}" placeholder="Search by name, HS code, PCT code, or schedule type..." class="flex-1 rounded-lg bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-700 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 text-sm">
                    <button type="submit" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">Search</button>
                    @if($search)
                    <a href="/products" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-300 transition">Clear</a>
                    @endif
                </form>
            </div>

            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 overflow-hidden">
                <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">HS Code</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Schedule</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tax</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Price</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tax Amt</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">MRP</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">SRO</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse($products as $product)
                            @php
                                $taxAmt = $product->default_price * $product->default_tax_rate / 100;
                                $totalWithTax = $product->default_price + $taxAmt;
                                $badges = [
                                    'standard' => 'bg-gray-100 text-gray-800',
                                    'reduced' => 'bg-blue-100 text-blue-800',
                                    '3rd_schedule' => 'bg-amber-100 text-amber-800',
                                    'exempt' => 'bg-green-100 text-green-800',
                                    'zero_rated' => 'bg-purple-100 text-purple-800',
                                ];
                                $badgeClass = $badges[$product->schedule_type] ?? 'bg-gray-100 text-gray-800';
                            @endphp
                            <tr class="even:bg-gray-50/50 dark:even:bg-gray-800/50 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
                                <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">{{ $product->name }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400 font-mono">{{ $product->hs_code }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    @if($product->schedule_type)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $badgeClass }}">
                                            {{ str_replace('_', ' ', ucfirst($product->schedule_type)) }}
                                        </span>
                                    @else
                                        <span class="text-gray-400 text-sm">-</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm font-medium {{ $product->default_tax_rate > 0 ? 'text-amber-600' : 'text-gray-600' }}">{{ intval($product->default_tax_rate) }}%</td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">PKR {{ number_format($product->default_price, 2) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-red-600 font-medium">PKR {{ number_format($taxAmt, 2) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-emerald-700 dark:text-emerald-400 font-bold">PKR {{ number_format($totalWithTax, 2) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                    @if($product->mrp)
                                        <span class="text-purple-700 font-medium">PKR {{ number_format($product->mrp, 2) }}</span>
                                    @else
                                        <span class="text-gray-400">-</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                    @if($product->sro_reference)
                                        <span class="text-xs" title="{{ $product->sro_reference }}">{{ Str::limit($product->sro_reference, 15) }}</span>
                                        @if($product->serial_number)
                                            <span class="text-xs text-blue-600 ml-1">#{{ $product->serial_number }}</span>
                                        @endif
                                    @else
                                        <span class="text-gray-400">-</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    @if($product->is_active)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">Active</span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">Inactive</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-right text-sm space-x-2">
                                    <a href="/products/{{ $product->id }}/edit" class="text-emerald-600 hover:text-emerald-800 font-medium">Edit</a>
                                    <form method="POST" action="/products/{{ $product->id }}/toggle" class="inline">
                                        @csrf
                                        <button type="submit" class="text-{{ $product->is_active ? 'red' : 'emerald' }}-600 hover:text-{{ $product->is_active ? 'red' : 'emerald' }}-800 font-medium">
                                            {{ $product->is_active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </form>
                                    <form method="POST" action="/products/{{ $product->id }}" class="inline" onsubmit="return confirm('Are you sure you want to permanently delete &quot;{{ addslashes($product->name) }}&quot;? This action cannot be undone.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-600 hover:text-red-800 font-medium">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="px-6 py-12 text-center text-gray-500">
                                    No products found. <a href="/products/create" class="text-emerald-600 hover:text-emerald-800 font-medium">Create your first product</a>.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>

            <div class="mt-4">
                {{ $products->links() }}
            </div>
        </div>
    </div>
</x-app-layout>
