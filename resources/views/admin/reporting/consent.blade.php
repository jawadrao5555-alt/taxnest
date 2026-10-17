<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Connect read-only reports</title><style>body{font:17px system-ui;background:#f3f6fa;color:#152536;margin:0}main{max-width:750px;margin:32px auto;background:white;padding:28px;border-radius:16px}fieldset{border:1px solid #ccd5df;margin:20px 0;padding:16px}label{display:block;margin:12px 0}button{font:inherit;padding:12px 20px;margin:10px 8px 0 0;cursor:pointer}a{color:#125ab7}</style></head><body><main>
<h1>Connect read-only reports</h1><p><strong>{{ $client->name }}</strong> requests access to company billing, fiscal reporting, tax items, payment evidence and operational summaries.</p>
<p>This connection cannot create, edit, delete, report or print invoices. Buyer personal details and device credentials are excluded. Access expires after {{ config('reporting_connector.grant_days') }} days and can be revoked at any time.</p>
<form method="post" action="{{ url('/admin/reporting/oauth/authorize') }}">@csrf
@foreach($parameters as $key=>$value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
<fieldset><legend>Company access</legend><p>Select at least one company, or choose all companies.</p><label><input type="radio" name="company_scope" value="selected" checked> Only companies selected below</label>
@foreach($companies as $company)<label><input type="checkbox" name="company_ids[]" value="{{ $company->id }}"> {{ $company->name }} ({{ $company->product_type }})</label>@endforeach
<label><input type="radio" name="company_scope" value="all"> All billing companies, including companies added later</label></fieldset>
<button type="submit" name="decision" value="approve">Allow read-only access</button><button type="submit" name="decision" value="deny">Deny</button>
</form><p><a href="{{ url('/admin/reporting/connections') }}">Manage connections</a></p></main></body></html>
