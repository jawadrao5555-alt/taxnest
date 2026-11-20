<x-admin-layout>
    <section class="p-4 sm:p-6 max-w-3xl mx-auto space-y-5 text-gray-100">
        <a class="text-indigo-300 underline" href="{{ route('saas.admin.package-requests.index') }}">Signup package requests</a>
        <h1 class="text-2xl font-bold">{{ $sale->company?->name ?? 'Company #' . $sale->company_id }}</h1>
        <div class="rounded-lg bg-gray-900 border border-gray-700 p-4">
            <p class="font-semibold">{{ $sale->plan_name }} · {{ \App\Models\Subscription::getCycleLabel($sale->billing_cycle) }}</p>
            <p>Quoted amount <strong>Rs {{ number_format($sale->quoted_amount, 2) }}</strong></p>
            <p class="text-sm text-gray-300">{{ $sale->quote_source === 'signup' ? 'Quoted at signup' : 'Quoted at admin review' }} · {{ $sale->created_at?->format('d M Y') }} · {{ ucfirst($sale->status) }}</p>
        </div>
        @if($sale->status === 'pending')
            <form class="rounded-lg bg-gray-900 border border-gray-700 p-4 space-y-4" method="POST" action="{{ route('saas.admin.package-requests.confirm', $sale) }}">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
                <fieldset>
                    <legend class="font-medium">How was payment received?</legend>
                    <div class="flex gap-6 mt-2">
                        @foreach(['cash' => 'Cash', 'card' => 'Card'] as $method => $label)
                            <label><input type="radio" name="payment_method" value="{{ $method }}" required @checked(old('payment_method') === $method)> {{ $label }}</label>
                        @endforeach
                    </div>
                </fieldset>
                <label class="block">Amount received (PKR)
                    <input class="block w-full rounded bg-gray-800" type="number" name="verified_received_amount" min="0.01" step="0.01" value="{{ old('verified_received_amount', $sale->quoted_amount) }}" required>
                </label>
                <label class="block">Payment received on
                    <input class="block w-full rounded bg-gray-800" type="date" name="payment_date" max="{{ today()->toDateString() }}" value="{{ old('payment_date', today()->toDateString()) }}" required>
                </label>
                <label class="block">Payment reference (optional)
                    <input class="block w-full rounded bg-gray-800" type="text" name="reference" maxlength="120" value="{{ old('reference') }}">
                </label>
                <button type="submit" class="rounded bg-emerald-700 px-4 py-2 font-medium">Confirm company and record payment</button>
            </form>
            <details class="rounded-lg bg-gray-900 border border-gray-700 p-4">
                <summary class="cursor-pointer">Reject package request</summary>
                <form class="space-y-3 mt-3" method="POST" action="{{ route('saas.admin.package-requests.reject', $sale) }}">
                    @csrf
                    <label class="block">Reason for rejection
                        <textarea class="block w-full rounded bg-gray-800" name="rejection_reason" maxlength="1000" required>{{ old('rejection_reason') }}</textarea>
                    </label>
                    <button class="rounded bg-red-800 px-4 py-2" type="submit">Reject package request</button>
                </form>
            </details>
        @elseif($sale->status === 'rejected')
            <p>Reason for rejection: {{ $sale->rejection_reason }}</p>
        @elseif($sale->invoice)
            @foreach($sale->invoice->payments as $payment)
                <div class="flex flex-wrap gap-4">
                    <a class="text-indigo-300 underline" href="{{ route('saas.admin.billing.documents.receipt', [$sale->company_id, $payment->id]) }}">Download receipt PDF</a>
                    <a class="text-indigo-300 underline" href="{{ route('saas.admin.billing.documents.receipt', [$sale->company_id, $payment->id, 'print' => 1]) }}" target="_blank" rel="noopener">Print receipt</a>
                </div>
            @endforeach
        @endif
    </section>
</x-admin-layout>
