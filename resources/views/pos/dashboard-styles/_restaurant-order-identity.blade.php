{{-- A restaurant order and its eventual invoice are separate records.  Keep this
     shared across dashboard styles so an order id is never presented as a receipt. --}}
@if($order instanceof \App\Models\RestaurantOrder)
    <div>
        <p class="text-[11px] font-bold text-current">Order #{{ $order->order_number }}</p>
        @if($order->posTransaction && $order->posTransaction->status === 'completed')
            <a href="{{ route('pos.transaction.show', $order->pos_transaction_id) }}" class="block text-[9px] font-semibold text-purple-600 hover:underline">
                Invoice {{ $order->posTransaction->invoice_number }}
            </a>
        @else
            <p class="text-[9px] text-gray-400">Not finalized</p>
        @endif
    </div>
@else
    {{-- Some shared dashboard styles also render retail PosTransaction rows. --}}
    <span class="text-[11px] font-bold text-current">{{ $order->invoice_number ?? ('#' . $order->id) }}</span>
@endif