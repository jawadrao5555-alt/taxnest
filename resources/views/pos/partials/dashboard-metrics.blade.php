@php
 $metricDate=$todayKhata['date'] ?? $todayKhata['business_date'] ?? \App\Services\PosBusinessDay::current((int)$company->id);
 $metricCashier=$dashCashierId ?? $cashierFilter ?? 'all';
 $reportLinksAllowed = auth('pos')->user()?->posCustomAllows('reports') !== false;
 $metricSource=$dashboardOrderSource ?? 'bills';
 $metricLinks=[
  ['revenue',__('pos.todays_revenue'),'Rs. '.number_format($todayTotalSale ?? $todaySales ?? $todayStats->revenue ?? 0, 2),route('pos.dashboard.detail',['metric'=>'revenue','date'=>$metricDate,'cashier'=>$metricCashier])],
  ['orders',__('pos.orders_word'),number_format($todayOrders ?? $todayStats->count ?? 0),route('pos.dashboard.detail',['metric'=>'orders','date'=>$metricDate,'cashier'=>$metricCashier,'source'=>$metricSource])],
  ['customers',__('pos.new_customers'),number_format($newCustomersToday ?? 0),route('pos.dashboard.detail',['metric'=>'customers','date'=>$metricDate,'cashier'=>$metricCashier])],
 ];
 if(\App\Services\PosFeatureService::moduleRelevant($company,'tables') && auth('pos')->user()?->posCustomAllows('tables') !== false)$metricLinks[]=['tables',__('pos.tables_word'),($occupiedTables ?? 0).'/'.($totalTables ?? 0),route('pos.restaurant.tables')];
@endphp
<section data-dashboard-metrics class="mb-4">
 <p class="text-xs text-gray-500 mb-2">{{ __('pos.business_day_label') }}: {{ $metricDate }} · {{ __('pos.tables_word') }}: Live</p>
 <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
  @foreach($metricLinks as [$key,$label,$value,$href])
  <x-pos.report-stat :label="$label" :value="$value" :href="$key === 'tables' || $reportLinksAllowed ? $href : null" :data-dashboard-metric="$key" />
  @endforeach
 </div>
</section>
