@extends('fbr-pos.school-fees.layout')
@section('content')
<h1>{{ __('school.settings') }}</h1>
<p class="notice">{{ __('school.configuration_notice') }}</p>
@if(session('status'))<p role="status" class="notice">{{ session('status') }}</p>@endif
@if($errors->any())<p role="alert" class="error">{{ $errors->first() }}</p>@endif
<div class="metrics school-settings-hub">
<section class="card"><h2>School profile & receipt</h2><p>Name, logo, address, contact, paper size, margins and receipt appearance.</p><a class="button" href="{{ route('fbrpos.school-fees.printing') }}">Profile & receipt settings</a></section>
<section class="card"><h2>Campuses & team</h2><p>Campus records, active campus and campus-scoped cashier access.</p><a class="button" href="/fbr-pos/team">Manage team access</a></section>
<section class="card"><h2>My profile & password</h2><p>Signed-in user's personal profile and password.</p><a class="button" href="/fbr-pos/my-profile">Open profile</a></section>
<section class="card"><h2>Billing & package</h2><p>Subscription, package and billing history.</p><a class="button" href="/fbr-pos/billing">Open billing</a></section>
<section class="card"><h2>Help & support</h2><p>School POS guide and product support.</p><a class="button" href="/product-guide#school-standalone">School POS guide</a> <a href="/fbr-pos/suggestions">Contact support</a></section>
<section class="card" id="school-records"><h2>{{ __('school_workspace.retail_archive') }}</h2><a class="button" href="/fbr-pos/school-fees/legacy-retail">{{ __('pos.school_legacy_title') }}</a></section>
<section class="card" id="school-preferences">
<h2>{{ __('school_workspace.language') }}</h2>
<form method="post" action="/fbr-pos/set-language">@csrf<label for="school-language">{{ __('school_workspace.language') }}</label><select id="school-language" name="language"><option value="en" @selected(app()->getLocale()==='en')>English</option><option value="rur" @selected(app()->getLocale()==='rur')>Roman Urdu</option><option value="ur" @selected(app()->getLocale()==='ur')>اردو</option></select><button type="submit">{{ __('school_workspace.save_language') }}</button></form>
</section>
</div>
@include('fbr-pos.partials.collection-fbr-settings-link')
<section class="card"><h2>{{ __('school.heads') }}</h2><p class="muted">{{ __('school_audit.rate_help') }}</p>
@foreach($feeHeads as $head)
<form method="post" action="/fbr-pos/school-fees/settings/fee-head" class="school-edit-head">@csrf
@if($imsFeeHeadSettingsAvailable)
<div class="grid">
    <label>{{ __('school_audit.ims_pct_code') }}<input name="ims_pct_code" inputmode="decimal" maxlength="10" value="{{ $head['ims_pct_code'] ?? '' }}"></label>
    <label>{{ __('school_audit.ims_tax_rate') }}<input name="ims_tax_rate" type="number" min="0" max="100" step="0.01" value="{{ $head['ims_tax_rate'] ?? '' }}"></label>
</div>
@endif
<input type="hidden" name="code" value="{{ $head['code'] }}"><div class="grid"><div><label>{{ __('school.label') }}<input name="label" maxlength="80" value="{{ $head['label'] }}" required></label></div><div><label>{{ __('school_audit.default_rate') }} (PKR)<input name="default_amount" type="number" min="0" max="99999999.99" step="0.01" value="{{ isset($head['default_amount_paisa']) ? number_format($head['default_amount_paisa']/100, 2, '.', '') : '' }}"></label></div><div><label>{{ __('school.active') }}<select name="active"><option value="1" @selected($head['active'])>{{ __('school.active') }}</option><option value="0" @selected(!$head['active'])>{{ __('school.inactive') }}</option></select></label></div></div><button type="submit">{{ __('school.save') }}</button></form><hr>
@endforeach
<form id="school-add-head" method="post" action="/fbr-pos/school-fees/settings/fee-head">@csrf<input type="hidden" name="active" value="1"><label>{{ __('school.label') }}<input name="label" maxlength="80" required></label><label>{{ __('school_audit.default_rate') }} (PKR)<input name="default_amount" type="number" min="0" max="99999999.99" step="0.01"></label><button type="submit">{{ __('school.add_head') }}</button></form>
</section>
<section class="card"><h2>{{ __('pos.school_campus') }}</h2>@if($campuses->isEmpty())<p class="notice">{{ __('school_workspace.campus_start') }}</p>@endif<p><a href="/fbr-pos/team">{{ __('school_workspace.campus_access') }}</a></p><p>{{ __('school.campus_help') }}</p><ul>@foreach($campuses as $campus)<li>{{ $campus->name }} · {{ $campus->city }}</li>@endforeach</ul>
<form id="school-add-campus" method="post" action="/fbr-pos/school-fees/settings/campus">@csrf
<label>{{ __('school.name') }}<input name="name" maxlength="255" required></label><label>{{ __('school.city') }}<input name="city" maxlength="100"></label><label>{{ __('school.address') }}<input name="address" maxlength="1000"></label><button type="submit">{{ __('school.add_campus') }}</button></form>
</section>
@endsection

