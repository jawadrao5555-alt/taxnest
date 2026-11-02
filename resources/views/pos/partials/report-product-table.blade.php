<section data-report-products>
 <div class="section-title font-semibold">{{ __('pos_history.products') }}</div>
 <p>{{ __('pos_history.product_basis') }}</p>
 <table class="data w-full text-sm"><thead><tr><th class="text-left">{{ __('pos.product_col') }}</th><th class="text-left">{{ __('pos_history.channel') }}</th><th class="c text-center">{{ __('pos.receipt_qty') }}</th><th class="r text-right">{{ __('pos_history.net') }}</th></tr></thead><tbody>
 @foreach(collect($presentation['products'])->groupBy('category')->sortKeys(SORT_NATURAL|SORT_FLAG_CASE) as $category=>$products)
  <tr><td colspan="4" style="font-weight:bold;border-top:1px solid currentColor">{{ $category }}</td></tr>
  @foreach($products->sortBy(fn($r)=>$r['name'].'|'.$r['size']) as $row)
   <tr><td>{{ $row['name'] }}{{ $row['size'] ? ' · '.$row['size'] : '' }}</td><td>{{ $row['channel'] }}<br><small>{{ $row['basis'] }}</small></td><td class="c text-center">{{ rtrim(rtrim(number_format($row['qty'],4),'0'),'.') }}</td><td class="r text-right">{{ number_format($row['net'],2) }}</td></tr>
  @endforeach
 @endforeach
 </tbody></table>
</section>
