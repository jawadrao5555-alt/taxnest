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
            <label for="sandbox-token">Sandbox token · {{ $profile?->sandbox_token ? 'Saved' : 'Not configured' }}</label>
            <input id="sandbox-token" type="password" name="sandbox_token" autocomplete="new-password" maxlength="8192" spellcheck="false">
            <label for="production-token">Production token · {{ $profile?->production_token ? 'Saved' : 'Not configured' }}</label>
            <input id="production-token" type="password" name="production_token" autocomplete="new-password" maxlength="8192" spellcheck="false">
            <p class="muted">Leave either field blank to keep its saved token. Both are encrypted and never displayed again. The selected environment uses only its own token.</p>
            <label><input type="checkbox" name="confirm_sandbox_setup" value="1">For Sandbox: enable test submissions only.</label>
            <label><input type="checkbox" name="confirm_production_setup" value="1">For Production: I confirm all applicable sandbox scenarios have passed in FBR/IRIS, FBR has issued this company's production token, and the tax mapping has been reviewed. Enable live DI reporting.</label>
            <button type="submit">Save DI setup</button>
        </form>
        <a class="button" href="{{ route('pos.di-sandbox.index') }}">Open DI workbench / history</a>
    </section>
    <section class="card"><h2>Sandbox → Production</h2>
        <ol><li>Save the sandbox token and select Sandbox.</li><li>Prepare and submit the applicable scenario invoices. Review each FBR result.</li><li>Confirm all required scenarios are successful in IRIS and obtain the production token there.</li><li>Save the production token, select Production and confirm activation.</li><li>Prepare a real completed bill, review its mapping and explicitly submit it live.</li></ol>
        <p><strong>Accepted sandbox scenarios recorded in TaxNest:</strong> {{ $scenarios->implode(', ') ?: 'None yet' }}</p>
        <p class="muted">This list is local evidence, not a claim that every required IRIS scenario is complete. Scenario requirements depend on the company's registered activities. TaxNest does not issue production tokens.</p>
        <p>Switching environments does not resend any invoice or convert a test record into a live record. History and return references stay separate. PRA keeps its existing connection and reporting behavior.</p>
        <p class="muted">Submission is manual from this workbench. FBR POS (IMS) selection, automatic checkout submission and integration into the nine normal receipt designs are separate work.</p>
    </section>
</div>
@endsection
