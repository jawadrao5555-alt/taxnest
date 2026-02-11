<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-xl text-gray-800 leading-tight">Billing & Plans</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">

            @if($currentSubscription && $usageData)
                @if($usageData['is_expired'])
                <div class="bg-red-50 border border-red-200 rounded-xl p-6 mb-6">
                    <div class="flex items-center space-x-3">
                        <svg class="w-8 h-8 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.268 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        <div>
                            <h3 class="text-lg font-semibold text-red-800">Subscription Expired!</h3>
                            <p class="text-sm text-red-600">Your subscription has expired. Please renew to continue creating invoices.</p>
                        </div>
                    </div>
                </div>
                @elseif($usageData['is_expiring_soon'])
                <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 mb-6" x-data="{ days: {{ $usageData['days_left'] }} }">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <svg class="w-8 h-8 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <div>
                                <h3 class="text-lg font-semibold text-yellow-800">Subscription Expiring Soon</h3>
                                <p class="text-sm text-yellow-600"><span class="font-bold" x-text="days">{{ $usageData['days_left'] }}</span> days remaining on your {{ $currentSubscription->pricingPlan->name }} plan</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-2xl font-bold text-yellow-800">{{ $usageData['days_left'] }}</p>
                            <p class="text-xs text-yellow-600">days left</p>
                        </div>
                    </div>
                </div>
                @endif

                @if($usageData['needs_upgrade'])
                <div class="bg-orange-50 border border-orange-200 rounded-xl p-6 mb-6">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <svg class="w-8 h-8 text-orange-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                            <div>
                                <h3 class="text-lg font-semibold text-orange-800">Usage Alert - Upgrade Recommended</h3>
                                <p class="text-sm text-orange-600">You've used {{ $usageData['usage_percent'] }}% of your invoice limit ({{ $usageData['invoice_count'] }}/{{ $usageData['invoice_limit'] }}). Consider upgrading for more capacity.</p>
                            </div>
                        </div>
                    </div>
                </div>
                @endif

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-8">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Current Plan: {{ $currentSubscription->pricingPlan->name }}</h3>
                            <p class="text-sm text-gray-500 mt-1">
                                Active until {{ \Carbon\Carbon::parse($currentSubscription->end_date)->format('d M Y') }}
                            </p>
                        </div>
                        <span class="inline-flex px-3 py-1 bg-emerald-100 text-emerald-700 rounded-full text-sm font-medium">Active</span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Invoice Usage</p>
                            <div class="flex items-center space-x-3">
                                <div class="flex-1 bg-gray-200 rounded-full h-3">
                                    <div class="h-3 rounded-full transition-all duration-500
                                        {{ $usageData['usage_percent'] > 80 ? 'bg-red-500' : ($usageData['usage_percent'] > 50 ? 'bg-yellow-500' : 'bg-emerald-500') }}"
                                        style="width: {{ $usageData['usage_percent'] }}%"></div>
                                </div>
                                <span class="text-sm font-medium text-gray-700">{{ $usageData['invoice_count'] }}/{{ $usageData['invoice_limit'] }}</span>
                            </div>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Time Remaining</p>
                            <div class="flex items-center space-x-3">
                                @php $timePercent = $usageData['total_days'] > 0 ? min(100, (($usageData['total_days'] - $usageData['days_left']) / $usageData['total_days']) * 100) : 100; @endphp
                                <div class="flex-1 bg-gray-200 rounded-full h-3">
                                    <div class="h-3 rounded-full bg-blue-500 transition-all duration-500" style="width: {{ $timePercent }}%"></div>
                                </div>
                                <span class="text-sm font-medium text-gray-700">{{ $usageData['days_left'] }} days</span>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <div class="text-center mb-8">
                <h3 class="text-2xl font-bold text-gray-900">Choose Your Plan</h3>
                <p class="text-gray-500 mt-2">Select the plan that best fits your business needs</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6" x-data="{ showModal: false, selectedPlan: null }">
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
                            <li class="flex items-center text-sm text-gray-600">
                                <svg class="w-5 h-5 text-emerald-500 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                Compliance Scoring
                            </li>
                        </ul>
                        @if(!$currentSubscription || $currentSubscription->pricing_plan_id !== $plan->id)
                        <form method="POST" action="/billing/subscribe" class="mt-6">
                            @csrf
                            <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                            <button type="submit" class="w-full py-2.5 rounded-lg font-semibold text-sm transition bg-emerald-600 text-white hover:bg-emerald-700">
                                Select Plan
                            </button>
                        </form>
                        @else
                        <div class="mt-6">
                            <button disabled class="w-full py-2.5 rounded-lg font-semibold text-sm bg-gray-100 text-gray-400 cursor-not-allowed">Current Plan</button>
                        </div>
                        @endif
                    </div>
                </div>
                @endforeach

                <div class="md:col-span-3 text-center mt-4">
                    <button @click="showModal = true" class="text-sm text-emerald-600 hover:text-emerald-700 font-medium underline">
                        Compare All Plans
                    </button>
                </div>

                <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50" @click.self="showModal = false">
                    <div class="bg-white rounded-2xl shadow-2xl max-w-3xl w-full mx-4 max-h-[80vh] overflow-y-auto" @click.stop>
                        <div class="p-6 border-b border-gray-100 flex items-center justify-between">
                            <h3 class="text-xl font-bold text-gray-900">Plan Comparison</h3>
                            <button @click="showModal = false" class="text-gray-400 hover:text-gray-600">
                                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                        <div class="p-6">
                            <table class="w-full">
                                <thead>
                                    <tr class="border-b border-gray-200">
                                        <th class="text-left py-3 text-sm font-medium text-gray-500">Feature</th>
                                        @foreach($plans as $plan)
                                        <th class="text-center py-3 text-sm font-bold text-gray-900">{{ $plan->name }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr>
                                        <td class="py-3 text-sm text-gray-600">Monthly Price</td>
                                        @foreach($plans as $plan)
                                        <td class="py-3 text-center text-sm font-semibold">Rs. {{ number_format($plan->price) }}</td>
                                        @endforeach
                                    </tr>
                                    <tr>
                                        <td class="py-3 text-sm text-gray-600">Invoice Limit</td>
                                        @foreach($plans as $plan)
                                        <td class="py-3 text-center text-sm font-semibold">{{ $plan->invoice_limit }}</td>
                                        @endforeach
                                    </tr>
                                    <tr>
                                        <td class="py-3 text-sm text-gray-600">FBR Integration</td>
                                        @foreach($plans as $plan)
                                        <td class="py-3 text-center"><svg class="w-5 h-5 text-emerald-500 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg></td>
                                        @endforeach
                                    </tr>
                                    <tr>
                                        <td class="py-3 text-sm text-gray-600">PDF Generation</td>
                                        @foreach($plans as $plan)
                                        <td class="py-3 text-center"><svg class="w-5 h-5 text-emerald-500 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg></td>
                                        @endforeach
                                    </tr>
                                    <tr>
                                        <td class="py-3 text-sm text-gray-600">Compliance Scoring</td>
                                        @foreach($plans as $plan)
                                        <td class="py-3 text-center"><svg class="w-5 h-5 text-emerald-500 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg></td>
                                        @endforeach
                                    </tr>
                                    <tr>
                                        <td class="py-3 text-sm text-gray-600">Activity Logs</td>
                                        @foreach($plans as $plan)
                                        <td class="py-3 text-center"><svg class="w-5 h-5 text-emerald-500 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg></td>
                                        @endforeach
                                    </tr>
                                    <tr>
                                        <td class="py-3 text-sm text-gray-600">Integrity Verification</td>
                                        @foreach($plans as $plan)
                                        <td class="py-3 text-center"><svg class="w-5 h-5 text-emerald-500 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg></td>
                                        @endforeach
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
