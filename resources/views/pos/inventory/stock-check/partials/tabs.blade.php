{{-- Shared inventory tab strip, with Stock Check as the active pill. --}}
<div class="flex flex-wrap gap-2 mb-6">
    <a href="{{ route('pos.inventory.dashboard') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700">{{ __('pos.dashboard') }}</a>
    <a href="{{ route('pos.inventory.stock') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700">{{ __('pos.stock_levels') }}</a>
    <a href="{{ route('pos.inventory.movements') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700">{{ __('pos.movements') }}</a>
    <a href="{{ route('pos.inventory.low-stock') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700">{{ __('pos.low_stock_alerts') }}</a>
    <a href="{{ route('pos.inventory.adjust') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700">{{ __('pos.adjust_stock') }}</a>
    <a href="{{ route('pos.inventory.stock-check.index') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-purple-600 text-white shadow-sm">{{ __('pos.stock_check') }}</a>
    @if($canTransfer ?? false)
    <a href="{{ route('pos.inventory.transfers') }}" class="px-4 py-2 text-xs font-semibold rounded-xl bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition shadow-sm border border-gray-200 dark:border-gray-700">{{ __('pos.branch_transfer') }}</a>
    @endif
</div>
