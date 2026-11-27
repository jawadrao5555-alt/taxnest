<x-pos-layout>
@include('pos.partials.report-screen-style')
<div class="pos-report-document max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    @php
        $salesScope = ['tab' => $tab, 'cashier' => $selectedCashier, 'from' => $itemSales['from'], 'to' => $itemSales['to']];
        $pdfFilters = $salesScope + array_filter([
            'category' => $itemSales['category'],
            'item' => $itemSales['item'],
        ], fn ($value) => $value !== '');
    @endphp
    <x-pos.report-header :title="__('pos.top_selling_items')">
        <x-slot:actions>
            @if($canExportsPlan)
                <a href="{{ route('pos.reports.items-pdf', $pdfFilters) }}" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">{{ __('pos.download_pdf') }}</a>
            @endif
            <a href="{{ route('pos.reports', $salesScope) }}" class="text-sm font-semibold text-purple-700 underline dark:text-purple-300">{{ __('pos.back_word') }}</a>
        </x-slot:actions>
    </x-pos.report-header>
    @include('pos.partials.mode-tabs', ['baseUrl' => route('pos.reports'), 'showExempt' => true])
    <x-pos.report-panel class="mb-6 p-5" data-pos-item-sales>
        <form method="GET" action="{{ route('pos.reports') }}" class="mb-4 flex flex-wrap items-end gap-3">
            <input type="hidden" name="top_items" value="1">
            <input type="hidden" name="tab" value="{{ $tab }}">
            @if(!$isCashier)
                <label class="block min-w-0 text-xs font-medium text-gray-700 dark:text-gray-300">
                    {{ __('pos.lbl_view_sales_by') }}
                    <select name="cashier" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800">
                        <option value="all">{{ __('pos.opt_all_company_sales') }}</option>
                        @foreach($teamMembers as $member)
                            <option value="{{ $member->id }}" @selected((string) $selectedCashier === (string) $member->id)>{{ $member->name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">
                {{ __('pos.lbl_from') }}
                <input type="date" name="from" value="{{ $itemSales['from'] }}"
                       class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800">
            </label>
            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">
                {{ __('pos.lbl_to') }}
                <input type="date" name="to" value="{{ $itemSales['to'] }}"
                       class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800">
            </label>
            <label class="block min-w-0 text-xs font-medium text-gray-700 dark:text-gray-300">
                {{ __('pos.th_category') }}
                <select name="category" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800">
                    <option value="">{{ __('pos.all_categories') }}</option>
                    @if($itemSales['category'] !== '' && !$itemSales['categories']->contains($itemSales['category']))
                        <option value="{{ $itemSales['category'] }}" selected>{{ $itemSales['category'] }}</option>
                    @endif
                    @foreach($itemSales['categories'] as $category)
                        <option value="{{ $category }}" @selected($itemSales['category'] === $category)>{{ $category }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block min-w-0 text-xs font-medium text-gray-700 dark:text-gray-300">
                {{ __('pos.item_name') }}
                <input type="search" name="item" value="{{ $itemSales['item'] }}" maxlength="255"
                       class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800">
            </label>
            <button type="submit" class="rounded-lg bg-purple-600 px-4 py-2 text-sm font-semibold text-white hover:bg-purple-700">{{ __('pos.apply_filters') }}</button>
        </form>
        <div class="overflow-x-auto">
            @include('pos.partials.report-item-sales-table')
        </div>
    </x-pos.report-panel>
</div>
</x-pos-layout>
