<x-pos-layout>
@include('pos.partials.report-screen-style')
<div class="pos-report-document max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <x-pos.report-header :title="__('pos.top_customers')">
        <x-slot:actions>
            <a href="{{ route('pos.reports', $scope) }}" class="text-sm font-semibold text-purple-700 underline dark:text-purple-300">{{ __('pos.back_word') }}</a>
        </x-slot:actions>
    </x-pos.report-header>
    @include('pos.partials.mode-tabs', ['baseUrl' => route('pos.reports'), 'showExempt' => true])
    <x-pos.report-panel class="p-5" data-pos-customer-rankings>
        <form method="GET" action="{{ route('pos.reports') }}" class="mb-4 flex flex-wrap items-end gap-3">
            <input type="hidden" name="top_customers" value="1">
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
            @foreach(['from', 'to'] as $date)
                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">
                    {{ __('pos.lbl_'.$date) }}
                    <input type="date" name="{{ $date }}" value="{{ $scope[$date] }}" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800">
                </label>
            @endforeach
            <label class="block min-w-0 text-xs font-medium text-gray-700 dark:text-gray-300">
                {{ __('pos.ph_search_customer_name') }}
                <input type="search" name="customer" value="{{ $search }}" maxlength="255"
                       class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800">
            </label>
            <button type="submit" class="rounded-lg bg-purple-600 px-4 py-2 text-sm font-semibold text-white hover:bg-purple-700">{{ __('pos.apply_filters') }}</button>
        </form>
        @if($customers->isEmpty())
            <p>{{ __('pos.no_matches') }}</p>
        @else
            @include('pos.partials.report-customer-rankings', ['rankings' => $customers, 'firstRank' => $customers->firstItem()])
        @endif
        {{ $customers->links() }}
    </x-pos.report-panel>
</div>
</x-pos-layout>
