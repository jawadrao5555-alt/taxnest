<x-pos-layout><div class="max-w-6xl mx-auto p-4">
@include('pos.partials.back-link')
<h1 class="text-xl font-bold mb-3">{{ $metric==='customers' ? __('pos.new_customers') : __('pos.orders_word') }} · {{ request('date') }}</h1>
<p class="text-sm text-gray-500 mb-4">{{ $rows->total() }} {{ $metric==='customers' ? __('pos.new_customers') : __('pos.orders_word') }}</p>
<div class="overflow-auto rounded-xl border"><table class="w-full text-sm"><thead><tr><th class="p-3 text-left">#</th><th class="text-left">{{ $metric==='customers' ? __('pos.customer_name') : __('pos.dc_report_no') }}</th><th>{{ __('pos.th_status') }}</th><th>{{ __('pos.amount_word') }}</th></tr></thead><tbody>
@forelse($rows as $row)<tr class="border-t"><td class="p-3">{{ $row->id }}</td><td>{{ $metric==='customers' ? $row->name : ($row->order_number ?? $row->invoice_number) }}</td><td class="text-center">{{ $row->status ?? '—' }}</td><td class="text-right p-3">{{ $metric==='customers' ? '—' : number_format($row->total_amount,2) }}</td></tr>@empty<tr><td colspan="4" class="p-4">{{ __('pos.no_data') }}</td></tr>@endforelse
</tbody></table></div>{{ $rows->links() }}
</div></x-pos-layout>
