<!doctype html>
<html lang="{{ app()->getLocale() === 'ur' ? 'ur' : 'en' }}" dir="{{ app()->getLocale() === 'ur' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8"><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('pos.school_fee_desk') }} · {{ $company->name }}</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f3f6fa;color:#183044;font:16px/1.55 system-ui,sans-serif}main{max-width:980px;margin:32px auto;padding:0 20px}header{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;margin-bottom:24px}h1{font-size:28px;margin:0}h2{font-size:20px}.brand{color:#096c73;font-weight:700}.card{background:white;border:1px solid #d9e2ec;border-radius:16px;padding:24px;margin:18px 0;box-shadow:0 3px 12px #18304406}.notice{padding:16px;border:1px solid #ebd59b;background:#fff9e9;border-radius:10px;font-size:14px}.muted{color:#526777;font-size:14px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}label{display:block;font-weight:600;margin-bottom:6px}input,select{width:100%;font:inherit;padding:11px;border:1px solid #b8c8d5;border-radius:8px;background:white}button,.button{display:inline-block;padding:12px 20px;border:0;border-radius:8px;background:#096c73;color:white;font:inherit;font-weight:600;text-decoration:none;cursor:pointer}button:disabled{opacity:.55}a{color:#096c73}.actions{display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-top:22px}.error{color:#9b1c1c}table{width:100%;border-collapse:collapse}td,th{padding:12px 8px;text-align:start;border-bottom:1px solid #e3eaf0}.scroll{overflow:auto}.total{font-size:28px;font-weight:700}dl{display:grid;grid-template-columns:1fr 1fr;gap:10px}dt{color:#526777}dd{margin:0;text-align:end}nav svg{max-width:24px}.school-shared-page{min-width:0}.school-shared-page .overflow-hidden{overflow-x:auto}details{margin:16px 0}summary{cursor:pointer;font-weight:700}textarea{max-width:100%}img{max-width:100%}form{min-width:0}input,select{min-width:0}#school-workspace-menu select{max-width:240px}#school-workspace-menu button{margin:8px}body main>header svg{max-width:24px}.metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.metric-value{font-size:27px;font-weight:700;margin-top:10px}.campus-switcher{max-width:300px}.steps{padding-left:20px}.cash-confirm{display:flex;gap:10px;align-items:flex-start;margin-top:20px}.cash-confirm input{width:20px;height:20px;flex-shrink:0}nav.actions{padding-bottom:16px;border-bottom:1px solid #d9e2ec}nav.actions a{font-weight:600}@media(max-width:600px){main{margin:18px auto}.metrics{grid-template-columns:1fr}.grid{grid-template-columns:1fr}.card{padding:18px}h1{font-size:23px}}@media print{body{background:white}main{max-width:80mm;margin:0;padding:3mm}.no-print,header{display:none!important}.card{border:0;box-shadow:none;padding:0}.notice{border:1px solid #777;background:white}.total{font-size:21px}h1{font-size:20px}dl{font-size:12px}}

        .school-shell{display:grid;grid-template-columns:240px minmax(0,1fr);min-height:100vh}
        .school-shell main{width:100%;max-width:1280px;min-width:0}
        .school-sidebar{display:flex;flex-direction:column;gap:20px;background:#101e32;color:white;padding:24px 18px}
        .school-sidebar a{color:inherit}.school-identity{font-size:20px;font-weight:800;line-height:1.18;overflow-wrap:anywhere;text-decoration:none}
        .school-sidebar-nav{display:grid;gap:6px}.school-sidebar-nav a{padding:8px 10px;border-radius:8px}
        .school-sidebar-nav a:hover,.school-sidebar-nav a:focus-visible,.school-sidebar-nav a.active{background:#147f72;color:white}
        .school-sidebar p{margin:0}.school-sidebar-brand{display:grid;gap:2px;margin-top:auto;padding-top:16px;border-top:1px solid #3a4a5d}
        .school-sidebar-brand a{color:#92eed4;text-decoration:underline}
        .school-topbar{background:#fff;border-bottom:1px solid #d9e2ec;margin:-32px -20px 24px;padding:20px;align-items:center}.school-topbar h1{font-size:24px}.school-topbar-tools{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap}.school-topbar-tools form{margin:0}.school-topbar-tools select{min-width:180px}.fiscal-badge{display:inline-flex;align-items:center;border-radius:8px;padding:9px 13px;font-weight:800;font-size:13px;background:#f2d49b;color:#70450b}.fiscal-badge.on{background:#d7f3e8;color:#0b664f}
        @media(max-width:760px){.school-shell{display:block}.school-sidebar{gap:10px;padding:16px}.school-sidebar-nav{display:flex;flex-wrap:wrap}.school-sidebar-brand{padding-top:8px}.school-topbar{margin:-18px -20px 18px}.school-topbar h1{font-size:20px}.school-topbar-tools{justify-content:flex-start}}
        @media print{.school-shell{display:block}.school-shell main{max-width:80mm}}
    </style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('fbr-pos.partials.collection-workspace-controls')
    @include('partials.urdu-font')
</head>
<body>
<div class="school-shell">
    <aside class="school-sidebar no-print">
        <a class="school-identity" href="/fbr-pos/dashboard">TaxNest<br>School POS</a>
        <nav class="school-sidebar-nav" aria-label="{{ __('pos.school_navigation') }}">
            <a @class(['active'=>request()->is('fbr-pos/dashboard')]) href="/fbr-pos/dashboard">{{ __('pos.school_dashboard') }}</a>
            <a @class(['active'=>request()->is('fbr-pos/school-fees')]) href="/fbr-pos/school-fees">{{ __('pos.school_nav_collect') }}</a>
            <a @class(['active'=>request()->routeIs('fbrpos.school-fees.student-ledger')]) href="{{ route('fbrpos.school-fees.student-ledger') }}">{{ __('school_audit.student_ledger') }}</a>
            <a @class(['active'=>request()->routeIs('fbrpos.school-fees.outstanding')]) href="{{ route('fbrpos.school-fees.outstanding') }}">{{ __('school_audit.outstanding_fees') }}</a>
            <a @class(['active'=>request()->routeIs('fbrpos.school-fees.reports*')]) href="{{ route('fbrpos.school-fees.reports') }}">Reports</a>
            <a @class(['active'=>request()->is('fbr-pos/transactions*')]) href="/fbr-pos/transactions">{{ __('pos.school_nav_receipts') }}</a>
            @if(\App\Services\PosAccessService::dayCloseAllowed(Auth::guard('fbrpos')->user(), $company))<a @class(['active'=>request()->is('fbr-pos/day-close*')]) href="/fbr-pos/day-close">{{ __('pos.school_nav_close') }}</a>@endif
            @if($schoolCanConfigure ?? false)<a @class(['active'=>request()->is('fbr-pos/school-fees/settings*')]) href="/fbr-pos/school-fees/settings">{{ __('school.settings') }}</a>@endif
        </nav>
        <div class="school-sidebar-brand">
            <strong>TaxNest POS</strong>
            <a href="https://taxnest.pk">taxnest.pk</a>
        </div>
    </aside>
    <main>
<header class="school-topbar"><h1>{{ $company->name }}</h1><div class="school-topbar-tools no-print">
@if(isset($schoolCampuses) && $schoolCampuses->count() > 1)
<form id="school-campus-switcher" class="campus-switcher" method="post" action="/fbr-pos/school-fees/campus">
@csrf
<label class="sr-only" for="school-campus">{{ __('pos.school_campus') }}</label><select id="school-campus" name="branch_id" onchange="this.form.requestSubmit()">
@foreach($schoolCampuses as $campus)<option value="{{ $campus->id }}" @selected((int) $schoolCampusId === (int) $campus->id)>{{ $campus->name }}</option>@endforeach
</select><noscript><button type="submit">{{ __('pos.school_switch_campus') }}</button></noscript>
</form>
@elseif(isset($schoolCampuses) && $schoolCampuses->count() === 1)
<span class="muted">{{ $schoolCampuses->first()->name }}</span>
@else
<span class="muted">{{ __('school.no_campus') }}</span>
@endif
<span @class(['fiscal-badge','on'=>(bool)$company->fbr_reporting_enabled]) id="school-fiscal-status" role="status">{{ $company->fbr_reporting_enabled ? 'FBR IMS ON' : 'Fiscal OFF' }}</span>
@if(is_array(session('impersonation')))<form method="post" action="{{ route('saas.admin.impersonation.stop') }}">@csrf<button type="submit">{{ __('school_workspace.exit_company') }}</button></form>@else<form method="post" action="/fbr-pos/logout">@csrf<button type="submit">{{ __('pos.logout') }}</button></form>@endif
</div></header>
@unless($schoolCanConfigure ?? false)
<details class="no-print" id="school-workspace-menu"><summary>{{ __('school_workspace.workspace_menu') }}</summary><nav class="actions" aria-label="{{ __('school_workspace.workspace_menu') }}">
<a href="/fbr-pos/my-profile">{{ __('school_workspace.my_profile') }}</a><a href="#school-help-guide">{{ __('school_workspace.help') }}</a>
</nav><form method="post" action="/fbr-pos/set-language">@csrf<label for="school-language">{{ __('school_workspace.language') }}</label><select id="school-language" name="language"><option value="en" @selected(app()->getLocale()==='en')>English</option><option value="rur" @selected(app()->getLocale()==='rur')>Roman Urdu</option><option value="ur" @selected(app()->getLocale()==='ur')>اردو</option></select><button type="submit">{{ __('school_workspace.save_language') }}</button></form></details>
@endunless
<details class="no-print" id="school-help-guide"><summary>{{ __('school_workspace.help') }}</summary><p>{{ __('school_audit.guide') }}</p><a href="/product-guide#school-standalone">{{ __('school_workspace.help') }}</a><p>{{ __('school_ledger.pending_recovery') }}</p><p>{{ __('school_ledger.record_only') }}</p><a href="/fbr-pos/suggestions">{{ __('school_audit.support') }}</a></details>
<x-prospective-policy-reminder guard="fbrpos" />
@if(isset($slot))<section class="school-shared-page">{{ $slot }}</section>@else @yield('content') @endif
<script>
// A bfcache page can predate a category cutover or deploy. Reload only a restored
// GET page; pending collection keys remain in sessionStorage for safe recovery.
window.addEventListener('pageshow', function(event) { if(event.persisted) window.location.reload(); });
</script>
</main>
</div>
@if($showInteractiveSupport ?? true)<footer class="no-print collection-support-dock"><x-madadgar-support product="fbrpos" placement="inline" /></footer>@endif</body></html>
