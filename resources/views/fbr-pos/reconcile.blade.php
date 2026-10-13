<x-fbr-pos-layout>
<main class="mx-auto max-w-3xl space-y-5 px-4 py-6" data-fbr-owner-reconcile>
    <a href="{{ route('fbrpos.failQueue') }}" class="text-teal-700 underline">Back to submission queue</a>
    <h1 class="text-2xl font-bold dark:text-white">Reconcile existing invoice</h1>
    <section class="rounded-xl border p-4 dark:text-white">
        <p>Retailer invoice / USIN: <strong>{{ $bill->invoice_number }}</strong></p>
        <p>Invoice total: <strong>PKR {{ number_format($bill->total_amount, 2) }}</strong></p>
        <p>Stored fiscal number: <strong>{{ $bill->fbr_invoice_number ?: 'Not recorded' }}</strong></p>
        <p class="mt-3 text-sm">Latest company connection evidence (separate from this invoice's operator review)</p>
        <p>Requested environment: {{ $evidence['requested_environment'] }}</p>
        <p>Local environment proof: {{ $evidence['local_environment_proof'] }}</p>
        <p>Central verification: {{ $evidence['central_verification_state'] }}</p>
    </section>
    <p class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
        Check this exact USIN, POS identity and amount in local IMS before deciding.
        A missing FBR portal record may mean delayed synchronization; it is not proof of rejection.
        This form records your checked evidence, not automatic FBR verification. Do not paste credentials or tokens.
    </p>
    @if($errors->any())<p role="alert" class="rounded border border-red-300 p-3 text-red-700">{{ $errors->first() }}</p>@endif
    <form method="POST" action="{{ route('fbrpos.reconcile.store', $bill->id) }}" class="space-y-4 rounded-xl border p-4 dark:text-white">
        @csrf
        <input type="hidden" name="request_key" value="{{ old('request_key', (string) \Illuminate\Support\Str::uuid()) }}">
        <input type="hidden" name="fingerprint" value="{{ \App\Services\FbrPosOwnerReconciliation::fingerprint($bill) }}">
        <label class="block">Evidence result
            <select required name="decision" class="block mt-1 w-full rounded border dark:bg-gray-800">
                <option value="">Select the checked result</option>
                <option value="accepted" @selected(old('decision') === 'accepted')>Accepted — record existing fiscal number; do not repost</option>
                <option value="not_accepted" @selected(old('decision') === 'not_accepted')>IMS confirms rejection — allow retry of the same USIN</option>
            </select>
        </label>
        <label class="block">Evidence source
            <select name="evidence_source" required class="block mt-1 w-full rounded border dark:bg-gray-800">
                <option value="ims_log" @selected(old('evidence_source') === 'ims_log')>Local IMS submission log</option>
                <option value="fbr_portal" @selected(old('evidence_source') === 'fbr_portal')>FBR portal acceptance record</option>
            </select>
        </label>
        <label class="block">Checked environment
            <select name="environment" required class="block mt-1 w-full rounded border dark:bg-gray-800">
                <option value="production" @selected(old('environment', $evidence['requested_environment']) === 'production')>Production</option>
                <option value="sandbox" @selected(old('environment', $evidence['requested_environment']) === 'sandbox')>Sandbox</option>
            </select>
        </label>
        <label class="block">Log/document reference <input required maxlength="160" name="evidence_reference" value="{{ old('evidence_reference') }}" class="block mt-1 w-full rounded border dark:bg-gray-800"></label>
        <label class="block">Actual fiscal number (required for accepted result) <input maxlength="100" name="fiscal_number" value="{{ old('fiscal_number', $bill->fbr_invoice_number) }}" class="block mt-1 w-full rounded border dark:bg-gray-800"></label>
        <label class="flex items-start gap-2"><input type="checkbox" required name="confirmed" value="1" class="mt-1">I checked this USIN, POS identity, environment and amount, and confirm the selected result.</label>
        <button class="w-full rounded-lg bg-teal-700 px-4 py-3 font-semibold text-white sm:w-auto">Save owner reconciliation</button>
    </form>
</main>
</x-fbr-pos-layout>
