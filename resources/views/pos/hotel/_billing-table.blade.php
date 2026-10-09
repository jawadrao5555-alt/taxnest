<table style="{{ $billingPdf ? 'width: 100%; table-layout: fixed;' : 'min-width: 65rem;' }}" class="w-full min-w-[65rem] text-sm" data-hotel-billing-table="1">
    <thead><tr class="text-left text-xs uppercase bg-gray-50 dark:bg-gray-800 text-gray-500">
        <th class="px-3 py-3">{{ __('pos.th_pos_invoice_no') }}</th>
        <th class="px-3 py-3">{{ __('pos.th_date') }}</th>
        <th class="px-3 py-3">{{ __('pos.hotel_guest') }} / {{ __('pos.hotel_room') }}</th>
        <th class="px-3 py-3">{{ __('pos.th_payment') }}</th>
        <th class="px-3 py-3 text-right" data-hotel-tax-detail @if(!$billingPdf) hidden @endif>{{ __('pos.subtotal') }}</th>
        <th class="px-3 py-3 text-right" data-hotel-tax-detail @if(!$billingPdf) hidden @endif>{{ __('pos.receipt_discount') }}</th>
        <th class="px-3 py-3 text-right" data-hotel-tax-detail @if(!$billingPdf) hidden @endif>{{ __('pos.th_tax_pct') }}</th>
        <th class="px-3 py-3 text-right">{{ __('pos.th_tax_amt') }}</th>
        <th class="px-3 py-3 text-right">{{ __('pos.total_word') }}</th>
        <th class="px-3 py-3 text-right">{{ __('hotel_billing.stay_paid') }}</th>
        <th class="px-3 py-3 text-right">{{ __('hotel_billing.stay_due') }}</th>
        <th class="px-3 py-3">{{ __('pos.pra_word') }}</th>
        @if(!$billingPdf)<th class="sticky right-0 z-20 bg-gray-50 dark:bg-gray-800 px-3 py-3 border-l min-w-36">{{ __('pos.action_col') }}</th>@endif
    </tr></thead>
    <tbody class="divide-y divide-gray-100">
    @forelse($transactions as $t)
        @php
            $row = $directory['rows'][$t->id] ?? null;
            $stay = $row['stay'] ?? null;
            $isReturn = $t->transaction_type === 'return';
            $sign = $isReturn && $billTypeFilter !== 'returns' ? -1 : 1;
            $iv = $itemValues[$t->id] ?? null;
            $rowTax = $taxRateFilter ? ($iv['item_tax'] ?? 0) : (float) $t->tax_amount;
            $rowTotal = $taxRateFilter ? ($iv['item_subtotal'] ?? 0) + $rowTax : (float) $t->total_amount;
        @endphp
        <tr data-hotel-invoice="{{ $t->id }}" class="{{ $isReturn ? 'text-rose-700' : '' }}">
            <td class="px-3 py-3 whitespace-nowrap">
                @if($billingPdf){{ $t->invoice_number }}@else
                <a class="font-semibold text-blue-700 underline" href="{{ $t->is_archived && $stay ? route('pos.hotel.bill-receipt', [$stay->id, $t->id]) : route('pos.transaction.show', $t->id) }}">{{ $t->invoice_number }}</a>
                @endif
                @if($isReturn)<span class="block text-xs">{{ __('pos.credit_note_badge') }}</span>@endif
                @if($stay)
                @if($billingPdf)<span class="block text-xs">{{ $stay->stay_number }}</span>@else
                <a data-tax-hotel-stay="{{ $t->id }}" href="{{ route('pos.hotel.stays.show', $stay->id) }}" class="block text-xs underline">{{ $stay->stay_number }}</a>
                @endif
                @endif
            </td>
            <td class="px-3 py-3 whitespace-nowrap">{{ $t->created_at->format('d M Y H:i') }}</td>
            <td class="px-3 py-3">{{ $stay?->guest_name ?? $t->customer_name }}<span class="block text-xs">{{ $stay?->room?->room_number }}</span></td>
            <td class="px-3 py-3">{{ \App\Support\PosPaymentLabels::label($t->payment_method) }}</td>
            <td class="px-3 py-3 text-right whitespace-nowrap" data-hotel-tax-detail @if(!$billingPdf) hidden @endif>{{ number_format($sign * ($taxRateFilter ? ($iv['item_subtotal'] ?? 0) : $t->subtotal), 2) }}</td>
            <td class="px-3 py-3 text-right whitespace-nowrap" data-hotel-tax-detail @if(!$billingPdf) hidden @endif>{{ number_format($taxRateFilter ? 0 : $sign * $t->discount_amount, 2) }}</td>
            <td class="px-3 py-3 text-right whitespace-nowrap" data-hotel-tax-detail @if(!$billingPdf) hidden @endif>{{ number_format($taxRateFilter ? ($taxRateFilter === 'exempt' ? 0 : (float) $taxRateFilter) : $t->tax_rate, 2) }}%</td>
            <td class="px-3 py-3 text-right whitespace-nowrap">{{ number_format($sign * $rowTax, 2) }}</td>
            <td class="px-3 py-3 text-right font-bold whitespace-nowrap">{{ number_format($sign * $rowTotal, 2) }}</td>
            <td class="px-3 py-3 text-right whitespace-nowrap">{{ $row && $row['money'] !== null ? number_format($row['money']['paid'], 2) : '—' }}</td>
            <td class="px-3 py-3 text-right whitespace-nowrap">{{ $row && $row['money'] !== null ? number_format($row['money']['balance'], 2) : '—' }}</td>
            <td class="px-3 py-3">
                {{ $t->pra_status === 'submitted' && $t->pra_invoice_number ? __('hotel_bill.accepted') : ($t->isLocalBill() ? __('hotel_bill.local') : __('hotel_preview.status_'.$t->pra_status)) }}
                <span class="block text-xs">{{ $t->pra_invoice_number }}</span>
            </td>
            @if(!$billingPdf)
            <td class="sticky right-0 z-10 bg-white dark:bg-gray-900 px-3 py-3 border-l whitespace-nowrap" data-tax-credit-actions="{{ $t->id }}">
                @if($stay)
                <a class="block rounded-lg border px-2 py-1 text-xs font-semibold text-blue-700" href="{{ route('pos.hotel.bill-receipt', [$stay->id, $t->id]) }}">{{ __('hotel_bill.issued_receipt') }}</a>
                @if($row['credit_id'])
                <a class="block mt-2 text-xs underline" href="{{ route('pos.hotel.bill-receipt', [$stay->id, $row['credit_id']]) }}">{{ __('hotel_billing.view_credit') }}</a>
                @elseif($row['can_credit'])
                <a class="inline-flex mt-2 rounded border border-rose-300 px-2 py-1 text-xs font-semibold text-rose-700" data-tax-credit-note="{{ $t->id }}" href="{{ route('pos.hotel.credit-notes', $stay->id) }}#bill-{{ $t->id }}">{{ __('hotel_credit.entry') }}</a>
                @endif
                @endif
            </td>
            @endif
        </tr>
    @empty
        <tr><td colspan="13" class="px-4 py-12 text-center text-gray-500">{{ __('pos.no_transactions_for_filters') }}</td></tr>
    @endforelse
    </tbody>
</table>
