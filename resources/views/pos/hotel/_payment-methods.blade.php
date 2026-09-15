@php
    $hotelPayMethods = $hotelPayMethods ?? ['cash', 'card', 'debit_card', 'credit_card', 'qr_payment'];
@endphp
@foreach($hotelPayMethods as $method)
<option value="{{ $method }}">{{ \App\Support\PosPaymentLabels::label($method) }}</option>
@endforeach
