<x-pos-layout>
<div class="max-w-3xl mx-auto p-4">
<h1 class="text-xl font-bold">{{ __('online_payment_policy.title') }}</h1>
<p>{{ __('online_payment_policy.description') }}</p>
<a href="{{ route('pos.team') }}">{{ __('online_payment_policy.back') }}</a>
@foreach($cashiers as $cashier)
<form method="POST" action="{{ route('pos.team.online-payment-policy.update', $cashier->id) }}" class="border rounded p-4 my-3">
@csrf @method('PUT')
<strong>{{ $cashier->name }}</strong>
<label class="block"><input type="hidden" name="requires_approval" value="0"><input type="checkbox" name="requires_approval" value="1" @checked(in_array($cashier->id, $enabled))> {{ __('online_payment_policy.require') }}</label>
<button type="submit" class="border rounded p-2">{{ __('online_payment_policy.save') }}</button>
</form>
@endforeach
</div>
</x-pos-layout>
