<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center space-x-2 text-sm text-gray-500 dark:text-gray-400">
            <a href="/dashboard" class="hover:text-emerald-600 dark:hover:text-emerald-400">Dashboard</a>
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <a href="/billing/plans" class="hover:text-emerald-600 dark:hover:text-emerald-400">Billing</a>
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-gray-800 dark:text-gray-200 font-semibold">Custom Plan</span>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8" x-data="customPlanBuilder()">

            <div class="text-center mb-8">
                <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Build Your Custom Plan</h2>
                <p class="text-gray-500 dark:text-gray-400 mt-2">Configure exact limits for your business needs and get instant pricing.</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 space-y-6">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-6">Configure Limits</h3>

                        <div class="space-y-8">
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Invoice Limit (per month)</label>
                                    <span class="text-sm font-bold text-emerald-600 dark:text-emerald-400" x-text="Number(invoiceLimit).toLocaleString()"></span>
                                </div>
                                <input type="range" min="50" max="100000" step="50" x-model="invoiceLimit" @input="calculate()"
                                    class="w-full h-2 bg-gray-200 dark:bg-gray-700 rounded-lg appearance-none cursor-pointer accent-emerald-600">
                                <div class="flex justify-between text-xs text-gray-400 dark:text-gray-500 mt-1">
                                    <span>50</span>
                                    <span>25,000</span>
                                    <span>50,000</span>
                                    <span>100,000</span>
                                </div>
                            </div>

                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">User Count</label>
                                    <span class="text-sm font-bold text-emerald-600 dark:text-emerald-400" x-text="userCount"></span>
                                </div>
                                <input type="range" min="1" max="500" step="1" x-model="userCount" @input="calculate()"
                                    class="w-full h-2 bg-gray-200 dark:bg-gray-700 rounded-lg appearance-none cursor-pointer accent-emerald-600">
                                <div class="flex justify-between text-xs text-gray-400 dark:text-gray-500 mt-1">
                                    <span>1</span>
                                    <span>125</span>
                                    <span>250</span>
                                    <span>500</span>
                                </div>
                            </div>

                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Branch Count</label>
                                    <span class="text-sm font-bold text-emerald-600 dark:text-emerald-400" x-text="branchCount"></span>
                                </div>
                                <input type="range" min="1" max="100" step="1" x-model="branchCount" @input="calculate()"
                                    class="w-full h-2 bg-gray-200 dark:bg-gray-700 rounded-lg appearance-none cursor-pointer accent-emerald-600">
                                <div class="flex justify-between text-xs text-gray-400 dark:text-gray-500 mt-1">
                                    <span>1</span>
                                    <span>25</span>
                                    <span>50</span>
                                    <span>100</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Billing Cycle</h3>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                            <button type="button" @click="billingCycle = 'monthly'; calculate()"
                                :class="billingCycle === 'monthly' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 ring-2 ring-emerald-200 dark:ring-emerald-800' : 'border-gray-200 dark:border-gray-600 hover:border-gray-300'"
                                class="relative p-4 rounded-xl border-2 text-center transition cursor-pointer">
                                <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">Monthly</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">No discount</p>
                            </button>
                            <button type="button" @click="billingCycle = 'quarterly'; calculate()"
                                :class="billingCycle === 'quarterly' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 ring-2 ring-emerald-200 dark:ring-emerald-800' : 'border-gray-200 dark:border-gray-600 hover:border-gray-300'"
                                class="relative p-4 rounded-xl border-2 text-center transition cursor-pointer">
                                <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">Quarterly</p>
                                <span class="inline-block mt-1 px-2 py-0.5 bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400 text-xs font-bold rounded-full">-1%</span>
                            </button>
                            <button type="button" @click="billingCycle = 'semi_annual'; calculate()"
                                :class="billingCycle === 'semi_annual' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 ring-2 ring-emerald-200 dark:ring-emerald-800' : 'border-gray-200 dark:border-gray-600 hover:border-gray-300'"
                                class="relative p-4 rounded-xl border-2 text-center transition cursor-pointer">
                                <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">Semi-Annual</p>
                                <span class="inline-block mt-1 px-2 py-0.5 bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400 text-xs font-bold rounded-full">-3%</span>
                            </button>
                            <button type="button" @click="billingCycle = 'annual'; calculate()"
                                :class="billingCycle === 'annual' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 ring-2 ring-emerald-200 dark:ring-emerald-800' : 'border-gray-200 dark:border-gray-600 hover:border-gray-300'"
                                class="relative p-4 rounded-xl border-2 text-center transition cursor-pointer">
                                <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">Annual</p>
                                <span class="inline-block mt-1 px-2 py-0.5 bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400 text-xs font-bold rounded-full">-6%</span>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6 sticky top-6">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Price Summary</h3>

                        <div x-show="loading" class="flex items-center justify-center py-8">
                            <svg class="animate-spin h-6 w-6 text-emerald-500" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        </div>

                        <div x-show="pricing && !loading" class="space-y-4">
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between text-gray-600 dark:text-gray-400">
                                    <span>Invoices</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200">Rs. <span x-text="pricing?.breakdown?.invoices?.toLocaleString()"></span></span>
                                </div>
                                <div class="flex justify-between text-gray-600 dark:text-gray-400">
                                    <span>Users</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200">Rs. <span x-text="pricing?.breakdown?.users?.toLocaleString()"></span></span>
                                </div>
                                <div class="flex justify-between text-gray-600 dark:text-gray-400">
                                    <span>Branches</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200">Rs. <span x-text="pricing?.breakdown?.branches?.toLocaleString()"></span></span>
                                </div>
                                <div class="border-t border-gray-200 dark:border-gray-600 pt-2 flex justify-between font-medium text-gray-700 dark:text-gray-300">
                                    <span>Base Rate/mo</span>
                                    <span>Rs. <span x-text="pricing?.base_rate_monthly?.toLocaleString()"></span></span>
                                </div>
                            </div>

                            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-3 space-y-2 text-sm">
                                <div class="flex justify-between text-gray-600 dark:text-gray-400">
                                    <span>Duration</span>
                                    <span class="font-medium" x-text="pricing?.months + ' month' + (pricing?.months > 1 ? 's' : '')"></span>
                                </div>
                                <div class="flex justify-between text-gray-600 dark:text-gray-400">
                                    <span>Subtotal</span>
                                    <span class="font-medium">Rs. <span x-text="pricing?.total_before_discount?.toLocaleString()"></span></span>
                                </div>
                                <div x-show="pricing?.discount_percent > 0" class="flex justify-between text-emerald-600 dark:text-emerald-400">
                                    <span>Discount (<span x-text="pricing?.discount_percent"></span>%)</span>
                                    <span class="font-medium">- Rs. <span x-text="pricing?.discount_amount?.toLocaleString()"></span></span>
                                </div>
                            </div>

                            <div class="border-t border-gray-200 dark:border-gray-600 pt-3">
                                <div class="flex justify-between items-center">
                                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">Total</span>
                                    <span class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">Rs. <span x-text="pricing?.final_price?.toLocaleString()"></span></span>
                                </div>
                                <p class="text-xs text-gray-500 dark:text-gray-400 text-right mt-1">
                                    Effective: Rs. <span x-text="pricing?.monthly_effective?.toLocaleString()"></span>/mo
                                </p>
                            </div>

                            <form method="POST" action="/billing/subscribe-custom">
                                @csrf
                                <input type="hidden" name="invoice_limit" :value="invoiceLimit">
                                <input type="hidden" name="user_count" :value="userCount">
                                <input type="hidden" name="branch_count" :value="branchCount">
                                <input type="hidden" name="billing_cycle" :value="billingCycle">
                                <button type="submit" class="w-full mt-2 py-3 bg-emerald-600 text-white rounded-xl font-semibold text-sm hover:bg-emerald-700 transition shadow-sm">
                                    Subscribe to Custom Plan
                                </button>
                            </form>
                        </div>

                        <div x-show="!pricing && !loading" class="text-center py-8 text-gray-400 dark:text-gray-500">
                            <p class="text-sm">Adjust sliders to see pricing</p>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script>
        function customPlanBuilder() {
            return {
                invoiceLimit: 500,
                userCount: 5,
                branchCount: 1,
                billingCycle: 'monthly',
                pricing: null,
                loading: false,
                debounceTimer: null,

                init() {
                    this.calculate();
                },

                calculate() {
                    clearTimeout(this.debounceTimer);
                    this.debounceTimer = setTimeout(() => this.fetchPricing(), 300);
                },

                async fetchPricing() {
                    this.loading = true;
                    try {
                        let res = await fetch('/billing/calculate-custom', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify({
                                invoice_limit: parseInt(this.invoiceLimit),
                                user_count: parseInt(this.userCount),
                                branch_count: parseInt(this.branchCount),
                                billing_cycle: this.billingCycle
                            })
                        });
                        if (res.ok) {
                            this.pricing = await res.json();
                        }
                    } catch(e) {
                        console.error('Pricing calculation failed:', e);
                    }
                    this.loading = false;
                }
            };
        }
    </script>
</x-app-layout>
