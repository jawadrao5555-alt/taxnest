<x-pos-layout>
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">PRA Integration Settings</h1>

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 text-sm">{{ session('success') }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">PRA Configuration</h3>
                <form method="POST" action="{{ route('pos.pra-settings') }}" class="space-y-4">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Environment</label>
                        <select name="pra_environment" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
                            <option value="sandbox" {{ $company->pra_environment === 'sandbox' ? 'selected' : '' }}>Sandbox (Testing)</option>
                            <option value="production" {{ $company->pra_environment === 'production' ? 'selected' : '' }}>Production (Live)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">POS Registration ID (from PRA portal)</label>
                        <input type="text" name="pra_pos_id" value="{{ $company->pra_pos_id }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500" placeholder="e.g. 100000">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Production Token</label>
                        <input type="text" name="pra_production_token" value="{{ $company->pra_production_token }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500" placeholder="Token from PRA POS registration">
                        <p class="text-xs text-gray-400 mt-1">Sandbox uses default test token. Production token is available on PRA registration screen.</p>
                    </div>
                    <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Receipt Printer Size</label>
                        <select name="receipt_printer_size" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
                            <option value="80mm" {{ ($company->receipt_printer_size ?? '80mm') === '80mm' ? 'selected' : '' }}>80mm (Standard)</option>
                            <option value="58mm" {{ ($company->receipt_printer_size ?? '80mm') === '58mm' ? 'selected' : '' }}>58mm (Compact)</option>
                        </select>
                        <p class="text-xs text-gray-400 mt-1">Select the thermal printer paper size used at your POS terminals.</p>
                    </div>
                    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-3">
                        <p class="text-xs text-blue-700 dark:text-blue-400">
                            <strong>API Endpoints:</strong><br>
                            Sandbox: https://ims.pral.com.pk/ims/sandbox/api/Live/PostData<br>
                            Production: https://ims.pral.com.pk/ims/production/api/Live/PostData
                        </p>
                    </div>
                    <button type="submit" class="px-6 py-2 bg-emerald-600 text-white text-sm rounded-lg hover:bg-emerald-700 transition">Save Settings</button>
                </form>
            </div>
        </div>

        <div class="space-y-6">
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Status</h3>
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">PRA Reporting</span>
                        <span class="{{ $company->pra_reporting_enabled ? 'text-emerald-600 font-semibold' : 'text-red-500' }}">
                            {{ $company->pra_reporting_enabled ? 'Enabled' : 'Disabled' }}
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Environment</span>
                        <span class="text-gray-900 dark:text-white">{{ ucfirst($company->pra_environment ?? 'sandbox') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">POS ID</span>
                        <span class="text-gray-900 dark:text-white">{{ $company->pra_pos_id ?? 'Not Set' }}</span>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Recent PRA Logs</h3>
                @forelse($praLogs as $log)
                <div class="border-b border-gray-100 dark:border-gray-800 last:border-0 py-2">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium {{ $log->status === 'success' ? 'text-emerald-600' : ($log->status === 'failed' ? 'text-red-600' : 'text-amber-600') }}">
                            {{ strtoupper($log->status) }}
                        </span>
                        <span class="text-xs text-gray-400">{{ $log->created_at->diffForHumans() }}</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-0.5">Code: {{ $log->response_code ?? 'N/A' }}</p>
                </div>
                @empty
                <p class="text-xs text-gray-400">No PRA logs yet</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
</x-pos-layout>