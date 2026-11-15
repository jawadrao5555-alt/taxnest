@extends('fbr-pos.school-fees.layout')
@section('content')
<h1>{{ __('school.settings') }}</h1>
<p class="notice">{{ __('school.configuration_notice') }}</p>
@if($errors->any())<p role="alert" class="error">{{ $errors->first() }}</p>@endif
<section class="card"><h2>{{ __('school.heads') }}</h2>
@foreach($feeHeads as $head)
<form method="post" action="/fbr-pos/school-fees/settings/fee-head" class="school-edit-head">@csrf
<input type="hidden" name="code" value="{{ $head['code'] }}"><div class="grid"><div><label>{{ __('school.label') }}<input name="label" maxlength="80" value="{{ $head['label'] }}" required></label></div><div><label>{{ __('school.active') }}<select name="active"><option value="1" @selected($head['active'])>{{ __('school.active') }}</option><option value="0" @selected(!$head['active'])>{{ __('school.inactive') }}</option></select></label></div></div><button type="submit">{{ __('school.save') }}</button></form><hr>
@endforeach
<form id="school-add-head" method="post" action="/fbr-pos/school-fees/settings/fee-head">@csrf<input type="hidden" name="active" value="1"><label>{{ __('school.label') }}<input name="label" maxlength="80" required></label><button type="submit">{{ __('school.add_head') }}</button></form>
</section>
<section class="card"><h2>{{ __('pos.school_campus') }}</h2>@if($campuses->isEmpty())<p class="notice">{{ __('school_workspace.campus_start') }}</p>@endif<p><a href="/fbr-pos/team">{{ __('school_workspace.campus_access') }}</a></p><p>{{ __('school.campus_help') }}</p><ul>@foreach($campuses as $campus)<li>{{ $campus->name }} · {{ $campus->city }}</li>@endforeach</ul>
<form id="school-add-campus" method="post" action="/fbr-pos/school-fees/settings/campus">@csrf
<label>{{ __('school.name') }}<input name="name" maxlength="255" required></label><label>{{ __('school.city') }}<input name="city" maxlength="100"></label><label>{{ __('school.address') }}<input name="address" maxlength="1000"></label><button type="submit">{{ __('school.add_campus') }}</button></form>
</section>
@endsection
