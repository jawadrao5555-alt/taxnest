@extends('fbr-pos.hospital.layout')
@section('content')
<h1>{{ __('hospital_ledger.dashboard') }}</h1><p>{{ $date }} · {{ __('hospital_ledger.today') }}</p>
<div class="metrics">
@foreach(['cash','card','bank'] as $method)<section class="card"><h2>{{ __('hospital.'.$method) }}</h2><div id="hospital-today-{{ $method }}" data-paisa="{{ $totals[$method.'_paisa'] }}">PKR {{ number_format($totals[$method.'_paisa']/100,2) }}</div></section>@endforeach
<section class="card"><h2>{{ __('hospital.count') }}</h2><div id="hospital-today-count">{{ $totals['receipt_count'] }}</div></section>
<section class="card"><h2>{{ __('hospital.day_close') }}</h2><p>{{ __($closed ? 'hospital.closed_label' : 'hospital.open') }}</p></section>
</div><div class="actions"><a href="/fbr-pos/hospital">{{ __('hospital.collect') }}</a><a href="/fbr-pos/hospital/history">{{ __('hospital.history') }}</a></div>
<p>{{ __('hospital_ledger.record_only') }}</p>
@endsection
