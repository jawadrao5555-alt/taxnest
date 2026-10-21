<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Read-only reporting connections</title><style>body{font:17px system-ui;background:#f3f6fa;color:#152536;margin:0}main{max-width:850px;margin:32px auto;background:white;padding:28px;border-radius:16px}article{border:1px solid #ccd5df;border-radius:12px;padding:16px;margin:16px 0}code{overflow-wrap:anywhere}button{font:inherit;padding:10px 20px;cursor:pointer}a{color:#125ab7}</style></head><body><main>
<h1>Read-only reporting connections</h1><p>Connect a compatible OAuth MCP client using this server URL:</p><p><code>{{ $resource }}</code></p>
<p>Billing reports require your explicit connection approval. Reports cannot change business data. Connections expire after {{ config('reporting_connector.grant_days') }} days; revoking immediately blocks access and refresh tokens.</p>
<section aria-labelledby="connection-setup" data-reporting-onboarding>
<h2 id="connection-setup">Connect and verify a known bill</h2>
<ol>
<li>Add the server URL above to your OAuth-capable MCP client, then sign in as the TaxNest super admin. Client support and availability depend on your account.</li>
<li>Approve only the required companies. No password or API token should be pasted into a conversation. Existing fiscal and company settings stay unchanged.</li>
<li>Resolve the exact company and choose a known date in Asia/Karachi. Use the same calendar/business-date basis, branch and payment filters as the company report.</li>
<li>Compare bill count, reported/unreported amounts, tax and taxable/exempt/zero-rate items. Check one sale and one credit note; a credit note is not proof of a cash refund. Compare payments separately and retain unavailable evidence as unavailable.</li>
<li>Test revocation here and confirm the client loses report access. Reconnect only if you want to keep using the integration.</li>
</ol>
<p><strong>Active means access is authorised; it does not prove ChatGPT is connected or that billing totals were checked.</strong> A successful read below proves only that this grant served a report request. Compare the known bill yourself before accepting the connection.</p>
<details><summary>Trusted OAuth callbacks</summary><ul>@foreach(config('reporting_connector.redirect_uris', []) as $callback)<li><code>{{ $callback }}</code></li>@endforeach</ul><p>Use exact trusted callback URLs. Ask the owner to configure a different client callback; do not use wildcards.</p></details>
</section>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@forelse($grants as $grant)<article><h2>{{ $grant->name }}</h2><p>Company scope: {{ $grant->company_ids===null?'All billing companies':'Selected company IDs: '.implode(', ',json_decode($grant->company_ids,true)??[]) }}</p>
<p>Expires: {{ $grant->expires_at }}. Status: {{ $grant->revoked_at?'Revoked':(now()->gte($grant->expires_at)?'Expired':'Active') }}.</p>
<p data-report-read-evidence>Successful report reads: {{ (int) $grant->successful_reads }}.
@if($grant->last_read_at) Last successful read: {{ $grant->last_read_at }} ({{ config('app.timezone') }}).
@else No successful report read recorded for this connection yet.
@endif</p>
@if(!$grant->revoked_at)<form method="post" action="{{ url('/admin/reporting/connections/'.$grant->id.'/revoke') }}">@csrf<button type="submit">Revoke connection</button></form>@endif</article>
@empty<p>No approved connections yet.</p>@endforelse<p><a href="{{ url('/admin/dashboard') }}">Back to administration</a></p></main></body></html>
