<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-xl text-gray-800 leading-tight">Risk Settings</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">

            @if(session('success'))
            <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-xl text-green-800 text-sm font-medium">
                {{ session('success') }}
            </div>
            @endif

            @if($errors->any())
            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl text-red-800 text-sm">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-800">Anomaly & Compliance Thresholds</h3>
                    <p class="text-sm text-gray-500 mt-1">Configure the risk detection parameters used by the compliance engine.</p>
                </div>

                <form method="POST" action="/admin/risk-settings" class="space-y-6">
                    @csrf

                    <div>
                        <label for="mom_spike_threshold" class="block text-sm font-medium text-gray-700">MoM Spike Threshold (%)</label>
                        <p class="text-xs text-gray-500 mt-0.5">Percentage increase in month-over-month invoices that triggers anomaly</p>
                        <input type="number" name="mom_spike_threshold" id="mom_spike_threshold" value="{{ old('mom_spike_threshold', $settings['mom_spike_threshold']) }}" min="50" max="1000" step="1" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm">
                    </div>

                    <div>
                        <label for="tax_drop_threshold" class="block text-sm font-medium text-gray-700">Tax Drop Threshold (%)</label>
                        <p class="text-xs text-gray-500 mt-0.5">Percentage drop in effective tax rate that triggers anomaly</p>
                        <input type="number" name="tax_drop_threshold" id="tax_drop_threshold" value="{{ old('tax_drop_threshold', $settings['tax_drop_threshold']) }}" min="10" max="100" step="1" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm">
                    </div>

                    <div>
                        <label for="critical_score_threshold" class="block text-sm font-medium text-gray-700">Critical Score Threshold</label>
                        <p class="text-xs text-gray-500 mt-0.5">Compliance score below this value is classified as CRITICAL</p>
                        <input type="number" name="critical_score_threshold" id="critical_score_threshold" value="{{ old('critical_score_threshold', $settings['critical_score_threshold']) }}" min="10" max="90" step="1" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm">
                    </div>

                    <div>
                        <label for="stability_bonus_weight" class="block text-sm font-medium text-gray-700">Stability Bonus Weight</label>
                        <p class="text-xs text-gray-500 mt-0.5">Maximum bonus points for consistent compliance history</p>
                        <input type="number" name="stability_bonus_weight" id="stability_bonus_weight" value="{{ old('stability_bonus_weight', $settings['stability_bonus_weight']) }}" min="0" max="30" step="1" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm">
                    </div>

                    <div class="pt-4">
                        <button type="submit" class="inline-flex items-center px-6 py-2.5 bg-emerald-600 text-white rounded-lg font-medium text-sm hover:bg-emerald-700 transition shadow-sm">
                            Save Settings
                        </button>
                    </div>
                </form>
            </div>

            <div class="mt-6 flex flex-wrap gap-3">
                <a href="/admin/dashboard" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg font-medium text-sm hover:bg-gray-700 transition">Back to Dashboard</a>
            </div>
        </div>
    </div>
</x-app-layout>
