@extends('pos.di-sandbox.layout')
@section('title', 'PRA + Digital Invoice · Sandbox setup')
@section('content')
<div class="columns">
    <section class="card">
        <h2>{{ $company->name }}</h2>
        <p>DI sandbox pilot: <strong>{{ $profile?->enabled ? 'Enabled' : 'Disabled' }}</strong></p>
        <p>Sandbox credential: <strong>{{ $profile?->sandbox_token ? 'Saved' : 'Not configured' }}</strong></p>
        <form method="post" action="{{ route('pos.di-sandbox.setup.save') }}">@csrf
            <label for="enabled">Company testing mode</label>
            <select id="enabled" name="enabled">
                <option value="0" @selected(!old('enabled', $profile?->enabled))>PRA only · DI sandbox disabled</option>
                <option value="1" @selected(old('enabled', $profile?->enabled))>PRA + FBR Digital Invoice · sandbox pilot</option>
            </select>
            <label for="sandbox-token">FBR DI sandbox token</label>
            <input id="sandbox-token" type="password" name="sandbox_token" autocomplete="new-password" maxlength="8192" spellcheck="false">
            <p class="muted">Enter only this company's sandbox token. Leave blank to keep the saved token. The token is encrypted and is never displayed again. This setting does not change your existing PRA, DI or FBR POS credentials.</p>
            <label><input type="checkbox" name="confirm_sandbox_setup" value="1">I understand this enables manual sandbox copies only, not live FBR reporting.</label>
            <button type="submit">Save sandbox setup</button>
        </form>
        @if($profile?->enabled)<a class="button" href="{{ route('pos.di-sandbox.index') }}">Open sandbox workbench</a>@endif
    </section>
    <section class="card"><h2>Test one bill, then review</h2>
        <ol><li>Save the company's sandbox token and enable the pilot.</li><li>Select a completed POS bill and confirm making a sandbox copy.</li><li>Review the classification, buyer fields, tax and total in the payload.</li><li>Submit once and inspect FBR's result.</li><li>Preview the combined 58mm or 80mm test receipt.</li></ol>
        <p>No automatic checkout submission is enabled. Disabling the pilot stops new submissions; a request already started can still finish. Records are retained.</p>
        <p class="muted">PRA reporting continues through your existing setup. FBR POS (IMS) selection, live DI reporting and integration into the nine normal receipt designs are separate work; this screen does not enable them.</p>
    </section>
</div>
@endsection
