@extends('fbr-pos.school-fees.layout')
@section('content')
<article class="card">
<h1>{{ __('pos.school_legacy_title') }}</h1>
<p class="notice">{{ __('pos.school_legacy_notice') }}</p>
<h2>{{ $transaction->invoice_number }}</h2>
<dl><dt>{{ __('pos.school_calendar_date') }}</dt><dd>{{ $transaction->created_at }}</dd><dt>{{ __('pos.school_legacy_mode') }}</dt><dd>{{ $transaction->invoice_mode }}</dd><dt>{{ __('pos.payment_method') }}</dt><dd>{{ $transaction->payment_method }}</dd><dt>{{ __('pos.school_legacy_status') }}</dt><dd>{{ $transaction->fbr_status }}</dd>
@if($transaction->fbr_invoice_number)<dt>{{ __('pos.school_legacy_fiscal_number') }}</dt><dd>{{ $transaction->fbr_invoice_number }}</dd>@endif
</dl>
<div class="scroll"><table><thead><tr><th>{{ __('pos.school_legacy_item') }}</th><th>{{ __('pos.school_legacy_quantity') }}</th><th>{{ __('pos.unit_price') }}</th><th>{{ __('pos.school_legacy_tax') }}</th><th>{{ __('pos.total') }}</th></tr></thead><tbody>
@foreach($transaction->items as $item)<tr><td>{{ $item->item_name }}</td><td>{{ $item->quantity }}</td><td>{{ $item->unit_price }}</td><td>{{ $item->tax_amount }}</td><td>{{ $item->total }}</td></tr>@endforeach
</tbody></table></div>
<dl><dt>{{ __('pos.subtotal') }}</dt><dd>{{ $transaction->subtotal }}</dd><dt>{{ __('pos.school_legacy_discount') }}</dt><dd>{{ $transaction->discount_amount }}</dd><dt>{{ __('pos.school_legacy_tax') }}</dt><dd>{{ $transaction->tax_amount }}</dd><dt>{{ __('pos.school_legacy_service_charge') }}</dt><dd>{{ $transaction->fbr_service_charge }}</dd><dt>{{ __('pos.total') }}</dt><dd>PKR {{ $transaction->total_amount }}</dd></dl>
<div class="actions no-print"><button type="button" onclick="window.print()">{{ __('pos.school_print_reprint') }}</button><a href="/fbr-pos/school-fees/legacy-retail{{ request()->boolean('unassigned') ? '?unassigned=1' : '' }}">{{ __('pos.school_legacy_title') }}</a></div>
</article>
@endsection
