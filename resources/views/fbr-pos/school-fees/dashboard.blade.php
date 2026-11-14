@extends('fbr-pos.school-fees.layout')
@section('content')
<h1>{{ __('pos.school_dashboard_title') }}</h1>
<p class="muted">{{ $date }} · {{ __('pos.school_dashboard_scope') }}</p>
<div class="metrics">
<section class="card"><h2>{{ __('pos.school_today_collections') }}</h2><div class="metric-value" id="school-today-cash" data-paisa="{{ $cashAmountPaisa }}">PKR {{ number_format($cashAmountPaisa / 100, 2) }}</div></section>
<section class="card"><h2>{{ __('pos.school_receipt_count') }}</h2><div class="metric-value" id="school-today-count">{{ $receiptCount }}</div></section>
<section class="card"><h2>{{ __('pos.school_day_close_status') }}</h2><div class="metric-value" id="school-today-status">{{ __($closedReport ? 'pos.school_closed' : 'pos.school_open') }}</div>@if($closedReport)<p class="muted">SC-{{ $closedReport->id }} · {{ $closedReport->closed_at }}</p>@endif</section>
</div>
<div class="actions"><a class="button" href="/fbr-pos/school-fees">{{ __('pos.school_nav_collect') }}</a><a href="/fbr-pos/transactions">{{ __('pos.school_nav_receipts') }}</a></div>
<div class="notice" style="margin-top:24px">{{ __('pos.fbr_missing_school_route') }}</div>
<p class="muted">{{ __('pos.school_pilot_scope') }}</p>
@endsection
