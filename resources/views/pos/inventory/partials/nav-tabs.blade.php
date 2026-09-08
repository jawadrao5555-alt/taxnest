{{-- Shared NestPOS inventory tab strip. Pass $active: dashboard|stock|movements|low-stock|adjust|transfers|stock-check|ingredients|recipes|master --}}
@php
    $invActive = $active ?? '';
    $tabOn = 'px-4 py-2 text-xs font-semibold rounded-xl bg-purple-600 text-white shadow-sm';
    $tabOff = 'px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700';
    $navCompany = $company ?? \App\Models\Company::find(app('currentCompanyId'));
    $recipesNav = \App\Services\PosFeatureService::moduleAvailable($navCompany, 'recipes');
    $lowStockCount = (int) ($lowStockCount ?? (($lowStockItems ?? collect())->count() ?? 0));
@endphp
<div class="flex flex-wrap gap-2 mb-6">
    <a href="{{ route('pos.inventory.dashboard') }}" class="{{ $invActive === 'dashboard' ? $tabOn : $tabOff }}">{{ __('pos.dashboard') }}</a>
    <a href="{{ route('pos.inventory.stock') }}" class="{{ $invActive === 'stock' ? $tabOn : $tabOff }}">{{ __('pos.stock_levels') }}</a>
    @if($recipesNav)
    <a href="{{ route('pos.restaurant.ingredients') }}" class="{{ $invActive === 'ingredients' ? $tabOn : $tabOff }}">{{ __('pos.ingredients') }}</a>
    <a href="{{ route('pos.restaurant.recipes') }}" class="{{ $invActive === 'recipes' ? $tabOn : $tabOff }}">{{ __('pos.recipes_bom') }}</a>
    @endif
    <a href="{{ route('pos.inventory.movements') }}" class="{{ $invActive === 'movements' ? $tabOn : $tabOff }}">{{ __('pos.movements') }}</a>
    <a href="{{ route('pos.inventory.low-stock') }}" class="{{ $invActive === 'low-stock' ? $tabOn : $tabOff }} {{ $lowStockCount > 0 && $invActive !== 'low-stock' ? 'relative' : '' }}">
        {{ __('pos.low_stock_alerts') }}
        @if($lowStockCount > 0)
        <span class="ml-1 inline-flex items-center justify-center w-5 h-5 text-[10px] font-bold bg-red-500 text-white rounded-full">{{ $lowStockCount }}</span>
        @endif
    </a>
    <a href="{{ route('pos.inventory.adjust') }}" class="{{ $invActive === 'adjust' ? $tabOn : $tabOff }}">{{ __('pos.adjust_stock') }}</a>
    @if($canTransfer ?? false)
    <a href="{{ route('pos.inventory.transfers') }}" class="{{ $invActive === 'transfers' ? $tabOn : $tabOff }}">{{ __('pos.branch_transfer') }}</a>
    @endif
    <a href="{{ route('pos.inventory.stock-check.index') }}" class="{{ $invActive === 'stock-check' ? $tabOn : $tabOff }}">{{ __('pos.stock_check') }}<x-new-badge feature="stock_check" class="ml-1" /></a>
    <a href="{{ route('pos.inventory-master') }}" class="{{ $invActive === 'master' ? $tabOn : $tabOff }}">{{ __('pos.inventory_master') }}</a>
</div>
