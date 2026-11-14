<!doctype html>
<html lang="{{ app()->getLocale() === 'ur' ? 'ur' : 'en' }}" dir="{{ app()->getLocale() === 'ur' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('pos.school_fee_desk') }} · {{ $company->name }}</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f3f6fa;color:#183044;font:16px/1.55 system-ui,sans-serif}main{max-width:980px;margin:32px auto;padding:0 20px}header{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;margin-bottom:24px}h1{font-size:28px;margin:0}h2{font-size:20px}.brand{color:#096c73;font-weight:700}.card{background:white;border:1px solid #d9e2ec;border-radius:16px;padding:24px;margin:18px 0;box-shadow:0 3px 12px #18304406}.notice{padding:16px;border:1px solid #ebd59b;background:#fff9e9;border-radius:10px;font-size:14px}.muted{color:#526777;font-size:14px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}label{display:block;font-weight:600;margin-bottom:6px}input,select{width:100%;font:inherit;padding:11px;border:1px solid #b8c8d5;border-radius:8px;background:white}button,.button{display:inline-block;padding:12px 20px;border:0;border-radius:8px;background:#096c73;color:white;font:inherit;font-weight:600;text-decoration:none;cursor:pointer}button:disabled{opacity:.55}a{color:#096c73}.actions{display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-top:22px}.error{color:#9b1c1c}table{width:100%;border-collapse:collapse}td,th{padding:12px 8px;text-align:start;border-bottom:1px solid #e3eaf0}.scroll{overflow:auto}.total{font-size:28px;font-weight:700}dl{display:grid;grid-template-columns:1fr 1fr;gap:10px}dt{color:#526777}dd{margin:0;text-align:end}nav svg{max-width:24px}.metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.metric-value{font-size:27px;font-weight:700;margin-top:10px}.campus-switcher{max-width:300px}.steps{padding-left:20px}.cash-confirm{display:flex;gap:10px;align-items:flex-start;margin-top:20px}.cash-confirm input{width:20px;height:20px;flex-shrink:0}nav.actions{padding-bottom:16px;border-bottom:1px solid #d9e2ec}nav.actions a{font-weight:600}@media(max-width:600px){main{margin:18px auto}.metrics{grid-template-columns:1fr}.grid{grid-template-columns:1fr}.card{padding:18px}h1{font-size:23px}}@media print{body{background:white}main{max-width:80mm;margin:0;padding:3mm}.no-print,header{display:none!important}.card{border:0;box-shadow:none;padding:0}.notice{border:1px solid #777;background:white}.total{font-size:21px}h1{font-size:20px}dl{font-size:12px}}
    </style>
    @include('partials.urdu-font')
</head>
<body><main>
<header><div><a class="brand" href="/fbr-pos/dashboard">TaxNest · {{ __('pos.auth_bt_school_education') }}</a><div>{{ $company->name }}</div></div><div class="actions no-print" style="margin-top:0"><a href="/fbr-pos/dashboard">{{ __('pos.school_dashboard') }}</a><form method="post" action="/fbr-pos/logout">@csrf<button type="submit">{{ __('pos.logout') }}</button></form></div></header>
@if(isset($schoolCampuses) && $schoolCampuses->count() > 1)
<form id="school-campus-switcher" class="no-print campus-switcher" method="post" action="/fbr-pos/school-fees/campus">
@csrf
<label for="school-campus">{{ __('pos.school_campus') }}</label><select id="school-campus" name="branch_id" onchange="this.form.requestSubmit()">
@foreach($schoolCampuses as $campus)<option value="{{ $campus->id }}" @selected((int) $schoolCampusId === (int) $campus->id)>{{ $campus->name }}</option>@endforeach
</select><noscript><button type="submit">{{ __('pos.school_switch_campus') }}</button></noscript>
</form>
@elseif(isset($schoolCampuses) && $schoolCampuses->count() === 1)
<p class="muted">{{ __('pos.school_campus') }}: {{ $schoolCampuses->first()->name }}</p>
@endif
<nav class="actions no-print" aria-label="{{ __('pos.school_navigation') }}"><a href="/fbr-pos/school-fees">{{ __('pos.school_nav_collect') }}</a><a href="/fbr-pos/transactions">{{ __('pos.school_nav_receipts') }}</a>@if(\App\Services\PosAccessService::dayCloseAllowed(Auth::guard('fbrpos')->user(), $company))<a href="/fbr-pos/day-close">{{ __('pos.school_nav_close') }}</a>@endif</nav>
@yield('content')
</main></body></html>
