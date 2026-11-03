<x-pos-layout>
<div class="max-w-5xl mx-auto p-6">
    <a href="{{ route('pos.customize') }}" class="underline">Back to Customize POS</a>
    <h1 class="text-2xl font-bold my-4">{{ __('tax_integrations.title') }}</h1>
    <p class="mb-6">{{ $company->name }} · Set up PRA reporting and choose the federal integration required for this business. Saving a connection does not send an invoice.</p>
    <div class="grid md:grid-cols-3 gap-4">
        <section class="rounded-xl border p-5 bg-white dark:bg-gray-900"><h2 class="text-lg font-bold">PRA POS</h2>
            <p class="my-3">{{ $company->pra_enabled ? 'Enabled' : 'Check PRA reporting choice' }} · {{ ucfirst($company->pra_environment ?? 'sandbox') }}</p>
            <p>Punjab service-tax registration, connection mode and Desktop Agent.</p>
            <a class="inline-block underline mt-4" href="{{ route('pos.pra-settings') }}">PRA configuration</a>
        </section>
        <section class="rounded-xl border p-5 bg-white dark:bg-gray-900"><h2 class="text-lg font-bold">FBR POS · IMS</h2>
            <p class="my-3">{{ $imsProfile?->enabled ? 'Enabled' : 'Disabled' }} · {{ ucfirst($imsProfile?->environment ?? 'sandbox') }}</p>
            <p>Separate Sandbox/Production POS IDs, URLs and tokens. Cloud or a dedicated local FBR IMS component.</p>
            <a class="inline-block underline mt-4" href="{{ route('pos.tax-integrations.ims') }}">FBR POS configuration</a>
        </section>
        <section class="rounded-xl border p-5 bg-white dark:bg-gray-900"><h2 class="text-lg font-bold">FBR Digital Invoice</h2>
            <p class="my-3">{{ $diProfile?->enabled ? 'Enabled' : 'Disabled' }} · {{ ucfirst($diProfile?->environment ?? 'sandbox') }}</p>
            <p>FBR identity, Sandbox/Production API endpoints, encrypted tokens and scenario testing.</p>
            <a class="inline-block underline mt-4" href="{{ route('pos.di-sandbox.setup') }}">Digital Invoice configuration</a>
        </section>
    </div>
    <p class="mt-6">PRA keeps its own connection. Enabling FBR POS selects IMS and disables new DI requests; enabling DI disables new IMS requests. Saved credentials and history remain. An accepted or unresolved bill cannot be resent through the other federal integration.</p>
    <p class="mt-3">Federal submission is manual after reviewing each completed bill. PRA tax does not automatically make a federal sale exempt or zero-rated.</p>
</div>
</x-pos-layout>
