<section data-report-products>
 <div class="section-title">{{ __('pos_history.products') }}</div>
 <p>{{ __('pos_history.product_basis') }}</p>
 <table class="data"><thead><tr><th>{{ __('pos.product_col') }}</th><th>{{ __('pos_history.channel') }}</th><th class="c">{{ __('pos.receipt_qty') }}</th><th class="r">{{ __('pos_history.net') }}</th></tr></thead><tbody>
 @foreach(collect($presentation['products'])->groupBy('category')->sortKeys(SORT_NATURAL|SORT_FLAG_CASE) as $category=>$products)
  <tr><td colspan="4" style="font-weight:bold;background:#e5e7eb;color:#000">{{ $category }}</td></tr>
  @foreach($products->sortBy(fn($r)=>$r['name'].'|'.$r['size']) as $row)
   <tr><td>{{ $row['name'] }}{{ $row['size'] ? ' · '.$row['size'] : '' }}</td><td>{{ $row['channel'] }}</td><td class="c">{{ rtrim(rtrim(number_format($row['qty'],4),'0'),'.') }}</td><td class="r">{{ number_format($row['net'],2) }}</td></tr>
  @endforeach
 @endforeach
 </tbody></table><p>{{ __('pos_history.detail_note') }}</p>
</section>
