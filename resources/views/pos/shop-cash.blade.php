<x-pos-layout>
<div class="max-w-5xl mx-auto p-4 sm:p-6">
    <a href="{{ route('pos.deliveries') }}" class="text-sm text-indigo-600">← Deliveries</a>
    <h1 class="text-xl font-bold mt-3">Receive delivery cash at shop</h1>
    <p class="text-sm text-gray-500 mt-2">Select unassigned final cash bills from one branch. This records money received; it does not finalize a provisional bill or mark delivery complete.</p>
    @if(session('success'))<p role="status" class="p-3 my-3 bg-green-50 text-green-800 rounded">{{ session('success') }}</p>@endif
    @if($errors->any())<p role="alert" class="p-3 my-3 bg-red-50 text-red-800 rounded">{{ $errors->first() }}</p>@endif
    <form method="GET" class="flex flex-wrap gap-3 my-4 items-end">
        <label class="text-xs">Business day from<input class="block rounded border-gray-300" type="date" name="from" value="{{ request('from') }}"></label>
        <label class="text-xs">Business day to<input class="block rounded border-gray-300" type="date" name="to" value="{{ request('to') }}"></label>
        <label class="text-xs">Bill reference<input class="block rounded border-gray-300" name="reference" maxlength="100" value="{{ request('reference') }}"></label>
        <button class="px-3 py-2 rounded bg-gray-100 dark:bg-gray-800">Find bills</button>
    </form>
    <form method="POST" action="{{ route('pos.deliveries.shop-cash') }}" x-data="{ selected: [], submitting: false, amounts: {{ Js::from($bills->getCollection()->mapWithKeys(fn($bill) => [$bill->id => (int) round(\App\Models\PosRider::remainingAmount($bill) * 100)])) }}, get cents() { return this.selected.reduce((total, id) => total + (this.amounts[id] || 0), 0); } }"
          @submit="if (submitting || !selected.length || !window.confirm('Record Rs. ' + (cents / 100).toFixed(2) + ' received for ' + selected.length + ' selected bills?')) { $event.preventDefault(); } else { submitting = true; }">
        @csrf
        <input type="hidden" name="receipt_key" value="{{ $receiptKey }}">
        <input type="hidden" name="expected_amount" :value="(cents / 100).toFixed(2)">
        <div class="overflow-x-auto my-4 border rounded">
            <table class="w-full text-sm text-left">
                <thead><tr class="border-b"><th class="p-3">Select</th><th class="p-3">Bill / ID</th><th class="p-3">Business day</th><th class="p-3">Delivery</th><th class="p-3 text-right">Cash due</th></tr></thead>
                <tbody>
                @forelse($bills as $bill)
                    <tr class="border-b">
                        <td class="p-3"><input type="checkbox" name="bill_ids[]" value="{{ $bill->id }}" x-model="selected" aria-label="Select bill {{ $bill->invoice_number }}"></td>
                        <td class="p-3">{{ $bill->invoice_number }}<span class="block text-xs text-gray-500">ID {{ $bill->id }} · Branch {{ $bill->branch_id ?? 'Legacy / unassigned' }}{{ $bill->is_archived ? ' · Archived' : '' }}</span></td>
                        <td class="p-3">{{ $bill->business_date ?? 'Not recorded' }}</td>
                        <td class="p-3">{{ $bill->delivery_status ?? 'Not marked delivered' }}</td>
                        <td class="p-3 text-right">Rs. {{ number_format(\App\Models\PosRider::remainingAmount($bill), 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-6 text-gray-500">No eligible unassigned cash bills in this scope. Card, draft/provisional, returned, rider-assigned and already-received bills are excluded.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <p class="font-semibold" aria-live="polite"><span x-text="selected.length">0</span> selected · Rs. <span x-text="(cents / 100).toFixed(2)">0.00</span></p>
        <button type="submit" :disabled="submitting || !selected.length" class="my-3 px-4 py-2 rounded bg-indigo-600 text-white disabled:opacity-40">Confirm cash received</button>
    </form>
    {{ $bills->links() }}
</div>
</x-pos-layout>
