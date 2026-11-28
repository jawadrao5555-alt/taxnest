<x-admin-layout>
<div class="tn-content-rail p-4 sm:p-6 space-y-5">
    <a href="{{ route('saas.admin.companies.show', $company->id) }}" class="text-indigo-400 underline">{{ $company->name }}</a>
    <h1 class="text-2xl font-bold text-white">Package receipts</h1>
    @if(session('success')) <p role="status" class="text-emerald-300">{{ session('success') }}</p> @endif
    @if($errors->any()) <ul role="alert" class="text-red-300">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul> @endif

    @forelse($terms as $term)
        @php
            $termCharges = $charges->get($term->id, collect());
            $termProofs = $proofs->get($term->id, collect());
            $hasUnallocatedProof = $termProofs->contains(fn ($proof) => !$proof->admin_allocated_payment_id);
            $plan = $term->entitlementPlan();
            $openCharge = $termCharges->count() === 1 ? $termCharges->first() : null;
            $canSettleCharge = $openCharge
                && in_array($openCharge->status, ['pending', 'unpaid'], true)
                && !$openCharge->paid_at && !$openCharge->document_snapshot && !$openCharge->document_number
                && $openCharge->payments->isEmpty() && $openCharge->amount > 0
                && $term->final_price !== null && number_format($openCharge->amount, 2, '.', '') === $term->final_price;
            $canRecord = $termCharges->isEmpty() || $canSettleCharge;
        @endphp
        <section class="space-y-3 rounded-lg border border-gray-700 bg-gray-900 p-4">
            <h2 class="text-lg font-semibold text-white">{{ $plan?->name ?? 'Package name unavailable' }} &middot; Term #{{ $term->id }}</h2>
            <p class="text-sm text-gray-300">
                {{ $term->start_date?->format('d M Y') ?? 'Start unrecorded' }} – {{ $term->end_date?->format('d M Y') ?? 'End unrecorded' }}
                &middot; {{ $term->active ? 'Active' : 'Inactive' }}
                &middot; {{ $term->final_price === null ? 'Price unrecorded' : 'PKR '.number_format($term->final_price, 2) }}
            </p>

            @foreach($termCharges as $charge)
                <div class="text-sm text-gray-200">Charge #{{ $charge->id }} &middot; PKR {{ number_format($charge->amount, 2) }} &middot; {{ ucfirst($charge->status) }}</div>
                @if($discount = $charge->document_snapshot[\App\Services\VerifiedSubscriptionDocuments::HISTORICAL_DISCOUNT] ?? null)
                    <p class="text-sm text-gray-300">
                        Recorded gross PKR {{ number_format($discount['gross_paisa'] / 100, 2) }}
                        &middot; Agreed discount {{ number_format($discount['discount_basis_points'] / 100, 2) }}% (PKR {{ number_format($discount['discount_paisa'] / 100, 2) }})
                        &middot; Agreed net PKR {{ number_format($discount['net_paisa'] / 100, 2) }}
                    </p>
                    <p class="text-sm text-gray-300">{{ $discount['reason'] }} &middot; Evidence {{ $discount['reference'] }}</p>
                @endif
                @forelse($charge->payments as $payment)
                    @php
                        $allocatedProof = $termProofs->firstWhere('admin_allocated_payment_id', $payment->id);
                    @endphp
                    <div class="flex flex-wrap gap-3 text-sm text-gray-300">
                        <span>Payment #{{ $payment->id }} &middot; PKR {{ number_format($payment->amount, 2) }} &middot; {{ $payment->payment_method ?: 'Method unrecorded' }} &middot; {{ $payment->paid_at?->format('d M Y') ?? 'Date unrecorded' }}</span>
                        @if($payment->hasDownloadableReceipt())
                            @unless($payment->receipt_snapshot)<span>Historical ledger receipt</span>@endunless
                            <a class="text-indigo-400 underline" href="{{ route('saas.admin.billing.documents.receipt', [$company->id, $payment->id]) }}">Download receipt</a>
                            <a class="text-indigo-400 underline" href="{{ route('saas.admin.billing.documents.receipt', ['company'=>$company->id, 'payment'=>$payment->id, 'print'=>1]) }}" target="_blank" rel="noopener">Print receipt</a>
                        @else
                            <span>Insufficient evidence for a receipt</span>
                        @endif
                    </div>
                    @if($allocatedProof)
                        <p class="text-sm text-emerald-300">Verified collection proof #{{ $allocatedProof->id }} for payment #{{ $payment->id }}.</p>
                    @elseif(!$company->trashed() && !$charge->payment_proof_id && !$hasUnallocatedProof
                        && $payment->amount > 0 && $payment->paid_at && !$payment->paid_at->isFuture())
                        <details class="text-sm text-gray-200">
                            <summary class="cursor-pointer text-indigo-400">Confirm existing collection for payment #{{ $payment->id }}</summary>
                            <form method="POST" action="{{ route('saas.admin.companies.package-receipts.attest', [$company->id, $term->id, $payment->id]) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                                @csrf
                                <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                @if(filled($payment->payment_method))
                                    <p>Recorded method: {{ $payment->payment_method }}</p>
                                @else
                                    <label>Original payment method
                                        <select name="payment_method" required class="w-full rounded bg-gray-800"><option value="">Select method</option><option value="cash">Cash</option><option value="card">Card</option></select>
                                    </label>
                                @endif
                                @if(filled($payment->transaction_ref))
                                    <p>Recorded reference: {{ $payment->transaction_ref }}</p>
                                @else
                                    <label>Supporting reference (optional)
                                        <input type="text" name="reference" maxlength="100" class="w-full rounded bg-gray-800">
                                    </label>
                                @endif
                                <button type="submit" class="rounded bg-indigo-600 px-3 py-2 text-white">Confirm existing collection &amp; create proof</button>
                            </form>
                        </details>
                    @endif
                @empty
                    <p class="text-sm text-amber-300">No payment allocated to this charge.</p>
                @endforelse
            @endforeach

            @foreach($termProofs as $proof)
                <div class="flex flex-wrap items-center gap-3 text-sm text-gray-300">
                    <a class="text-indigo-400 underline" href="{{ route('saas.admin.payment-proofs', ['company_id' => $company->id, 'status' => 'verified']) }}">Verified proof #{{ $proof->id }}</a>
                    <span> Submitted PKR {{ number_format($proof->amount, 2) }} &middot; {{ $proof->payment_method ?: 'Method unrecorded' }}</span>
                    @if($canRecord && !$company->trashed())
                        <form method="POST" action="{{ route('saas.admin.companies.package-receipts.recover', [$company->id, $term->id, $proof->id]) }}">
                            @csrf
                            <button class="text-indigo-400 underline" type="submit">Recover receipt from verified proof</button>
                        </form>
                    @endif
                </div>
            @endforeach

            @if($termCharges->isEmpty() && $termProofs->isEmpty())
                <p class="text-sm text-gray-300">No recorded payment or verified proof for this term.</p>
            @endif
            @if($termProofs->isEmpty() && $canRecord && !$company->trashed() && $term->final_price > 0 && !$plan?->is_trial && !$term->isTrialActive() && !$term->isTrialExpired())
                <details class="text-sm text-gray-200">
                    <summary class="cursor-pointer text-indigo-400">Record payment for this term</summary>
                    <form method="POST" action="{{ route('saas.admin.companies.package-receipts.record', [$company->id, $term->id]) }}" class="mt-4 grid gap-3 sm:grid-cols-2">
                        @csrf
                        @if($canSettleCharge)
                            <input type="hidden" name="subscription_invoice_id" value="{{ $openCharge->id }}">
                            <p class="sm:col-span-2">Apply payment to charge #{{ $openCharge->id }}.</p>
                        @endif
                        <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                        <label>Payment method
                            <select name="payment_method" required class="w-full rounded bg-gray-800"><option value="">Select method</option><option value="cash">Cash</option><option value="card">Card</option><option value="bank">Bank transfer</option></select>
                        </label>
                        <label>Actual amount received (PKR)
                            <input type="number" name="verified_received_amount" min="0.01" step="0.01" required class="w-full rounded bg-gray-800">
                        </label>
                        <label>Date received
                            <input type="date" name="payment_date" max="{{ now()->toDateString() }}" required class="w-full rounded bg-gray-800">
                        </label>
                        <label>Reference (optional)
                            <input type="text" name="reference" maxlength="100" class="w-full rounded bg-gray-800">
                        </label>
                        @if($termCharges->isEmpty())
                            <details class="space-y-2 sm:col-span-2">
                                <summary class="cursor-pointer text-indigo-400">Record an agreed historical discount</summary>
                                <p>Use the documented discount and payment for this term. Enter the exact net amount received above.</p>
                                <label>Agreed discount (%)
                                    <input type="number" name="historical_discount_percent" min="0.01" max="99.99" step="0.01" class="w-full rounded bg-gray-800">
                                </label>
                                <label>Reason for discount
                                    <input type="text" name="historical_discount_reason" maxlength="500" class="w-full rounded bg-gray-800">
                                </label>
                                <label>Agreement or supporting evidence reference
                                    <input type="text" name="historical_discount_reference" maxlength="120" class="w-full rounded bg-gray-800">
                                </label>
                            </details>
                        @endif
                        <button class="rounded bg-indigo-600 px-3 py-2 text-white" type="submit">Confirm payment received</button>
                    </form>
                </details>
            @endif
        </section>
    @empty
        <p>No assigned package terms.</p>
    @endforelse
    {{ $terms->links() }}
</div>
</x-admin-layout>
