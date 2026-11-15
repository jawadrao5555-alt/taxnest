<!doctype html>
<html lang="{{ app()->getLocale() === 'ur' ? 'ur' : 'en' }}" dir="{{ app()->getLocale() === 'ur' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('pos.school_fee_desk') }} · {{ $company->name }}</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f3f6fa;color:#183044;font:16px/1.55 system-ui,sans-serif}main{max-width:980px;margin:32px auto;padding:0 20px}header{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;margin-bottom:24px}h1{font-size:28px;margin:0}h2{font-size:20px}.brand{color:#096c73;font-weight:700}.card{background:white;border:1px solid #d9e2ec;border-radius:16px;padding:24px;margin:18px 0;box-shadow:0 3px 12px #18304406}.notice{padding:16px;border:1px solid #ebd59b;background:#fff9e9;border-radius:10px;font-size:14px}.muted{color:#526777;font-size:14px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}label{display:block;font-weight:600;margin-bottom:6px}input,select{width:100%;font:inherit;padding:11px;border:1px solid #b8c8d5;border-radius:8px;background:white}button,.button{display:inline-block;padding:12px 20px;border:0;border-radius:8px;background:#096c73;color:white;font:inherit;font-weight:600;text-decoration:none;cursor:pointer}button:disabled{opacity:.55}a{color:#096c73}.actions{display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-top:22px}.error{color:#9b1c1c}table{width:100%;border-collapse:collapse}td,th{padding:12px 8px;text-align:start;border-bottom:1px solid #e3eaf0}.scroll{overflow:auto}.total{font-size:28px;font-weight:700}dl{display:grid;grid-template-columns:1fr 1fr;gap:10px}dt{color:#526777}dd{margin:0;text-align:end}nav svg{max-width:24px}.school-shared-page{min-width:0}.school-shared-page .overflow-hidden{overflow-x:auto}details{margin:16px 0}summary{cursor:pointer;font-weight:700}textarea{max-width:100%}img{max-width:100%}form{min-width:0}input,select{min-width:0}#school-workspace-menu select{max-width:240px}#school-workspace-menu button{margin:8px}body main>header svg{max-width:24px}.metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.metric-value{font-size:27px;font-weight:700;margin-top:10px}.campus-switcher{max-width:300px}.steps{padding-left:20px}.cash-confirm{display:flex;gap:10px;align-items:flex-start;margin-top:20px}.cash-confirm input{width:20px;height:20px;flex-shrink:0}nav.actions{padding-bottom:16px;border-bottom:1px solid #d9e2ec}nav.actions a{font-weight:600}@media(max-width:600px){main{margin:18px auto}.metrics{grid-template-columns:1fr}.grid{grid-template-columns:1fr}.card{padding:18px}h1{font-size:23px}}@media print{body{background:white}main{max-width:80mm;margin:0;padding:3mm}.no-print,header{display:none!important}.card{border:0;box-shadow:none;padding:0}.notice{border:1px solid #777;background:white}.total{font-size:21px}h1{font-size:20px}dl{font-size:12px}}
    </style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.urdu-font')
</head>
<body><main>
<header><div><a class="brand" href="/fbr-pos/dashboard">TaxNest · {{ __('pos.auth_bt_school_education') }}</a><div>{{ $company->name }}</div></div><div class="actions no-print" style="margin-top:0"><a href="/fbr-pos/dashboard">{{ __('pos.school_dashboard') }}</a>@if(is_array(session('impersonation')))<form method="post" action="{{ route('saas.admin.impersonation.stop') }}">@csrf<button type="submit">{{ __('school_workspace.exit_company') }}</button></form>@else<form method="post" action="/fbr-pos/logout">@csrf<button type="submit">{{ __('pos.logout') }}</button></form>@endif</div></header>
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
@if($schoolCanConfigure ?? false)<p class="no-print"><a href="/fbr-pos/school-fees/settings">{{ __('school.settings') }}</a></p>@endif
<p class="notice no-print" id="school-fiscal-status" role="status">{{ __('school_workspace.fiscal_off') }} {{ __('pos.fbr_missing_school_route') }}</p>
<details class="no-print" id="school-workspace-menu"><summary>{{ __('school_workspace.workspace_menu') }}</summary><nav class="actions" aria-label="{{ __('school_workspace.workspace_menu') }}">
@if($schoolCanConfigure ?? false)
<a href="/fbr-pos/team">{{ __('school_workspace.team_access') }}</a><a href="/fbr-pos/school-fees/settings">{{ __('school.settings') }}</a><a href="/fbr-pos/school-fees/printing">{{ __('school_workspace.profile_printing') }}</a><a href="/fbr-pos/billing">{{ __('school_workspace.billing') }}</a><a href="/fbr-pos/school-fees/legacy-retail">{{ __('school_workspace.retail_archive') }}</a>
@endif
<a href="/fbr-pos/my-profile">{{ __('school_workspace.my_profile') }}</a><a href="/fbr-pos/suggestions">{{ __('school_workspace.help') }}</a>
</nav><form method="post" action="/fbr-pos/set-language">@csrf<label for="school-language">{{ __('school_workspace.language') }}</label><select id="school-language" name="language"><option value="en" @selected(app()->getLocale()==='en')>English</option><option value="rur" @selected(app()->getLocale()==='rur')>Roman Urdu</option><option value="ur" @selected(app()->getLocale()==='ur')>اردو</option></select><button type="submit">{{ __('school_workspace.save_language') }}</button></form></details>
@if(isset($slot))<section class="school-shared-page">{{ $slot }}</section>@else @yield('content') @endif
<script>
// A bfcache page can predate a category cutover or deploy. Reload only a restored
// GET page; pending collection keys remain in sessionStorage for safe recovery.
window.addEventListener('pageshow', function(event) { if(event.persisted) window.location.reload(); });
</script>
</main></body></html>
