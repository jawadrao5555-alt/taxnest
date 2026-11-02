<section data-report-summary>
 <div class="section-title">{{ __('pos.ra_summary_comparison') }}</div>
 <table class="data"><tbody>
  <tr><td>{{ __('pos.dc_total_invoices') }}</td><td class="r">{{ $presentation['count'] }}</td></tr>
  <tr><td>{{ __('pos.dc_total_revenue') }}</td><td class="r">PKR {{ number_format($presentation['total'],2) }}</td></tr>
  <tr><td>{{ __('pos.dc_sales_tax') }}</td><td class="r">PKR {{ number_format($presentation['tax'],2) }}</td></tr>
  <tr><td>{{ __('pos_history.returned') }}</td><td class="r">PKR {{ number_format($presentation['returns'],2) }}</td></tr>
  <tr><td>{{ __('pos_history.provisional',['amount'=>number_format($presentation['provisional'],2)]) }}</td><td></td></tr>
 </tbody></table>
 @if(!$presentation['complete'])<p role="status">{{ __('pos_history.partial') }} {{ __('pos_history.gap_explanation') }}</p>@endif
 <div class="section-title">{{ __('pos.dc_summary_streams') }}</div>
 <table class="data"><tbody>@foreach($presentation['streams'] as $stream=>$row)<tr><td>{{ strtoupper($stream) }}</td><td class="r">PKR {{ number_format($row['net'],2) }}</td></tr>@endforeach</tbody></table>
 <table style="width:100%;border-collapse:separate;border-spacing:6px 0"><tr>
 @foreach(['payments'=>__('pos.dc_summary_payments'),'order_types'=>__('pos.dcp_order_type_split')] as $key=>$label)
 <td style="width:50%;vertical-align:top"><div class="section-title">{{ $label }}</div><table class="data"><tbody>
  @foreach($presentation[$key] as $name=>$row)<tr><td>{{ $key==='payments' ? \App\Support\PosPaymentLabels::label($name) : ucwords(str_replace('_',' ',$name)) }}</td><td class="r">{{ number_format($row['net'],2) }}</td></tr>@endforeach
  <tr><td><strong>{{ __('pos.dc_total_revenue') }}</strong></td><td class="r"><strong>{{ number_format(collect($presentation[$key])->sum('net'),2) }}</strong></td></tr>
  <tr><td>{{ __('pos.delivery_charges') }} (included)</td><td class="r">{{ $presentation['delivery']===null ? '—' : number_format($presentation['delivery'],2) }}</td></tr>
 </tbody></table></td>
 @endforeach
 </tr></table>
</section>
