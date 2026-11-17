<!doctype html>
<html lang="{{ app()->getLocale() === 'ur' ? 'ur' : 'en' }}" dir="{{ app()->getLocale() === 'ur' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ __('hospital.title') }} · {{ $company->name }}</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#edf5f9;color:#173246;font:16px/1.55 system-ui,sans-serif}main{width:100%;max-width:1280px;min-width:0;margin:0 auto;padding:32px 24px}h1{font-size:28px;margin:0}h2{font-size:20px}.card{background:white;border:1px solid #ccdee8;border-radius:16px;padding:24px;margin:18px 0;box-shadow:0 3px 12px #18304408}.notice{padding:16px;border:1px solid #ebd59b;background:#fff9e9;border-radius:10px;font-size:14px}.muted{color:#526777;font-size:14px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}label{display:block;font-weight:600;margin-bottom:6px}input,select{width:100%;min-width:0;font:inherit;padding:11px;border:1px solid #b8c8d5;border-radius:8px;background:white}button,.button{display:inline-block;padding:12px 20px;border:0;border-radius:8px;background:#17657b;color:white;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}button:hover,.button:hover{background:#124f62}button:disabled{opacity:.55}a{color:#17657b}.actions{display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-top:22px}.error{color:#9b1c1c}table{width:100%;border-collapse:collapse}td,th{padding:12px 8px;text-align:start;border-bottom:1px solid #e3eaf0}.scroll{overflow:auto}.total{font-size:28px;font-weight:700}dl{display:grid;grid-template-columns:1fr 1fr;gap:10px}dt{color:#526777}dd{margin:0;text-align:end}details{margin:16px 0}summary{cursor:pointer;font-weight:700}textarea,img{max-width:100%}form{min-width:0}.metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.metric-value{font-size:27px;font-weight:800;color:#17657b;margin-top:10px}.cash-confirm{display:flex;gap:10px;align-items:flex-start;margin-top:20px}.cash-confirm input{width:20px;height:20px;flex-shrink:0}
.hospital-shell{display:grid;grid-template-columns:240px minmax(0,1fr);min-height:100vh}.hospital-sidebar{display:flex;flex-direction:column;gap:20px;background:#123648;color:white;padding:24px 18px}.hospital-sidebar a{color:inherit}.hospital-identity{font-size:20px;font-weight:800;line-height:1.18;text-decoration:none}.hospital-sidebar-nav{display:grid;gap:6px}.hospital-sidebar-nav a{padding:9px 10px;border-radius:8px;text-decoration:none}.hospital-sidebar-nav a:hover,.hospital-sidebar-nav a:focus-visible,.hospital-sidebar-nav a.active{background:#1f8094;color:white}.hospital-sidebar-brand{display:grid;gap:2px;margin-top:auto;padding-top:16px;border-top:1px solid #507082}.hospital-sidebar-brand a{color:#9ce8ee;text-decoration:underline}.hospital-topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;background:white;border-bottom:1px solid #ccdee8;margin:-32px -24px 24px;padding:20px 24px}.hospital-topbar h1{font-size:24px}.hospital-topbar-tools{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap}.hospital-topbar-tools form{margin:0}.campus-switcher select{min-width:180px}.fiscal-badge{display:inline-flex;align-items:center;border-radius:8px;padding:9px 13px;font-weight:800;font-size:13px;background:#f2d49b;color:#70450b}.fiscal-badge.on{background:#d7f3e8;color:#0b664f}.hospital-shared-page{min-width:0}
input:focus-visible,select:focus-visible,button:focus-visible,a:focus-visible{outline:2px solid #17657b;outline-offset:2px}
@media(max-width:760px){.hospital-shell{display:block}.hospital-sidebar{gap:10px;padding:16px}.hospital-sidebar-nav{display:flex;flex-wrap:wrap}.hospital-sidebar-brand{padding-top:8px}.hospital-topbar{margin:-32px -24px 18px}.hospital-topbar h1{font-size:20px}.hospital-topbar-tools{justify-content:flex-start}.metrics,.grid{grid-template-columns:1fr}.card{padding:18px}}
@media print{body{background:white;color:#173246}.hospital-shell{display:block}.hospital-sidebar,.hospital-topbar,.no-print{display:none!important}main{max-width:80mm;padding:3mm}.card{border:0;box-shadow:none;padding:0}.notice{border:1px solid #777;background:white}.total{font-size:21px}h1{font-size:20px}dl{font-size:12px}}
</style>
@vite(['resources/css/app.css','resources/js/app.js'])
@include('fbr-pos.partials.collection-workspace-controls')
@include('partials.urdu-font')
</head>
<body><div class="hospital-shell">
<aside class="hospital-sidebar no-print">
<a class="hospital-identity" href="/fbr-pos/hospital/dashboard">TaxNest<br>Hospital POS</a>
<nav class="hospital-sidebar-nav" aria-label="{{ __('hospital.title') }}">
<a @class(['active'=>request()->is('fbr-pos/hospital/dashboard')]) href="/fbr-pos/hospital/dashboard">Overview</a>
<a @class(['active'=>request()->is('fbr-pos/hospital')]) href="/fbr-pos/hospital">Collect Payment</a>
<a @class(['active'=>request()->routeIs('fbrpos.hospital.patient-ledger')]) href="/fbr-pos/hospital/patient-ledger{{ !empty($hospitalUnassigned)?'?unassigned=1':'' }}">Patient Ledger</a>
<a @class(['active'=>request('status')==='partial']) href="/fbr-pos/hospital/history?status=partial">Outstanding</a>
<a @class(['active'=>request()->routeIs('fbrpos.hospital.history') && request('status')!=='partial']) href="/fbr-pos/hospital/history{{ !empty($hospitalUnassigned)?'?unassigned=1':'' }}">Receipts</a>
@if(\App\Services\PosAccessService::dayCloseAllowed(Auth::guard('fbrpos')->user(),$company))<a @class(['active'=>request()->routeIs('fbrpos.hospital.day-close')]) href="/fbr-pos/hospital/day-close{{ !empty($hospitalUnassigned)?'?unassigned=1':'' }}">Day Close</a>@endif
@if($hospitalIsOwner ?? false)<a @class(['active'=>request()->is('fbr-pos/hospital/settings*')]) href="/fbr-pos/hospital/settings">Settings</a>@endif
</nav>
<div class="hospital-sidebar-brand"><strong>TaxNest POS</strong><a href="https://taxnest.pk">taxnest.pk</a></div>
</aside>
<main>
<header class="hospital-topbar"><div><p class="muted">{{ __('hospital.title') }}</p><h1>{{ $company->name }}</h1></div><div class="hospital-topbar-tools no-print">
@if(isset($hospitalCampuses)&&$hospitalCampuses->count()>1)<form class="campus-switcher" action="/fbr-pos/hospital/campus" method="post">@csrf<label class="sr-only" for="hospital-campus">{{ __('hospital.campus') }}</label><select id="hospital-campus" name="branch_id" onchange="this.form.requestSubmit()">@foreach($hospitalCampuses as $campus)<option value="{{ $campus->id }}" @selected((int)$hospitalCampusId===(int)$campus->id)>{{ $campus->name }}</option>@endforeach</select><noscript><button>{{ __('hospital.campus') }}</button></noscript></form>
@elseif(isset($hospitalCampuses)&&$hospitalCampuses->count()===1)<span class="muted">{{ $hospitalCampuses->first()->name }}</span>@else<span class="muted">{{ __('hospital_workspace.no_branch') }}</span>@endif
@php($hospitalImsOn = $company->fbr_reporting_enabled && $company->fbrPosIntegrationConfigured())
<span @class(['fiscal-badge','on'=>$hospitalImsOn]) id="hospital-fiscal-status" role="status">{{ $hospitalImsOn ? 'FBR IMS ON' : 'Fiscal OFF' }}</span>
@if(is_array(session('impersonation')))<form method="post" action="{{ route('saas.admin.impersonation.stop') }}">@csrf<button>{{ __('hospital_workspace.exit_company') }}</button></form>@else<form method="post" action="/fbr-pos/logout">@csrf<button>{{ __('pos.logout') }}</button></form>@endif
</div></header>
@if(!empty($hospitalUnassigned))<p class="notice">{{ __('hospital.unassigned_notice') }}</p>@endif
@unless($hospitalIsOwner ?? false)
<details class="no-print" id="hospital-workspace-menu"><summary>{{ __('hospital_workspace.menu') }}</summary><nav class="actions">
<a href="/fbr-pos/my-profile">{{ __('hospital_workspace.my_profile') }}</a><a href="/product-guide#hospital-standalone">{{ __('hospital_workspace.help') }}</a>
</nav><form method="post" action="/fbr-pos/set-language">@csrf<label for="hospital-language">{{ __('hospital_workspace.language') }}</label><select id="hospital-language" name="language"><option value="en" @selected(app()->getLocale()==='en')>English</option><option value="rur" @selected(app()->getLocale()==='rur')>Roman Urdu</option><option value="ur" @selected(app()->getLocale()==='ur')>اردو</option></select><button>{{ __('hospital_workspace.save_language') }}</button></form></details>
@endunless
<x-prospective-policy-reminder guard="fbrpos" />
@if(isset($slot))<section class="hospital-shared-page">{{ $slot }}</section>@else @yield('content') @endif
<script>window.addEventListener('pageshow',function(event){if(event.persisted)window.location.reload();});</script>
</main></div>
@if($showInteractiveSupport ?? true)<footer class="no-print collection-support-dock"><x-madadgar-support product="fbrpos" placement="inline" /></footer>@endif
</body></html>