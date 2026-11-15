@extends('fbr-pos.hospital.layout')
@section('content')
<h1>{{ __('hospital_print.settings') }}</h1>
<div class="notice">{{ __('hospital_print.local_only') }}</div>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div class="error" role="alert">{{ $errors->first() }}</div>@endif
<form id="hospital-print-settings" class="card" method="post" enctype="multipart/form-data" action="/fbr-pos/hospital/printing">@csrf
<div class="grid">
@foreach(['name','address','phone','email'] as $field)<div><label for="hospital-profile-{{ $field }}">{{ __('hospital_print.'.$field) }}</label><input id="hospital-profile-{{ $field }}" name="{{ $field }}" value="{{ old($field,$company->$field) }}" @required($field === 'name') maxlength="{{ $field === 'address' ? 500 : ($field === 'phone' ? 50 : 255) }}" type="{{ $field === 'email' ? 'email' : 'text' }}"></div>@endforeach
<div><label for="hospital-logo">{{ __('hospital_print.logo') }}</label><input id="hospital-logo" type="file" name="logo" accept="image/png,image/jpeg,image/webp"><p class="muted">PNG, JPG, WebP · 2 MB</p></div>
<div><label for="hospital-paper">{{ __('hospital_print.paper') }}</label><select id="hospital-paper" name="paper">@foreach(['58mm','80mm','a4'] as $paper)<option value="{{ $paper }}" @selected(old('paper',$preferences['paper']) === $paper)>{{ strtoupper($paper) }}</option>@endforeach</select></div>
<div><label for="hospital-margin">{{ __('hospital_print.margin') }}</label><input id="hospital-margin" type="number" min="0" max="10" name="margin_mm" value="{{ old('margin_mm',$preferences['margin_mm']) }}" required></div>
<div><label for="hospital-printer">{{ __('hospital_print.preferred') }}</label><input id="hospital-printer" name="printer_label" maxlength="100" value="{{ old('printer_label',$preferences['printer_label']) }}"></div>
</div>
@foreach(['show_logo','show_contact','center'] as $field)<label class="cash-confirm"><input type="checkbox" name="{{ $field }}" value="1" @checked(old($field,$preferences[$field]))>{{ __('hospital_print.'.$field) }}</label>@endforeach
<p class="muted">{{ __('hospital_print.browser_printer') }}</p><p class="muted">{{ __('hospital_print.profile_notice') }}</p>
<div class="actions"><button type="submit">{{ __('hospital_print.save') }}</button><a id="hospital-preview-link" href="/fbr-pos/hospital/printing/preview" target="_blank" rel="noopener">{{ __('hospital_print.preview') }}</a></div>
</form>
@endsection
