<x-pos-layout>
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex items-center justify-between mb-5">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.return_refund') }}</h1>
            <p class="text-sm text-gray-500 mt-1">{{ __('pos.original_invoice_colon') }} <strong>{{ $original->invoice_number }}</strong> ({{ $original->created_at->format('d M Y H:i') }})</p>
            {{-- Task 678: notice uses the SAME eligibility predicate as the
                 service — PRA credit note vs local return ("PRA ko report
                 nahi hoga"), so the cashier always knows before processing. --}}
            @if($praEligible ?? $original->pra_invoice_number)
                <p class="text-xs text-emerald-600 dark:text-emerald-400 mt-1">{{ __('pos.return_will_report_pra') }}</p>
            @elseif($localParent ?? false)
                <p class="text-xs text-gray-500 mt-1">{{ __('pos.return_stays_local_bill') }}</p>
            @else
                <p class="text-xs text-gray-500 mt-1">{{ __('pos.return_stays_local') }}</p>
            @endif
        </div>
        <a href="{{ route('pos.transaction.show', $original->id) }}" class="text-sm text-blue-600 hover:underline">← {{ __('pos.cancel') }}</a>
    </div>

    @if(session('error'))<div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 text-red-700 dark:text-red-300 px-4 py-3 rounded-xl mb-4 text-sm font-medium">{{ session('error') }}</div>@endif

    {{-- Rider cash reconciliation notice (Task 570): a returned delivery may
         already have partial cash deposited by the rider — that money is in the
         drawer; the refund below goes OUT of the drawer. Surface it so day-close
         cash reconciliation is understood, never silently lost. --}}
    @if((float) ($original->rider_partial_paid ?? 0) > 0)
    <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 text-amber-800 dark:text-amber-300 px-4 py-3 rounded-xl mb-4 text-sm">
        {{ __('pos.return_rider_partial_notice', ['amount' => number_format((float) $original->rider_partial_paid)]) }}
    </div>
    @endif

    <form method="POST" action="{{ route('pos.transaction.return', $original->id) }}" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-6">
        @csrf
        <h3 class="font-bold mb-3 text-gray-900 dark:text-white">{{ __('pos.select_items_to_return') }}</h3>
        <table class="w-full text-sm mb-5 table-cards">
            <thead class="bg-gray-50 dark:bg-gray-800 text-left">
                <tr>
                    <th class="px-3 py-2">{{ __('pos.th_item') }}</th>
                    <th>{{ __('pos.th_sold_qty') }}</th>
                    <th>{{ __('pos.th_returned') }}</th>
                    <th>{{ __('pos.th_unit_price') }}</th>
                    <th>{{ __('pos.th_return_qty') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($original->items as $it)
                    @php $remaining = round((float) $it->quantity - (float) ($it->returned_quantity ?? 0), 3); @endphp
                    <tr class="border-t border-gray-100 dark:border-gray-700 {{ $remaining <= 0 ? 'opacity-50' : '' }}">
                        <td class="px-3 py-2 font-semibold text-gray-900 dark:text-white">{{ $it->item_name }}</td>
                        <td class="text-gray-700 dark:text-gray-300">{{ rtrim(rtrim(number_format($it->quantity, 3), '0'), '.') }}</td>
                        <td class="text-gray-700 dark:text-gray-300">{{ rtrim(rtrim(number_format((float) ($it->returned_quantity ?? 0), 3), '0'), '.') }}</td>
                        <td class="text-gray-700 dark:text-gray-300">Rs {{ number_format($it->unit_price, 2) }}</td>
                        <td>
                            <input type="hidden" name="items[{{ $loop->index }}][item_id]" value="{{ $it->id }}">
                            {{-- Owner rule (14 Aug 2026): full remaining qty PREFILLED — most
                                 returns are whole-bill; cashier only edits for partial returns. --}}
                            <input type="number" name="items[{{ $loop->index }}][return_qty]" value="{{ $remaining > 0 ? rtrim(rtrim(number_format($remaining, 3, '.', ''), '0'), '.') : 0 }}" min="0" max="{{ $remaining }}" step="0.001" {{ $remaining <= 0 ? 'disabled' : '' }} class="w-24 border border-gray-300 dark:border-gray-600 rounded px-2 py-1 text-sm dark:bg-gray-800 dark:text-white">
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="grid sm:grid-cols-2 gap-4 border-t border-gray-200 dark:border-gray-700 pt-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.refund_method_req') }}</label>
                {{-- PRA POS has no khata/credit bills — cash or card refund only. --}}
                <select name="refund_method" required class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 dark:bg-gray-800 dark:text-white">
                    <option value="cash">{{ __('pos.cash_refund') }}</option>
                    <option value="card">{{ __('pos.card_bank_refund') }}</option>
                </select>
            </div>
            <div class="flex items-end">
                <button type="submit" onclick="return confirm(@js(__('pos.process_refund_q')))" class="bg-red-600 text-white rounded-lg px-5 py-2.5 font-semibold hover:bg-red-700 w-full">{{ __('pos.process_refund') }}</button>
            </div>
        </div>
    </form>
</div>
</x-pos-layout>
