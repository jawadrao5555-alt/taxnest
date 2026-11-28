<x-admin-layout>
    @php
        $channels = [
            'federal_ims' => 'FBR POS · IMS',
            'federal_di' => 'FBR Digital Invoice',
        ];
        $oldField = fn (string $name): string => is_scalar($value = old($name)) ? (string) $value : '';
        $mode = $oldField('purchase_mode');
        $key = $oldField('idempotency_key');
        $fieldClass = 'block w-full rounded-lg border border-slate-500 bg-white px-3 py-2 text-slate-900 focus:ring-2 focus:ring-indigo-500 dark:bg-slate-900 dark:text-white';
    @endphp

    <div class="tn-content-rail mx-auto max-w-3xl space-y-4 p-4 sm:p-6">
        <a href="{{ route('saas.admin.companies.show', $company->id) }}" class="tn-admin-action inline-block rounded-lg px-3 py-2 text-sm font-semibold">Back to company</a>
        <h1 class="text-2xl font-bold">Historical federal add-on</h1>
        <p class="break-words text-sm">{{ $company->name }} · {{ $company->product_company_id ?? $company->account_code ?? $company->id }}</p>
        <p id="historical-federal-addon-effect" class="text-sm">This records the add-on for the package term. FBR connection setup and reporting activation are separate steps.</p>

        @if(session('success'))
            <p role="status" class="tn-admin-notice tn-admin-notice--success rounded-lg px-4 py-3 text-sm">{{ session('success') }}</p>
        @endif
        @if($errors->any())
            <ul role="alert" class="tn-admin-notice tn-admin-notice--error list-disc space-y-1 rounded-lg px-6 py-3 text-sm">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        @if($term)
            <section class="tn-account-panel space-y-1 rounded-xl border p-4" aria-labelledby="historical-addon-term">
                <h2 id="historical-addon-term" class="font-semibold">Package term #{{ $term->id }}</h2>
                <p class="text-sm">{{ $term->entitlementPlan()?->name ?? 'Package name unavailable' }}</p>
                <p class="text-sm">{{ $term->start_date?->format('d M Y') ?? 'Start not recorded' }} to {{ $term->end_date?->format('d M Y') ?? 'End not recorded' }}</p>
            </section>
        @endif

        @if($grant)
            @php
                $grantMode = $grant->historical_purchase['mode'] ?? null;
            @endphp
            <section class="tn-account-panel space-y-1 rounded-xl border p-4" aria-labelledby="recorded-federal-addon">
                <h2 id="recorded-federal-addon" class="font-semibold">Recorded federal add-on</h2>
                <p class="text-sm">{{ $channels[$grant->addon_code] ?? 'Federal add-on' }}</p>
                @if($grantMode === 'included')
                    <p class="text-sm">Complimentary with the paid package · Additional revenue PKR 0</p>
                @elseif($grantMode === 'separate')
                    <p class="text-sm">Separate historical payment</p>
                @endif
                @if($grant->ends_at)
                    <p class="text-sm">End date {{ $grant->ends_at->format('d M Y') }}</p>
                @endif
            </section>
        @endif

        @if($blocker)
            <p class="tn-admin-notice tn-admin-notice--warning rounded-lg px-4 py-3 text-sm">{{ $blocker }}</p>
        @endif

        @if($canRecord && $term)
            <form method="POST" action="{{ route('saas.admin.companies.historical-federal-addon.record', $company->id) }}"
                aria-describedby="historical-federal-addon-effect"
                x-data="{ mode: @js($mode) }"
                class="tn-account-panel space-y-4 rounded-xl border p-4 sm:p-5">
                @csrf
                <input type="hidden" name="subscription_id" value="{{ $term->id }}">
                <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::isUuid($key) ? $key : (string) \Illuminate\Support\Str::uuid() }}">

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <label class="block space-y-1 text-sm font-semibold">
                        Federal channel
                        <select name="addon_code" class="{{ $fieldClass }}" required>
                            <option value="">Select a channel</option>
                            @foreach($channels as $code => $label)
                                <option value="{{ $code }}" @selected($oldField('addon_code') === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block space-y-1 text-sm font-semibold">
                        Purchase type
                        <select name="purchase_mode" x-model="mode" class="{{ $fieldClass }}" required>
                            <option value="">Select a purchase type</option>
                            <option value="included" @selected($mode === 'included')>Complimentary with the paid package</option>
                            <option value="separate" @selected($mode === 'separate')>Historical separate payment</option>
                        </select>
                    </label>
                </div>
                <p x-show="mode === 'included'" x-cloak class="text-sm">A complimentary add-on records PKR 0 in additional revenue.</p>

                <div class="space-y-1">
                    <label for="historical-addon-evidence" class="block text-sm font-semibold">Original sale evidence reference</label>
                    <input id="historical-addon-evidence" name="evidence_reference" type="text" maxlength="150"
                        value="{{ $oldField('evidence_reference') }}" class="{{ $fieldClass }}" aria-describedby="historical-addon-evidence-help" required>
                    <p id="historical-addon-evidence-help" class="text-sm">Enter a reference to the original agreement, package invoice or separate receipt.</p>
                </div>

                <fieldset x-show="mode === 'separate'" :disabled="mode !== 'separate'" x-cloak class="space-y-3">
                    <legend class="mb-2 text-sm font-semibold">Original separate payment</legend>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <label class="block space-y-1 text-sm font-semibold">
                            Amount received (PKR)
                            <input name="received_amount" type="number" min="0.01" step="0.01" value="{{ $oldField('received_amount') }}"
                                class="{{ $fieldClass }}" :required="mode === 'separate'">
                        </label>
                        <label class="block space-y-1 text-sm font-semibold">
                            Date received
                            <input name="payment_date" type="date" max="{{ now()->toDateString() }}" value="{{ $oldField('payment_date') }}"
                                class="{{ $fieldClass }}" :required="mode === 'separate'">
                        </label>
                        <label class="block space-y-1 text-sm font-semibold">
                            Payment method
                            <select name="payment_method" class="{{ $fieldClass }}" :required="mode === 'separate'">
                                <option value="">Select a method</option>
                                <option value="cash" @selected($oldField('payment_method') === 'cash')>Cash</option>
                                <option value="card" @selected($oldField('payment_method') === 'card')>Card</option>
                                <option value="bank" @selected($oldField('payment_method') === 'bank')>Bank transfer</option>
                            </select>
                        </label>
                        <label class="block space-y-1 text-sm font-semibold">
                            Payment reference (optional)
                            <input name="payment_reference" type="text" maxlength="150" value="{{ $oldField('payment_reference') }}" class="{{ $fieldClass }}">
                        </label>
                    </div>
                </fieldset>

                <button type="submit" class="tn-admin-action tn-admin-action--confirm rounded-lg px-4 py-2 text-sm font-semibold">Record historical add-on</button>
            </form>
        @endif
    </div>
</x-admin-layout>
