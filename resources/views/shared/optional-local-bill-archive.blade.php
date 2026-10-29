<section class="tn-support-copies" data-optional-support-copies="{{ $product }}">
    <h1>Optional local bill support copies</h1>
    <p><strong>{{ $company->name }}</strong> — {{ $product === 'pos' ? 'PRA POS' : 'FBR POS' }} — Company ID: {{ $company->id }}</p>
    <p class="tn-copy-state" role="status">Retention <strong>{{ $enabled ? 'ON' : 'OFF' }}</strong>@if($enabled) — {{ $grant->retention_days }} days per copy; new-copy consent valid through {{ $grant->valid_until }}@endif</p>
    <p>This separate support/dispute copy contains selected bill and item amounts, not a new invoice. It does not submit to PRA/FBR or add to live sales totals. Customer names, phone numbers, addresses and private notes are excluded.</p>
    <p>No old deleted records can be recovered. Enabling applies only to future eligible local-bill deletions; existing live bills are not copied.</p>
    @if(session('success'))<p class="tn-copy-success" role="status">{{ session('success') }}</p>@endif
    @if($errors->any())<div class="tn-copy-error" role="alert"><strong>Please correct your archive choice.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if($ownerCanChange)
        <form method="POST" action="{{ route($product.'.local-support-copies.update') }}" class="tn-copy-form">
            @csrf
            <h2>Owner choice</h2>
            <p>Only the company owner can opt in. A platform administrator cannot enable retention for you. The choice applies to this company, across its branches.</p>
            <label for="tn-archive-action">Archive action</label>
            <select id="tn-archive-action" name="action" aria-label="Archive action" required>
                <option value="">Choose an action</option>
                <option value="grant" @selected(old('action')==='grant')>Keep future copies for support/disputes</option>
                <option value="stop" @selected(old('action')==='stop')>Stop future copies; keep existing until expiry</option>
                <option value="purge" @selected(old('action')==='purge')>Stop future copies and purge existing optional copies</option>
            </select>
            <div class="tn-copy-fields">
                <label for="tn-retention-days">Retention days (1–365, required to enable)</label>
                <input id="tn-retention-days" name="retention_days" type="number" min="1" max="365" step="1" value="{{ old('retention_days') }}" aria-label="Retention days">
                <label for="tn-consent-until">Consent valid through (required to enable)</label>
                <input id="tn-consent-until" name="valid_until" type="date" value="{{ old('valid_until') }}" aria-label="Consent valid through">
            </div>
            <p>Choose both periods explicitly. Each retained copy expires after your chosen number of days. Consent must expire within one year; after that no new optional copies are kept until you choose again.</p>
            <label class="tn-copy-confirm"><input type="checkbox" name="confirm" value="1" required aria-label="Confirm owner decision"> I am the company owner. I confirm this action and the support-copy policy below. Purge permanently removes access to existing optional copies.</label>
            <p class="tn-copy-policy">{{ \App\Services\OptionalLocalBillArchive::WORDING }}</p>
            <button type="submit">Save archive choice</button>
        </form>
    @else
        <p><strong>Platform read-only view.</strong> The company owner manages consent. Lists and exports are access-logged.</p>
    @endif
    <h2>Available support copies</h2>
    <p>Expired copies are hidden immediately. Stopping future retention leaves existing copies until their original expiry. Purge removes their decryption keys and optional rows; it does not delete or change mandatory fiscal/accounting records.</p>
    <p>SQL backups can contain encrypted copies until the operator's backup rotation removes them. This screen does not promise immediate backup erasure. A restored backup must be quarantined before use; restoring an old keyring together with old data can restore access unless the documented restore procedure is followed.</p>
    <div class="tn-copy-table-wrap"><table><thead><tr><th>Reference</th><th>Product / branch</th><th>Deleted at</th><th>Copy expiry</th><th>Read-only export</th></tr></thead><tbody>
        @forelse($copies as $copy)
            <tr><td>{{ $copy->reference }}</td><td>{{ $copy->product === 'pos' ? 'PRA POS' : 'FBR POS' }} / {{ $copy->branch_id ?? 'Legacy / not recorded' }}</td><td>{{ $copy->deleted_at }}</td><td>{{ $copy->expires_at }}</td><td><a href="{{ $ownerCanChange ? route($product.'.local-support-copies.download', $copy->id) : route('saas.admin.local-support-copies.download', ['companyId'=>$company->id,'id'=>$copy->id]) }}">Download support copy</a></td></tr>
        @empty
            <tr><td colspan="5">No optional copies available.</td></tr>
        @endforelse
    </tbody></table></div>
    {{ $copies->links() }}
    <h2>Recent consent history</h2>
    <ul class="tn-copy-history">@forelse($consents as $consent)<li>{{ $consent->created_at }} — {{ $consent->action }} — actor {{ $consent->actor_id ?? 'System' }} — policy {{ $consent->policy_version }}@if($consent->retention_days) — {{ $consent->retention_days }} days; valid through {{ $consent->valid_until }}@endif</li>@empty<li>No consent recorded. Retention is OFF by default.</li>@endforelse</ul>
