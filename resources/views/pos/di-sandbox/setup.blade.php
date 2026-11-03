@extends('pos.di-sandbox.layout')
@section('title', 'PRA + Digital Invoice · Connection setup')
@section('content')
<div class="columns">
    <section class="card">
        <h2>{{ $company->name }}</h2>
        <p>DI reporting: <strong>{{ $profile?->enabled ? 'Enabled' : 'Disabled' }}</strong> · Selected environment: <strong>{{ strtoupper($profile?->environment ?? 'sandbox') }}</strong></p>
        <form method="post" action="{{ route('pos.di-sandbox.setup.save') }}">@csrf
            <label for="enabled">Enable DI alongside PRA</label>
            <select id="enabled" name="enabled"><option value="0" @selected(!old('enabled', $profile?->enabled))>Disabled · PRA setup unchanged</option><option value="1" @selected(old('enabled', $profile?->enabled))>Enabled</option></select>
            <label for="environment">DI environment</label>
            <select id="environment" name="environment"><option value="sandbox" @selected(old('environment', $profile?->environment ?? 'sandbox') === 'sandbox')>Sandbox · scenario testing</option><option value="production" @selected(old('environment', $profile?->environment) === 'production')>Production · live reporting</option></select>
            <h2>Company FBR details</h2>
            <label for="fbr-id">FBR Registration No</label><input id="fbr-id" type="text" name="fbr_registration_no" value="{{ old('fbr_registration_no',$company->fbr_registration_no ?: $company->ntn) }}">
            <label for="fbr-name">FBR Business Name</label><input id="fbr-name" type="text" name="fbr_business_name" value="{{ old('fbr_business_name',$company->fbr_business_name ?: $company->name) }}">
            <h2>Sandbox configuration</h2>
            <label for="sandbox-url">Sandbox API endpoint</label><input id="sandbox-url" name="sandbox_url" type="url" value="{{ old('sandbox_url',$profile?->sandbox_url ?: \App\Services\PosTaxIntegrationEndpoints::url('di','sandbox')) }}" required>
            <label for="sandbox-token">Sandbox token · {{ $profile?->sandbox_token ? 'Saved' : 'Not configured' }}</label>
            <input id="sandbox-token" type="password" name="sandbox_token" autocomplete="new-password" maxlength="8192" spellcheck="false">
            <h2>Production configuration</h2>
            <label for="production-url">Production API endpoint</label><input id="production-url" name="production_url" type="url" value="{{ old('production_url',$profile?->production_url ?: \App\Services\PosTaxIntegrationEndpoints::url('di','production')) }}" required>
            <label for="production-token">Production token · {{ $profile?->production_token ? 'Saved' : 'Not configured' }}</label>
            <input id="production-token" type="password" name="production_token" autocomplete="new-password" maxlength="8192" spellcheck="false">
            <p class="muted">Leave either token blank to keep its saved value. Use the displayed official endpoint for each environment. Both are encrypted and never displayed again. The selected environment uses only its own token.</p>
            <label><input type="checkbox" name="confirm_sandbox_setup" value="1">For Sandbox: enable test submissions only and select DI as the federal reporting channel. IMS credentials/history are retained.</label>
            <label><input type="checkbox" name="confirm_production_setup" value="1">For Production: I confirm all applicable sandbox scenarios have passed in FBR/IRIS, FBR has issued this company's production token, and the tax mapping has been reviewed. Enable live DI reporting.</label>
            <button type="submit">Save DI setup</button>
        </form>
        <form method="post" action="{{ route('pos.di-sandbox.check') }}">@csrf<button>Check saved configuration</button></form>
        <p class="muted">Configuration checks inspect saved fields only. Successful scenario invoices in the workbench establish the FBR sandbox result; a stored token alone is not proof of a working connection.</p>
        <a class="button" href="{{ route('pos.di-sandbox.index') }}">Open DI workbench / history</a>
    </section>
    <section class="card"><h2>Sandbox → Production</h2>
        <ol><li>Save the sandbox token and select Sandbox.</li><li>Prepare and submit the applicable scenario invoices. Review each FBR result.</li><li>Confirm all required scenarios are successful in IRIS and obtain the production token there.</li><li>Save the production token, select Production and confirm activation.</li><li>Prepare a real completed bill, review its mapping and explicitly submit it live.</li></ol>
        <p><strong>Accepted sandbox scenarios recorded in TaxNest:</strong> {{ $scenarios->implode(', ') ?: 'None yet' }}</p>
        <p class="muted">This list is local evidence, not a claim that every required IRIS scenario is complete. Scenario requirements depend on the company's registered activities. TaxNest does not issue production tokens.</p>
        <p>Switching environments does not resend any invoice or convert a test record into a live record. History and return references stay separate. PRA keeps its existing connection and reporting behavior.</p>
        <p><a href="{{ route('pos.tax-integrations') }}">Tax Integrations · PRA / FBR POS / Digital Invoice</a></p>
        <p class="muted">Submission is manual after reviewing the completed bill. Normal checkout and the nine normal receipt designs remain separate from the dedicated combined preview.</p>
    </section>
</div>
@endsection
