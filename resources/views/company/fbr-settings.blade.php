<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-xl text-gray-800 leading-tight">FBR Integration Settings</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">

            @if(session('success'))
            <div class="mb-4 p-4 bg-emerald-50 border border-emerald-200 rounded-lg text-emerald-700">{{ session('success') }}</div>
            @endif
            @if(session('error'))
            <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">{{ session('error') }}</div>
            @endif

            <form method="POST" action="/company/fbr-settings" x-data="fbrSettings()" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-6">
                @csrf
                @method('PUT')

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">FBR Registration No</label>
                    <input type="text" name="fbr_registration_no" value="{{ old('fbr_registration_no', $company->fbr_registration_no) }}" placeholder="e.g. 1234567890123" class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">FBR Business Name</label>
                    <input type="text" name="fbr_business_name" value="{{ old('fbr_business_name', $company->fbr_business_name) }}" class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <hr class="border-gray-200">

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Environment</label>
                    <div class="flex gap-4">
                        <label class="flex items-center gap-2 px-4 py-3 rounded-lg border-2 cursor-pointer transition"
                            :class="environment === 'sandbox' ? 'border-amber-400 bg-amber-50' : 'border-gray-200 hover:border-gray-300'">
                            <input type="radio" name="fbr_environment" value="sandbox" x-model="environment" class="text-amber-500 focus:ring-amber-500">
                            <div>
                                <span class="font-medium text-gray-800">Sandbox</span>
                                <p class="text-xs text-gray-500">Test environment for development</p>
                            </div>
                        </label>
                        <label class="flex items-center gap-2 px-4 py-3 rounded-lg border-2 cursor-pointer transition"
                            :class="environment === 'production' ? 'border-red-400 bg-red-50' : 'border-gray-200 hover:border-gray-300'">
                            <input type="radio" name="fbr_environment" value="production" x-model="environment" class="text-red-500 focus:ring-red-500">
                            <div>
                                <span class="font-medium text-gray-800">Production</span>
                                <p class="text-xs text-gray-500">Live FBR PRAL system</p>
                            </div>
                        </label>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sandbox Token</label>
                    <input type="password" name="fbr_sandbox_token" value="{{ old('fbr_sandbox_token', $sandboxToken) }}" placeholder="Enter sandbox API token" class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                    <p class="text-xs text-gray-400 mt-1">Used when environment is set to Sandbox</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Production Token</label>
                    <input type="password" name="fbr_production_token" value="{{ old('fbr_production_token', $productionToken) }}" placeholder="Enter production API token" class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                    <p class="text-xs text-gray-400 mt-1">Used when environment is set to Production</p>
                </div>

                <template x-if="environment === 'production' && originalEnv !== 'production'">
                    <div class="p-4 bg-red-50 border border-red-200 rounded-lg">
                        <p class="text-sm text-red-700 font-medium mb-2">Switching to Production requires confirmation</p>
                        <p class="text-xs text-red-600 mb-3">All invoices will be submitted to the live FBR PRAL system. This action cannot be undone for submitted invoices.</p>
                        <label class="block text-sm font-medium text-red-700 mb-1">Type CONFIRM to proceed</label>
                        <input type="text" name="confirm_production" placeholder="Type CONFIRM" class="w-full rounded-lg border-red-300 shadow-sm focus:ring-red-500 focus:border-red-500">
                    </div>
                </template>

                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-2.5 bg-emerald-600 text-white rounded-lg font-medium hover:bg-emerald-700 transition">Save FBR Settings</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function fbrSettings() {
        return {
            environment: '{{ $company->fbr_environment ?? "sandbox" }}',
            originalEnv: '{{ $company->fbr_environment ?? "sandbox" }}'
        }
    }
    </script>
</x-app-layout>
