<x-fbr-pos-layout>
<div class="max-w-6xl mx-auto">
    @include('fbr-pos.partials.back-link')
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.products_word') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.manage_products_tax_config') }}</p>
        </div>
        <a href="{{ route('fbrpos.products.create') }}" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition shadow-sm">
            {{ __('pos.plus_new_product') }}
        </a>
    </div>

    @if(session('success'))
    <div class="mb-4 p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 rounded-xl text-sm">
        {{ session('success') }}
    </div>
    @endif

    <div class="mb-6">
        <form method="GET" action="{{ route('fbrpos.products') }}" class="flex flex-col sm:flex-row gap-3">
            <input type="text" name="search" value="{{ $search }}" placeholder="{{ __('pos.search_name_hs_ph') }}"
                class="flex-1 rounded-lg bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-700 dark:text-white shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm">
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition">{{ __('pos.search_word') }}</button>
            @if($search)
            <a href="{{ route('fbrpos.products') }}" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg text-sm font-medium hover:bg-gray-300 transition">{{ __('pos.clear_word') }}</a>
            @endif
        </form>
    </div>

    @if(auth('fbrpos')->user() && auth('fbrpos')->user()->role === 'company_admin')
    {{-- ═══ SALE-SCREEN VISIBILITY (bulk hide/show — admin only) ═══ --}}
    <div class="flex flex-wrap items-center gap-2 mb-4 p-3 rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm">
        <span class="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('pos.sale_screen_visibility') }}</span>
        <form method="POST" action="{{ route('fbrpos.products.bulk-sale') }}" class="inline"
              onsubmit="return confirm(@js(__('pos.confirm_hide_all_products')))">
            @csrf
            <input type="hidden" name="action" value="hide">
            <button type="submit" class="px-3 py-2 rounded-lg bg-gray-700 hover:bg-gray-800 text-white text-xs font-semibold">{{ __('pos.hide_all_btn') }}</button>
        </form>
        <form method="POST" action="{{ route('fbrpos.products.bulk-sale') }}" class="inline"
              onsubmit="return confirm(@js(__('pos.confirm_show_all_products')))">
            @csrf
            <input type="hidden" name="action" value="show">
            <button type="submit" class="px-3 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white text-xs font-semibold">{{ __('pos.show_all_btn') }}</button>
        </form>
        <span class="text-[11px] text-gray-400">{{ __('pos.hidden_products_hint') }}</span>
    </div>
    @endif

    <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 overflow-hidden">
        <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 table-cards">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('pos.product_col') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('pos.hs_code_col') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('pos.tax_type_col') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('pos.tax_rate_col') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('pos.price_col') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('pos.status_col') }}</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('pos.actions_col') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse($products as $product)
                @php
                    $taxBadges = [
                        'taxable' => ['bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300', intval($product->default_tax_rate) . '%'],
                        'exempt' => ['bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300', __('pos.exempt_word')],
                        'custom' => ['bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300', intval($product->default_tax_rate) . '%'],
                    ];
                    $taxType = $product->tax_type ?? 'taxable';
                    $badge = $taxBadges[$taxType] ?? $taxBadges['taxable'];
                @endphp
                <tr class="even:bg-gray-50/50 dark:even:bg-gray-800/50 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">{{ $product->name }}</td>
                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400 font-mono">{{ $product->hs_code ?: '-' }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold {{ $badge[0] }}">
                            {{ $badge[1] }}
                        </span>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium {{ $taxType === 'exempt' ? 'text-green-600' : 'text-amber-600' }}">
                        {{ $taxType === 'exempt' ? '0%' : intval($product->default_tax_rate) . '%' }}
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">PKR {{ number_format($product->default_price, 2) }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        @if($product->is_active)
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">{{ __('pos.active_word') }}</span>
                        @else
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">{{ __('pos.inactive_word') }}</span>
                        @endif
                        @if(!($product->show_on_sale ?? true))
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-300 ml-1" title="{{ __('pos.title_hidden_from_sale') }}">{{ __('pos.hidden_word') }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap text-right text-sm space-x-2">
                        <a href="{{ route('fbrpos.products.edit', $product->id) }}" class="text-blue-600 hover:text-blue-800 font-medium">{{ __('pos.edit') }}</a>
                        <form method="POST" action="{{ route('fbrpos.products.toggle-sale', $product->id) }}" class="inline">
                            @csrf
                            <button type="submit" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 font-medium" title="{{ ($product->show_on_sale ?? true) ? __('pos.title_hide_from_sale_grid') : __('pos.title_show_on_sale_grid') }}">
                                {{ ($product->show_on_sale ?? true) ? __('pos.hide_word') : __('pos.show_word') }}
                            </button>
                        </form>
                        <form method="POST" action="{{ route('fbrpos.products.toggle', $product->id) }}" class="inline">
                            @csrf
                            <button type="submit" class="text-{{ $product->is_active ? 'red' : 'green' }}-600 hover:text-{{ $product->is_active ? 'red' : 'green' }}-800 font-medium">
                                {{ $product->is_active ? __('pos.deactivate') : __('pos.activate') }}
                            </button>
                        </form>
                        <form method="POST" action="{{ route('fbrpos.products.destroy', $product->id) }}" class="inline" onsubmit="return confirm(@js(__('pos.confirm_delete_named', ['name' => $product->name])))">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-red-600 hover:text-red-800 font-medium">{{ __('pos.delete') }}</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                        {{ __('pos.no_products_yet_short') }} <a href="{{ route('fbrpos.products.create') }}" class="text-blue-600 hover:text-blue-800 font-medium">{{ __('pos.add_your_first_product') }}</a>.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    @if($products->hasPages())
    <div class="mt-4">{{ $products->links() }}</div>
    @endif
</div>
</x-fbr-pos-layout>
