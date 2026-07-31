<x-pos-layout>
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.pra_integration_settings') }}</h1>
        <a href="{{ route('pos.customize') }}" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            {{ __('pos.back_to_customize') }}
        </a>
    </div>

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 text-sm">{{ session('success') }}</div>
    @endif

    @if(($company->pos_integration_mode ?? 'pra') === 'standalone')
    {{-- Standalone edition: PRA settings are irrelevant until the company opts in. --}}
    <div class="mb-6 p-5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-md">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.standalone_edition_title') }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.standalone_edition_body') }}</p>
            </div>
            <form method="POST" action="{{ route('pos.api.enable-pra-integration') }}" onsubmit="return confirm({{ Js::from(__('pos.switch_pra_edition_q')) }});">
                @csrf
                <button type="submit" class="px-4 py-2.5 rounded-lg text-sm font-semibold text-white bg-emerald-600 hover:bg-emerald-700 transition whitespace-nowrap">{{ __('pos.enable_pra_integration') }}</button>
            </form>
        </div>
    </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">{{ __('pos.pra_configuration') }}</h3>
                <form method="POST" action="{{ route('pos.pra-settings') }}" class="space-y-4">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.environment_label') }}</label>
                        <select name="pra_environment" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
                            <option value="sandbox" {{ $company->pra_environment === 'sandbox' ? 'selected' : '' }}>{{ __('pos.env_sandbox') }}</option>
                            <option value="production" {{ $company->pra_environment === 'production' ? 'selected' : '' }}>{{ __('pos.env_production') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.connection_mode_label') }}</label>
                        <select name="pra_connection_mode" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
                            <option value="cloud" {{ ($company->pra_connection_mode ?? 'cloud') === 'cloud' ? 'selected' : '' }}>{{ __('pos.conn_cloud_api') }}</option>
                            <option value="fiscal_device" {{ ($company->pra_connection_mode ?? 'cloud') === 'fiscal_device' ? 'selected' : '' }}>{{ __('pos.conn_fiscal_device') }}</option>
                        </select>
                        <p class="text-xs text-gray-400 mt-1">{!! __('pos.pra_code112_hint_html') !!}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.pos_registration_id') }}</label>
                        <input type="text" name="pra_pos_id" value="{{ $company->pra_pos_id }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500" placeholder="{{ __('pos.ph_eg_100000') }}">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.production_token') }}</label>
                        <input type="text" name="pra_production_token" value="{{ $company->pra_production_token }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500" placeholder="{{ __('pos.ph_pra_token') }}">
                        <p class="text-xs text-gray-400 mt-1">{{ __('pos.production_token_hint') }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.pra_proxy_url') }}</label>
                        <input type="url" name="pra_proxy_url" value="{{ $company->pra_proxy_url }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500" placeholder="{{ __('pos.ph_eg_ngrok') }}">
                        <p class="text-xs text-gray-400 mt-1">{{ __('pos.pra_proxy_hint') }}</p>
                    </div>
                    <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.receipt_printer_size') }}</label>
                        <select name="receipt_printer_size" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-emerald-500 focus:border-emerald-500">
                            <option value="80mm" {{ ($company->receipt_printer_size ?? '80mm') === '80mm' ? 'selected' : '' }}>{{ __('pos.paper_80mm') }}</option>
                            <option value="58mm" {{ ($company->receipt_printer_size ?? '80mm') === '58mm' ? 'selected' : '' }}>{{ __('pos.paper_58mm') }}</option>
                        </select>
                        <p class="text-xs text-gray-400 mt-1">{{ __('pos.printer_size_hint') }}</p>
                    </div>
                    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-3">
                        <p class="text-xs text-blue-700 dark:text-blue-400">
                            <strong>API Endpoints:</strong><br>
                            @if(($company->pra_connection_mode ?? 'cloud') === 'fiscal_device')
                            Fiscal Device (on shop PC): http://localhost:8524/api/IMSFiscal/GetInvoiceNumberByModel<br>
                            Health check: http://localhost:8524/api/IMSFiscal/get → "Service is responding"
                            @else
                            Sandbox: https://ims.pral.com.pk/ims/sandbox/api/Live/PostData<br>
                            Production: https://ims.pral.com.pk/ims/production/api/Live/PostData
                            @endif
                        </p>
                    </div>
                    <button type="submit" class="px-6 py-2 bg-emerald-600 text-white text-sm rounded-lg hover:bg-emerald-700 transition">{{ __('pos.save_settings') }}</button>
                </form>
            </div>

            @if(!auth('pos')->user()->isPosCashier())
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">{{ __('pos.confidential_pin') }}</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">{{ __('pos.confidential_pin_sub') }}</p>

                @if($hasPinSet)
                <div class="flex items-center gap-2 mb-4 p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    <span class="text-sm font-medium text-emerald-700 dark:text-emerald-400">{{ __('pos.pin_set_and_active') }}</span>
                </div>
                @endif

                <form method="POST" action="{{ route('pos.pra-settings') }}" class="space-y-3">
                    @csrf
                    <input type="hidden" name="pra_environment" value="{{ $company->pra_environment }}">
                    <input type="hidden" name="receipt_printer_size" value="{{ $company->receipt_printer_size ?? '80mm' }}">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ $hasPinSet ? __('pos.change_pin') : __('pos.set_pin') }} {{ __('pos.digits_4_6_paren') }}</label>
                        <input type="password" name="confidential_pin" maxlength="6" pattern="\d{4,6}" placeholder="{{ __('pos.ph_enter_4_6_pin') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="px-4 py-2 bg-purple-600 text-white text-sm rounded-lg hover:bg-purple-700 transition">{{ $hasPinSet ? __('pos.update_pin') : __('pos.set_pin') }}</button>
                        @if($hasPinSet)
                        <button type="submit" name="remove_pin" value="1" onclick="return confirm({{ Js::from(__('pos.remove_pin_q')) }})" class="px-4 py-2 bg-red-600 text-white text-sm rounded-lg hover:bg-red-700 transition">{{ __('pos.remove_pin') }}</button>
                        @endif
                    </div>
                </form>
            </div>
            @endif
        </div>

        <div class="space-y-6">
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">{{ __('pos.status_label') }}</h3>
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('pos.pra_reporting') }}</span>
                        @php $praOnPraSettings = (bool) (auth('pos')->user()?->praReportingEnabled($company) ?? false); @endphp
                        <span class="{{ $praOnPraSettings ? 'text-emerald-600 font-semibold' : 'text-red-500' }}">
                            {{ $praOnPraSettings ? __('pos.enabled_word') : __('pos.disabled_word') }}
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('pos.environment_label') }}</span>
                        <span class="text-gray-900 dark:text-white">{{ ucfirst($company->pra_environment ?? 'sandbox') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('pos.pos_id_label') }}</span>
                        <span class="text-gray-900 dark:text-white">{{ $company->pra_pos_id ?? __('pos.not_set') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('pos.connection_label') }}</span>
                        @if(($company->pra_connection_mode ?? 'cloud') === 'fiscal_device')
                        <span class="text-blue-600 font-semibold">{{ __('pos.fiscal_device_shop_pc') }}</span>
                        @else
                        <span class="text-emerald-600 font-semibold">{{ __('pos.direct_pk_server') }}</span>
                        @endif
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">{{ __('pos.recent_pra_logs') }}</h3>
                @forelse($praLogs as $log)
                <div class="border-b border-gray-100 dark:border-gray-800 last:border-0 py-2">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium {{ $log->status === 'success' ? 'text-emerald-600' : ($log->status === 'failed' ? 'text-red-600' : 'text-amber-600') }}">
                            {{ strtoupper($log->status) }}
                        </span>
                        <span class="text-xs text-gray-400">{{ $log->created_at->diffForHumans() }}</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-0.5">{{ __('pos.code_colon') }} {{ $log->response_code ?? 'N/A' }}</p>
                </div>
                @empty
                <p class="text-xs text-gray-400">{{ __('pos.no_pra_logs') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
</x-pos-layout>