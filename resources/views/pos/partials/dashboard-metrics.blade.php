@php
 $metricDate=$todayKhata['business_date'] ?? \App\Services\PosBusinessDay::current((int)$company->id);
 $metricCashier=$cashierFilter ?? 'all';
 $metricSource=$dashboardOrderSource ?? 'bills';
 $metricLinks=[
  ['revenue',__('pos.todays_revenue'),'Rs. '.number_format($todayTotalSale ?? $todaySales ?? $todayStats->revenue ?? 0),route('pos.reports',['tab'=>'combined','from'=>$metricDate,'to'=>$metricDate,'cashier'=>$metricCashier])],
  ['orders',__('pos.orders_word'),number_format($todayOrders ?? $todayStats->count ?? 0),route('pos.dashboard.detail',['metric'=>'orders','date'=>$metricDate,'cashier'=>$metricCashier,'source'=>$metricSource])],
  ['customers',__('pos.new_customers'),number_format($newCustomersToday ?? 0),route('pos.dashboard.detail',['metric'=>'customers','date'=>$metricDate,'cashier'=>$metricCashier])],
 ];
 if(\App\Services\PosFeatureService::moduleRelevant($company,'tables'))$metricLinks[]=['tables',__('pos.tables_word'),($occupiedTables ?? 0).'/'.($totalTables ?? 0),route('pos.restaurant.tables')];
@endphp
<section data-dashboard-metrics class="mb-4">
 <p class="text-xs text-gray-500 mb-2">{{ __('pos.business_day_label') }}: {{ $metricDate }} · {{ __('pos.tables_word') }}: Live</p>
 <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
  @foreach($metricLinks as [$key,$label,$value,$href])
  <a data-dashboard-metric="{{ $key }}" href="{{ $href }}" class="block rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4 focus:ring-2 focus:ring-teal-600 hover:shadow-md">
   <span class="text-xs font-semibold text-gray-600 dark:text-gray-300">{{ $label }}</span><strong class="block text-xl mt-2 text-gray-900 dark:text-white">{{ $value }}</strong>
  </a>
  @endforeach
 </div>
</section>
