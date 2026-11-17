@extends('fbr-pos.school-fees.layout')
@section('content')
<h1>{{ __('pos.school_dashboard_title') }}</h1>
<p class="muted">{{ $date }} · {{ __('pos.school_dashboard_scope') }}</p>
<div class="metrics">
<section class="card"><h2>{{ __('school_audit.today_total') }}</h2><div class="metric-value" id="school-today-total" data-paisa="{{ $totalAmountPaisa }}">PKR {{ number_format($totalAmountPaisa/100,2) }}</div></section>
<section class="card"><h2>{{ __('school_ledger.card') }}</h2><div class="metric-value" id="school-today-card" data-paisa="{{ $cardAmountPaisa }}">PKR {{ number_format($cardAmountPaisa/100,2) }}</div></section>
<section class="card"><h2>{{ __('school_ledger.bank') }}</h2><div class="metric-value" id="school-today-bank" data-paisa="{{ $bankAmountPaisa }}">PKR {{ number_format($bankAmountPaisa/100,2) }}</div></section>
<section class="card"><h2>{{ __('school_ledger.balance') }}</h2><div class="metric-value" id="school-outstanding" data-paisa="{{ $outstandingPaisa }}">PKR {{ number_format($outstandingPaisa/100,2) }}</div><p>{{ __('school_audit.balance_scope') }}</p></section>
<section class="card"><h2>{{ __('school_ledger.today_cash') }}</h2><div class="metric-value" id="school-today-cash" data-paisa="{{ $cashAmountPaisa }}">PKR {{ number_format($cashAmountPaisa / 100, 2) }}</div></section>
<section class="card"><h2>{{ __('pos.school_receipt_count') }}</h2><div class="metric-value" id="school-today-count">{{ $receiptCount }}</div></section>
<section class="card"><h2>{{ __('pos.school_day_close_status') }}</h2><div class="metric-value" id="school-today-status">{{ __($closedReport ? 'pos.school_closed' : 'school_audit.not_closed') }}</div>@if($closedReport)<p class="muted">SC-{{ $closedReport->id }} · {{ $closedReport->closed_at }}</p>@endif</section>
</div>
<div class="actions"><a class="button" href="/fbr-pos/school-fees">{{ __('pos.school_nav_collect') }}</a><a href="/fbr-pos/transactions">{{ __('pos.school_nav_receipts') }}</a></div>
@if($schoolCanConfigure ?? false)
<section class="card" id="school-guided-setup"><h2>{{ __('school_audit.setup') }}</h2><ol class="steps"><li><a href="/fbr-pos/school-fees/printing">{{ __('school_workspace.profile_printing') }}</a></li><li><a href="/fbr-pos/school-fees/settings">{{ __('school.settings') }}</a></li><li><a href="/fbr-pos/team">{{ __('school_workspace.team_access') }}</a></li><li><a href="/fbr-pos/billing">{{ __('school_workspace.billing') }}</a></li></ol><p>{{ __('school_audit.setup_help') }}</p></section>
@endif
<p class="muted">{{ __('school_ledger.pilot_scope') }}</p>
@endsection
