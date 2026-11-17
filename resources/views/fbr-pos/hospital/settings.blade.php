@extends('fbr-pos.hospital.layout')
@section('content')
<h1>{{ __('hospital_workspace.settings') }}</h1>
<p class="notice">{{ __('hospital.scope') }}</p>
@if($errors->any())<p role="alert" class="error">{{ $errors->first() }}</p>@endif
@if(session('status'))<p role="status" class="notice">{{ session('status') }}</p>@endif
@include('fbr-pos.partials.collection-fbr-settings-link')
<section class="card"><h2>{{ __('hospital_workspace.services') }}</h2><p>{{ __('hospital_workspace.catalog_note') }}</p>
@foreach($services as $service)
<form class="hospital-edit-service" method="post" action="/fbr-pos/hospital/settings/services">@csrf<input type="hidden" name="code" value="{{ $service->code }}">
@if($imsServiceSettingsAvailable)
<label>{{ __('hospital_workspace.ims_pct_code') }}<input name="ims_pct_code" inputmode="decimal" maxlength="10" value="{{ $service->ims_pct_code ?? '' }}"></label>
<label>{{ __('hospital_workspace.ims_tax_rate') }}<input name="ims_tax_rate" type="number" min="0" max="100" step="0.01" value="{{ $service->ims_tax_rate ?? '' }}"></label>
@endif
<label>{{ __('hospital_workspace.label') }}<input name="label" maxlength="80" required value="{{ $service->label }}"></label><label>{{ __('hospital.service') }}<select name="service_head">@foreach(['consultation','lab','procedure'] as $head)<option value="{{ $head }}" @selected($service->service_head===$head)>{{ __('hospital.'.$head) }}</option>@endforeach</select></label>
<label>{{ __('hospital_workspace.rate') }}<input name="rate" inputmode="decimal" required value="{{ number_format($service->rate_paisa/100,2,'.','') }}"></label><label>{{ __('school.active') }}<select name="active"><option value="1" @selected($service->active)>{{ __('school.active') }}</option><option value="0" @selected(!$service->active)>{{ __('school.inactive') }}</option></select></label><button>{{ __('school.save') }}</button></form><hr>
@endforeach
<form id="hospital-add-service" method="post" action="/fbr-pos/hospital/settings/services">@csrf<input type="hidden" name="active" value="1"><label>{{ __('hospital_workspace.label') }}<input name="label" required maxlength="80"></label><label>{{ __('hospital.service') }}<select name="service_head">@foreach(['consultation','lab','procedure'] as $head)<option value="{{ $head }}">{{ __('hospital.'.$head) }}</option>@endforeach</select></label><label>{{ __('hospital_workspace.rate') }}<input name="rate" inputmode="decimal" required></label><button>{{ __('hospital_workspace.add_service') }}</button></form></section>
<section class="card"><h2>{{ __('hospital_workspace.campuses') }}</h2><p>{{ __('hospital_workspace.campus_note') }}</p><p><a href="/fbr-pos/team">{{ __('hospital_workspace.team') }}</a></p><ul>@foreach($campuses as $campus)<li>{{ $campus->name }} · {{ $campus->city }}</li>@endforeach</ul>
<form id="hospital-add-campus" method="post" action="/fbr-pos/hospital/settings/campus">@csrf<label>{{ __('school.name') }}<input name="name" required maxlength="255"></label><label>{{ __('school.city') }}<input name="city" maxlength="100"></label><label>{{ __('school.address') }}<input name="address" maxlength="1000"></label><button>{{ __('school.add_campus') }}</button></form></section>
@endsection
