<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-xl text-gray-800 leading-tight">Billing & Plans</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            @if($currentSubscription)
            <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-6 mb-8">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-emerald-800">Current Plan: {{ $currentSubscription->pricingPlan->name }}</h3>
                        <p class="text-sm text-emerald-600 mt-1">
                            Active until {{ \Carbon\Carbon::parse($currentSubscription->end_date)->format('d M Y') }}
                            &middot; Invoice Limit: {{ $currentSubscription->pricingPlan->invoice_limit }}
                        </p>
                    </div>
                    <span class="inline-flex px-3 py-1 bg-emerald-100 text-emerald-700 rounded-full text-sm font-medium">Active</span>
                </div>
            </div>
            @endif

            <div class="text-center mb-8">
                <h3 class="text-2xl font-bold text-gray-900">Choose Your Plan</h3>
                <p class="text-gray-500 mt-2">Select the plan that best fits your business needs</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                @foreach($plans as $plan)
                <div class="bg-white rounded-xl shadow-sm border-2 transition hover:shadow-md
                    {{ $currentSubscription && $currentSubscription->pricing_plan_id === $plan->id ? 'border-emerald-500' : 'border-gray-100' }}">
                    <div class="p-6">
                        @if($currentSubscription && $currentSubscription->pricing_plan_id === $plan->id)
                        <span class="inline-flex px-2 py-0.5 bg-emerald-100 text-emerald-700 rounded text-xs font-medium mb-3">Current Plan</span>
                        @endif
                        <h3 class="text-xl font-bold text-gray-900">{{ $plan->name }}</h3>
                        <div class="mt-4">
                            <span class="text-4xl font-bold text-gray-900">Rs. {{ number_format($plan->price) }}</span>
                            <span class="text-gray-500">/month</span>
                        </div>
                        <ul class="mt-6 space-y-3">
                            <li class="flex items-center text-sm text-gray-600">
                                <svg class="w-5 h-5 text-emerald-500 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                Up to {{ $plan->invoice_limit }} invoices
                            </li>
                            <li class="flex items-center text-sm text-gray-600">
                                <svg class="w-5 h-5 text-emerald-500 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                FBR Integration
                            </li>
                            <li class="flex items-center text-sm text-gray-600">
                                <svg class="w-5 h-5 text-emerald-500 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                PDF Generation
                            </li>
                        </ul>
                        <form method="POST" action="/billing/subscribe" class="mt-6">
                            @csrf
                            <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                            <button type="submit"
                                class="w-full py-2.5 rounded-lg font-semibold text-sm transition
                                {{ $currentSubscription && $currentSubscription->pricing_plan_id === $plan->id
                                    ? 'bg-gray-100 text-gray-400 cursor-not-allowed'
                                    : 'bg-emerald-600 text-white hover:bg-emerald-700' }}"
                                {{ $currentSubscription && $currentSubscription->pricing_plan_id === $plan->id ? 'disabled' : '' }}>
                                {{ $currentSubscription && $currentSubscription->pricing_plan_id === $plan->id ? 'Current Plan' : 'Select Plan' }}
                            </button>
                        </form>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>
