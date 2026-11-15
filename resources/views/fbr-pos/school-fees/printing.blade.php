@extends('fbr-pos.school-fees.layout')
@section('content')
<h1>{{ __('school_print.settings') }}</h1>
<div class="notice">{{ __('school_print.local_only') }}</div>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div class="error" role="alert">{{ $errors->first() }}</div>@endif
<form id="school-print-settings" class="card" method="post" enctype="multipart/form-data" action="/fbr-pos/school-fees/printing">@csrf
<div class="grid">
@foreach(['name','address','phone','email'] as $field)<div><label for="school-profile-{{ $field }}">{{ __('school_print.'.$field) }}</label><input id="school-profile-{{ $field }}" name="{{ $field }}" value="{{ old($field,$company->$field) }}" @required($field === 'name') maxlength="{{ $field === 'address' ? 500 : ($field === 'phone' ? 50 : 255) }}" type="{{ $field === 'email' ? 'email' : 'text' }}"></div>@endforeach
<div><label for="school-logo">{{ __('school_print.logo') }}</label><input id="school-logo" type="file" name="logo" accept="image/png,image/jpeg,image/webp"><p class="muted">PNG, JPG, WebP · 2 MB</p></div>
<div><label for="school-paper">{{ __('school_print.paper') }}</label><select id="school-paper" name="paper">@foreach(['58mm','80mm','a4'] as $paper)<option value="{{ $paper }}" @selected(old('paper',$preferences['paper']) === $paper)>{{ strtoupper($paper) }}</option>@endforeach</select></div>
<div><label for="school-margin">{{ __('school_print.margin') }}</label><input id="school-margin" type="number" min="0" max="10" name="margin_mm" value="{{ old('margin_mm',$preferences['margin_mm']) }}" required></div>
<div><label for="school-printer">{{ __('school_print.preferred') }}</label><input id="school-printer" name="printer_label" maxlength="100" value="{{ old('printer_label',$preferences['printer_label']) }}"></div>
</div>
@foreach(['show_logo','show_contact','center'] as $field)<label class="cash-confirm"><input type="checkbox" name="{{ $field }}" value="1" @checked(old($field,$preferences[$field]))>{{ __('school_print.'.$field) }}</label>@endforeach
<p class="muted">{{ __('school_print.browser_printer') }}</p><p class="muted">{{ __('school_print.profile_notice') }}</p>
<div class="actions"><button type="submit">{{ __('school_print.save') }}</button><a id="school-preview-link" href="/fbr-pos/school-fees/printing/preview" target="_blank" rel="noopener">{{ __('school_print.preview') }}</a></div>
</form>
@endsection
