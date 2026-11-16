@extends('fbr-pos.hospital.layout')
@section('content')
<h1>{{ __('hospital_ledger.dashboard') }}</h1><p>{{ $date }} · {{ __('hospital_ledger.today') }}</p>
<p id="hospital-active-campus">{{ __('hospital.campus') }}: {{ $campusName }}</p>
<div class="metrics">
<section class="card"><h2>{{ __('collection_audit.total') }}</h2><div id="hospital-today-total" data-paisa="{{ $totals['cash_paisa']+$totals['card_paisa']+$totals['bank_paisa'] }}">PKR {{ number_format(($totals['cash_paisa']+$totals['card_paisa']+$totals['bank_paisa'])/100,2) }}</div></section>
<section class="card"><h2>{{ __('collection_audit.outstanding') }}</h2><div id="hospital-outstanding" data-paisa="{{ $outstanding }}">PKR {{ number_format($outstanding/100,2) }}</div><a href="/fbr-pos/hospital/history?status=partial">{{ __('collection_audit.review_balances') }}</a></section>
@foreach(['cash','card','bank'] as $method)<section class="card"><h2>{{ __('hospital.'.$method) }}</h2><div id="hospital-today-{{ $method }}" data-paisa="{{ $totals[$method.'_paisa'] }}">PKR {{ number_format($totals[$method.'_paisa']/100,2) }}</div></section>@endforeach
<section class="card"><h2>{{ __('hospital.count') }}</h2><div id="hospital-today-count">{{ $totals['receipt_count'] }}</div></section>
<section class="card"><h2>{{ __('hospital.day_close') }}</h2><p>{{ __($closed ? 'hospital.closed_label' : 'hospital.open') }}</p></section>
</div><div class="actions"><a href="/fbr-pos/hospital">{{ __('hospital.collect') }}</a><a href="/fbr-pos/hospital/history">{{ __('hospital.history') }}</a></div>
<p>{{ __('hospital_ledger.record_only') }}</p>
@endsection
