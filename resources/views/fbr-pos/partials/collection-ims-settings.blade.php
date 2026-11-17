<section class="card" id="{{ $imsSectionId }}">
    <h2>FBR IMS integration</h2>
    <p>Finalized payment receipts use the standalone Desktop Agent IMS route.</p>
    <form method="post" action="{{ route($imsRoutes.'.ims-toggle') }}">
        @csrf
        <input type="hidden" name="enabled" value="{{ $company->fbr_reporting_enabled ? '0' : '1' }}">
        <p>Reporting: <strong>{{ $company->fbr_reporting_enabled ? 'ON' : 'OFF' }}</strong></p>
        <button type="submit">{{ $company->fbr_reporting_enabled ? 'Turn reporting OFF' : 'Turn reporting ON' }}</button>
    </form>
    <dl>
        <dt>Desktop Agent</dt><dd>{{ $company->agentOnline() ? 'Online' : ($company->agent_enabled ? 'Offline' : 'Not paired') }}</dd>
        <dt>IMS setup</dt><dd>{{ $company->agentServesFbr() && $company->fbrPosIntegrationConfigured() ? 'Configured' : 'Incomplete' }}</dd>
    </dl>
    <form method="post" action="{{ route($imsRoutes.'.ims-settings') }}" autocomplete="off">
        @csrf
        <div class="grid">
            <label>Registered IMS POS ID<input name="fbr_pos_id" inputmode="numeric" maxlength="20" value="{{ old('fbr_pos_id', $company->fbr_pos_id) }}"></label>
            <label>Environment<select name="fbr_pos_environment">
                <option value="sandbox" @selected(old('fbr_pos_environment', $company->fbr_pos_environment ?? 'sandbox') === 'sandbox')>Sandbox</option>
                <option value="production" @selected(old('fbr_pos_environment', $company->fbr_pos_environment ?? 'sandbox') === 'production')>Production</option>
            </select></label>
            <label>Sales Tax Registration Number<input name="fbr_sales_tax_registration_number" maxlength="30" value="{{ old('fbr_sales_tax_registration_number', $company->fbr_sales_tax_registration_number) }}"></label>
            <label>Registered tax office<input name="fbr_tax_office" maxlength="150" value="{{ old('fbr_tax_office', $company->fbr_tax_office) }}"></label>
            <label>IRIS Access Code<input type="password" name="fbr_access_code" maxlength="500" autocomplete="new-password" placeholder="{{ $company->fbr_access_code ? 'Saved — leave blank to keep' : 'Optional for local device setup' }}"></label>
            <label>FBR POS Token<input type="password" name="fbr_pos_token" maxlength="2000" autocomplete="new-password" placeholder="{{ $company->fbr_pos_token ? 'Saved — leave blank to keep' : 'Optional for Desktop Agent mode' }}"></label>
        </div>
        <div class="actions">
            <button type="submit">Save IMS setup</button>
            <a class="button" href="{{ route('fbrpos.agent') }}">Pair / check Desktop Agent</a>
        </div>
    </form>
    <p class="muted">Agent heartbeat confirms the TaxNest connection. Fiscal acceptance is shown on each receipt after IMS returns its result.</p>
    <form method="post" action="{{ route($imsRoutes.'.ims-diagnostics') }}">
        @csrf
        <button type="submit">Check Agent connection and recent receipts</button>
    </form>
    @if((int) session('ims_diagnostics.company_id') === (int) $company->id)
        <p role="status">Desktop Agent {{ session('ims_diagnostics.agent_online') ? 'online' : 'offline' }}.</p>
        <ul>
            @forelse(session('ims_diagnostics.receipts', []) as $entry)
                <li><a href="{{ route($imsRoutes.'.receipt', $entry['id']) }}">{{ $company->isSchoolFeePilot() ? 'SF-' : 'HP-' }}{{ $entry['id'] }}</a> · {{ $entry['status'] }}</li>
            @empty
                <li>No recent receipt status available.</li>
            @endforelse
        </ul>
    @endif
</section>