</section>
<style>
.tn-support-copies{max-width:1000px;margin:1rem auto;padding:1rem;color:#172b36;background:#fff;border:1px solid #b8c7ce;border-radius:12px;overflow-wrap:anywhere}.tn-support-copies h1,.tn-support-copies h2,.tn-support-copies label,.tn-support-copies table,.tn-copy-history{color:#172b36}.tn-support-copies h1{font-size:1.5rem;font-weight:750}.tn-support-copies h2{font-size:1.2rem;font-weight:700;margin-top:1.25rem}.tn-support-copies p{margin:.65rem 0;line-height:1.55}.tn-copy-state{padding:.8rem;border:1px solid #526c75;border-radius:6px;background:#edf5f6}.tn-copy-form{border:1px solid #9baeb7;padding:1rem;border-radius:8px;display:grid;gap:.65rem}.tn-copy-form h2{margin:0}.tn-copy-form label{font-weight:600}.tn-copy-form select,.tn-copy-form input:not([type=checkbox]){display:block;width:100%;min-width:0;min-height:44px;padding:.6rem;color:#102a35!important;background:#fff!important;border:1px solid #526c75!important;border-radius:5px;color-scheme:light}.tn-copy-fields{display:grid;gap:.45rem}.tn-copy-confirm{display:flex;gap:.65rem;align-items:flex-start;font-weight:400!important;line-height:1.6}.tn-copy-confirm input{margin-top:.3rem;flex-shrink:0;width:20px;height:20px}.tn-copy-form button{background:#073c48;color:#fff;padding:.75rem 1rem;min-height:44px;border-radius:5px;font-weight:700;justify-self:start}.tn-copy-policy{font-size:.92rem;border-left:3px solid #526c75;padding-left:.7rem}.tn-copy-error{background:#fff0ee;border:2px solid #b91c1c;color:#881313;padding:1rem;border-radius:5px}.tn-copy-success{background:#e8f6ee;color:#155b34;border:1px solid #247346;padding:.8rem}.tn-copy-table-wrap{max-width:100%;overflow-x:auto}.tn-support-copies table{width:100%;border-collapse:collapse;font-size:.94rem}.tn-support-copies td,.tn-support-copies th{padding:.7rem;text-align:left;border-bottom:1px solid #c9d4d9;vertical-align:top}.tn-support-copies a{color:#064e7b;text-decoration:underline}.tn-copy-history{padding-left:1.25rem;list-style:disc}.tn-support-copies :focus-visible{outline:3px solid #ad3d00;outline-offset:2px}@media(max-width:500px){.tn-support-copies{margin:.5rem;padding:.75rem}.tn-copy-form{padding:.7rem}.tn-support-copies td,.tn-support-copies th{padding:.5rem;min-width:115px}}
</style>
