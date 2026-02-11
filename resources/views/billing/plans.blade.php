@extends('layouts.app')

@section('content')

<h2 class="text-2xl font-bold mb-6">Choose Your Plan</h2>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6">

@foreach($plans as $plan)
<div class="bg-white p-6 rounded shadow text-center border-t-4 border-blue-600">
    <h3 class="text-xl font-bold mb-4">{{ $plan->name }}</h3>
    <p class="text-3xl font-bold text-blue-600 mb-2">Rs. {{ $plan->price }}</p>
    <p class="text-gray-600 mb-6">Invoice Limit: {{ $plan->invoice_limit }}</p>

    <form method="POST" action="/billing/subscribe">
        @csrf
        <input type="hidden" name="plan_id" value="{{ $plan->id }}">
        <button class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold px-4 py-2 rounded transition duration-200">
            Select Plan
        </button>
    </form>
</div>
@endforeach

</div>

@endsection
