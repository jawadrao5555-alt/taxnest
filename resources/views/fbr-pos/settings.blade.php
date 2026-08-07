<x-fbr-pos-layout>
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">{{ __('pos.fbr_integration_settings') }}</h1>

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-400 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm">{{ session('error') }}</div>
    @endif

    @php
        $fbrMode = $company->fbr_connection_mode ?? 'cloud';
        $isAgentMode = $fbrMode === 'fiscal_device';
        $agentOnline = $company->agent_last_seen && \Carbon\Carbon::parse($company->agent_last_seen)->gt(now()->subMinutes(2));
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            {{-- Submission Mode selector — Direct to FBR vs Fiscal Device (Agent), PRA-style --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl border-2 {{ $isAgentMode ? 'border-blue-300 dark:border-blue-700' : 'border-gray-200 dark:border-gray-700' }} shadow-md p-5">
                <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.submission_mode') }}</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.submission_mode_desc') }}</p>
                    </div>
                    <span class="px-3 py-1 rounded-full text-xs font-bold {{ $isAgentMode ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300 border border-blue-300 dark:border-blue-700' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300 border border-gray-300 dark:border-gray-700' }}">
                        {{ __('pos.currently_colon') }} {{ $isAgentMode ? __('pos.fiscal_device_agent') : __('pos.direct_to_fbr') }}
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {{-- Direct to FBR --}}
                    <div class="relative p-5 rounded-xl border-2 transition {{ !$isAgentMode ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 opacity-70' }}">
                        @if(!$isAgentMode)
                        <span class="absolute top-2 right-2 text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded bg-blue-600 text-white">{{ __('pos.active_word') }}</span>
                        @endif
                        <div class="font-bold text-gray-900 dark:text-white mb-1">{{ __('pos.direct_to_fbr_cloud') }}</div>
                        <p class="text-xs text-gray-600 dark:text-gray-400 mb-3 leading-relaxed">{{ __('pos.direct_to_fbr_desc') }}</p>
                        <ul class="text-[11px] text-gray-600 dark:text-gray-300 space-y-1 mb-3">
                            <li>✓ {{ __('pos.direct_mode_bullet_1') }}</li>
                            <li>⚠ {{ __('pos.direct_mode_bullet_2') }}</li>
                        </ul>
                        @if($isAgentMode)
                        <form method="POST" action="{{ route('fbrpos.settings') }}" onsubmit="return confirm(@js(__('pos.confirm_switch_direct')));">
                            @csrf
                            <input type="hidden" name="fbr_pos_environment" value="{{ $company->fbr_pos_environment ?? 'sandbox' }}">
                            <input type="hidden" name="fbr_connection_mode" value="cloud">
                            <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-lg font-semibold transition">{{ __('pos.switch_to_direct') }}</button>
                        </form>
                        @else
                        <div class="w-full px-4 py-2 bg-blue-600/10 text-blue-700 dark:text-blue-300 text-sm rounded-lg font-semibold text-center border border-blue-300 dark:border-blue-700">✓ {{ __('pos.active_mode') }}</div>
                        @endif
                    </div>

                    {{-- Fiscal Device (Agent) --}}
                    <div class="relative p-5 rounded-xl border-2 transition {{ $isAgentMode ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 opacity-70' }}">
                        @if($isAgentMode)
                        <span class="absolute top-2 right-2 text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded bg-blue-600 text-white">{{ __('pos.active_word') }}</span>
                        @endif
                        <div class="font-bold text-gray-900 dark:text-white mb-1">{{ __('pos.fiscal_device_via_agent') }}</div>
                        <p class="text-xs text-gray-600 dark:text-gray-400 mb-3 leading-relaxed">{{ __('pos.fiscal_device_desc') }}</p>
                        <ul class="text-[11px] text-gray-600 dark:text-gray-300 space-y-1 mb-3">
                            <li>✓ {{ __('pos.fiscal_mode_bullet_1') }}</li>
                            <li>✓ {{ __('pos.fiscal_mode_bullet_2') }}</li>
                            <li>⚠ {{ __('pos.fiscal_mode_bullet_3') }}</li>
                        </ul>
                        @if(!$isAgentMode)
                        <form method="POST" action="{{ route('fbrpos.settings') }}" onsubmit="return confirm(@js(__('pos.confirm_switch_fiscal')));">
                            @csrf
                            <input type="hidden" name="fbr_pos_environment" value="{{ $company->fbr_pos_environment ?? 'sandbox' }}">
                            <input type="hidden" name="fbr_connection_mode" value="fiscal_device">
                            <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-lg font-semibold transition">{{ __('pos.switch_to_fiscal_device') }}</button>
                        </form>
                        @else
                        <div class="w-full px-4 py-2 bg-blue-600/10 text-blue-700 dark:text-blue-300 text-sm rounded-lg font-semibold text-center border border-blue-300 dark:border-blue-700 flex items-center justify-center gap-2">
                            ✓ {{ __('pos.active_mode') }}
                            @if($agentOnline)
                            <span class="inline-flex items-center gap-1 text-[10px] px-1.5 py-0.5 rounded bg-emerald-500 text-white">● {{ __('pos.online_caps') }}</span>
                            @else
                            <span class="inline-flex items-center gap-1 text-[10px] px-1.5 py-0.5 rounded bg-red-500 text-white">{{ __('pos.offline_caps') }}</span>
                            @endif
                        </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">{{ __('pos.fbr_pos_configuration') }}</h3>
                <form method="POST" action="{{ route('fbrpos.settings') }}" class="space-y-4">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.environment_label') }}</label>
                        <select name="fbr_pos_environment" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                            <option value="sandbox" {{ ($company->fbr_pos_environment ?? 'sandbox') === 'sandbox' ? 'selected' : '' }}>{{ __('pos.sandbox_testing') }}</option>
                            <option value="production" {{ ($company->fbr_pos_environment ?? 'sandbox') === 'production' ? 'selected' : '' }}>{{ __('pos.production_live') }}</option>
                        </select>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800/50 rounded-lg p-2.5">
                        {!! __('pos.submission_mode_pick_hint') !!}
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.fbr_pos_registration_id') }} <span class="text-red-500">*</span></label>
                        <input type="text" name="fbr_pos_id" value="{{ $company->fbr_pos_id }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500" placeholder="{{ __('pos.ph_eg_196339') }}">
                        <p class="text-xs text-gray-400 mt-1">{{ __('pos.fbr_pos_registration_id_hint') }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.fbr_access_code') }}</label>
                        <input type="text" name="fbr_access_code" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500" placeholder="{{ $maskedAccessCode ?: __('pos.ph_iris_access_code') }}">
                        @if($maskedAccessCode)
                        <p class="text-xs text-gray-400 mt-1">{{ __('pos.current_access_code_hint', ['code' => $maskedAccessCode]) }}</p>
                        @else
                        <p class="text-xs text-gray-400 mt-1">{!! __('pos.fbr_access_code_hint') !!}</p>
                        @endif
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.fbr_pos_token') }} <span class="text-red-500">*</span></label>
                        <input type="text" name="fbr_pos_token" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500" placeholder="{{ $maskedPosToken ?: __('pos.ph_enter_fbr_ims_token') }}">
                        @if($maskedPosToken)
                        <p class="text-xs text-gray-400 mt-1">{{ __('pos.current_token_hint', ['token' => $maskedPosToken]) }}</p>
                        @else
                        <p class="text-xs text-gray-400 mt-1">{{ __('pos.fbr_pos_token_hint') }}</p>
                        @endif
                    </div>
                    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-3">
                        <p class="text-xs text-blue-700 dark:text-blue-400">
                            <strong>{{ __('pos.fbr_ims_pos_endpoints') }}</strong><br>
                            Sandbox: https://esp.fbr.gov.pk:8244/FBR/v1/api/Live/PostData<br>
                            Production: https://gw.fbr.gov.pk/imsp/v1/api/Live/PostData
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="submit" class="px-6 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition">{{ __('pos.save_settings') }}</button>
                        <button type="button" onclick="testFbrConnection()" id="testBtn" class="px-6 py-2 bg-gray-600 text-white text-sm rounded-lg hover:bg-gray-700 transition">
                            {{ __('pos.test_connection') }}
                        </button>
                    </div>
                </form>
                <div id="testResult" class="mt-3 hidden">
                    <div id="testResultContent" class="p-3 rounded-lg text-sm"></div>
                </div>
            </div>

            @if($isAgentMode)
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-blue-200 dark:border-blue-800 shadow-md p-5">
                <div class="flex items-center justify-between mb-1 flex-wrap gap-2">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                        {{ __('pos.connect_desktop_sync_agent') }}
                        <span class="text-[9px] px-1.5 py-0.5 bg-blue-600 text-white rounded font-bold uppercase tracking-wider">{{ __('pos.fiscal_device_badge') }}</span>
                    </h3>
                    @if($agentOnline)
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-emerald-500 text-white text-[10px] font-bold"><span class="w-1.5 h-1.5 rounded-full bg-white animate-pulse"></span> {{ __('pos.online_caps') }}</span>
                    @else
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-gray-500 text-white text-[10px] font-bold"><span class="w-1.5 h-1.5 rounded-full bg-gray-300"></span> {{ __('pos.offline_caps') }}</span>
                    @endif
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
                    {!! __('pos.fiscal_device_mode_blurb') !!}
                </p>

                {{-- 3 fields the agent needs to connect --}}
                <div class="space-y-3 mb-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.agent_field_server_url') }}</label>
                        <div class="flex gap-2">
                            <input type="text" id="agentServerUrl" readonly value="{{ url('/api/agent') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs font-mono focus:ring-blue-500 focus:border-blue-500">
                            <button type="button" onclick="copyField('agentServerUrl', this)" class="px-3 py-2 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-200 text-xs rounded-lg whitespace-nowrap">Copy</button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.agent_field_company_id') }}</label>
                        <div class="flex gap-2">
                            <input type="text" id="agentCompanyId" readonly value="{{ $company->id }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs font-mono focus:ring-blue-500 focus:border-blue-500">
                            <button type="button" onclick="copyField('agentCompanyId', this)" class="px-3 py-2 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-200 text-xs rounded-lg whitespace-nowrap">Copy</button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.agent_field_api_key') }}</label>
                        <div class="flex gap-2">
                            <input type="password" id="agentKey" readonly value="{{ $company->agent_api_key ?? '' }}" placeholder="{{ __('pos.ph_agent_key_generated') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs font-mono focus:ring-blue-500 focus:border-blue-500">
                            <button type="button" onclick="toggleAgentKey()" id="agentKeyToggle" class="px-3 py-2 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-200 text-xs rounded-lg whitespace-nowrap">{{ __('pos.show_word') }}</button>
                            <button type="button" onclick="copyField('agentKey', this)" class="px-3 py-2 bg-blue-600 text-white text-xs rounded-lg hover:bg-blue-700 transition whitespace-nowrap">Copy</button>
                        </div>
                        <p class="text-xs text-gray-400 mt-1">⚠️ {{ __('pos.agent_key_secret_warning') }}</p>
                    </div>
                </div>

                {{-- Download + regenerate --}}
                <div class="mb-4">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.download_desktop_sync_agent') }}</label>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('fbrpos.agent.download') }}?type=exe"
                           class="inline-flex items-center gap-1.5 px-3 py-2 bg-blue-600 text-white text-xs font-semibold rounded-lg hover:bg-blue-700 transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            {{ __('pos.download_agent_exe') }}
                        </a>
                        <a href="{{ route('fbrpos.agent.download') }}?type=zip"
                           class="inline-flex items-center gap-1.5 px-3 py-2 border border-blue-300 dark:border-blue-700 text-blue-700 dark:text-blue-400 text-xs font-semibold rounded-lg hover:bg-blue-50 dark:hover:bg-blue-900/20 transition">
                            {{ __('pos.portable_zip') }}
                        </a>
                        @if($company->agent_api_key)
                        <form method="POST" action="{{ route('fbrpos.settings') }}" onsubmit="return confirm(@js(__('pos.confirm_regenerate_agent_key')))" class="inline">
                            @csrf
                            <input type="hidden" name="fbr_pos_environment" value="{{ $company->fbr_pos_environment ?? 'sandbox' }}">
                            <input type="hidden" name="regenerate_agent_key" value="1">
                            <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 border border-amber-300 dark:border-amber-700 text-amber-700 dark:text-amber-400 text-xs font-semibold rounded-lg hover:bg-amber-50 dark:hover:bg-amber-900/20 transition">{{ __('pos.regenerate_key') }}</button>
                        </form>
                        @endif
                    </div>
                    <p class="text-xs text-gray-400 mt-1">{!! __('pos.agent_install_hint') !!}</p>
                </div>

                <div class="grid grid-cols-2 gap-3 text-sm mb-4">
                    <div class="flex flex-col">
                        <span class="text-gray-500 text-xs">{{ __('pos.agent_word') }}</span>
                        <span class="{{ $company->agent_enabled ? 'text-blue-600 font-semibold' : 'text-red-500' }}">{{ $company->agent_enabled ? __('pos.enabled_word') : __('pos.disabled_word') }}</span>
                    </div>
                    <div class="flex flex-col">
                        <span class="text-gray-500 text-xs">{{ __('pos.last_seen') }}</span>
                        <span class="text-gray-900 dark:text-white">{{ $company->agent_last_seen ? \Carbon\Carbon::parse($company->agent_last_seen)->diffForHumans() : __('pos.never_connected') }}</span>
                    </div>
                </div>

                <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-3">
                    <p class="text-xs font-semibold text-blue-700 dark:text-blue-400 mb-1">📖 {{ __('pos.agent_connect_howto_title') }}</p>
                    <ol class="text-xs text-blue-700 dark:text-blue-400 list-decimal list-inside space-y-0.5">
                        <li>{!! __('pos.agent_step_1') !!}</li>
                        <li>{!! __('pos.agent_step_2') !!}</li>
                        <li>{!! __('pos.agent_step_3') !!}</li>
                        <li>{!! __('pos.agent_step_4') !!}</li>
                        <li>{{ __('pos.agent_step_5') }}</li>
                    </ol>
                </div>
            </div>
            @endif

            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">{{ __('pos.confidential_pin') }}</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">{{ __('pos.confidential_pin_desc') }}</p>

                @php $hasPinSet = !empty($company->confidential_pin); @endphp
                @if($hasPinSet)
                <div class="flex items-center gap-2 mb-4 p-3 rounded-lg bg-blue-50 dark:bg-blue-900/20">
                    <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    <span class="text-sm font-medium text-blue-700 dark:text-blue-400">{{ __('pos.pin_is_set_and_active') }}</span>
                </div>
                @endif

                <form method="POST" action="{{ route('fbrpos.settings') }}" class="space-y-3">
                    @csrf
                    <input type="hidden" name="pin_update" value="1">
                    <input type="hidden" name="fbr_pos_environment" value="{{ $company->fbr_pos_environment ?? 'sandbox' }}">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ $hasPinSet ? __('pos.change_pin') : __('pos.set_pin') }} {{ __('pos.four_to_six_digits') }}</label>
                        <input type="password" name="confidential_pin" maxlength="6" pattern="\d{4,6}" placeholder="{{ __('pos.ph_enter_4_6_digit_pin') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition">{{ $hasPinSet ? __('pos.update_pin') : __('pos.set_pin') }}</button>
                        @if($hasPinSet)
                        <button type="submit" name="remove_pin" value="1" onclick="return confirm(@js(__('pos.confirm_remove_pin')))" class="px-4 py-2 bg-red-600 text-white text-sm rounded-lg hover:bg-red-700 transition">{{ __('pos.remove_pin') }}</button>
                        @endif
                    </div>
                </form>
            </div>

            {{-- 📋 Pending Bills at Day Close — FBR mirror of the PRA 'Khud Final' policy (Aug 2026) --}}
            @php $pendingPolicy = ($company->pos_dayclose_provisional_action === 'finalize') ? 'finalize' : 'carry'; @endphp
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">{{ __('pos.fbr_dayclose_pending_title') }}</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">{{ __('pos.fbr_dayclose_pending_desc') }}</p>
                <form method="POST" action="{{ route('fbrpos.settings') }}" class="space-y-3">
                    @csrf
                    <input type="hidden" name="dayclose_pending_update" value="1">
                    <label class="flex items-start gap-3 p-3 rounded-lg border cursor-pointer transition {{ $pendingPolicy === 'carry' ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700' }}">
                        <input type="radio" name="pending_policy" value="carry" {{ $pendingPolicy === 'carry' ? 'checked' : '' }} class="mt-0.5 text-blue-600 focus:ring-blue-500">
                        <span>
                            <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ __('pos.fbr_dayclose_carry') }}</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('pos.fbr_dayclose_carry_sub') }}</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-3 p-3 rounded-lg border cursor-pointer transition {{ $pendingPolicy === 'finalize' ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700' }}">
                        <input type="radio" name="pending_policy" value="finalize" {{ $pendingPolicy === 'finalize' ? 'checked' : '' }} class="mt-0.5 text-blue-600 focus:ring-blue-500">
                        <span>
                            <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ __('pos.fbr_dayclose_finalize') }}</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('pos.fbr_dayclose_finalize_sub') }}</span>
                        </span>
                    </label>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition">{{ __('pos.save_btn') }}</button>
                </form>
            </div>

            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">{{ __('pos.fbr_registration_details') }}</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">{{ __('pos.fbr_registration_details_desc') }}</p>
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('pos.seller_ntn_cnic') }}</span>
                        <span class="text-gray-900 dark:text-white font-mono">{{ $company->fbr_registration_no ?: ($company->ntn ?? __('pos.not_set')) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('pos.fbr_business_name') }}</span>
                        <span class="text-gray-900 dark:text-white">{{ $company->fbr_business_name ?: ($company->name ?? __('pos.not_set')) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('pos.province') }}</span>
                        <span class="text-gray-900 dark:text-white">{{ $company->province ?? __('pos.not_set') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('pos.address_label') }}</span>
                        <span class="text-gray-900 dark:text-white text-right max-w-[60%]">{{ $company->address ?? __('pos.not_set') }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">{{ __('pos.th_status') }}</h3>
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('pos.fbr_pos') }}</span>
                        <span class="{{ $company->fbr_pos_enabled ? 'text-blue-600 font-semibold' : 'text-red-500' }}">
                            {{ $company->fbr_pos_enabled ? __('pos.enabled_word') : __('pos.disabled_word') }}
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('pos.environment_label') }}</span>
                        <span class="text-gray-900 dark:text-white">{{ ucfirst($company->fbr_pos_environment ?? 'sandbox') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('pos.pos_id') }}</span>
                        <span class="text-gray-900 dark:text-white">{{ $company->fbr_pos_id ?? __('pos.not_set') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('pos.pos_token') }}</span>
                        <span class="{{ $maskedPosToken ? 'text-blue-600 font-semibold' : 'text-red-500' }}">
                            {{ $maskedPosToken ? __('pos.configured') : __('pos.not_set') }}
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('pos.connection') }}</span>
                        <span class="text-blue-600 font-semibold">{{ ($company->fbr_connection_mode ?? 'cloud') === 'fiscal_device' ? __('pos.fiscal_device_local') : __('pos.ims_pos_direct_cloud') }}</span>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">{{ __('pos.recent_fbr_logs') }}</h3>
                @forelse($fbrLogs as $log)
                <div class="border-b border-gray-100 dark:border-gray-800 last:border-0 py-2">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium {{ $log->status === 'success' ? 'text-blue-600' : ($log->status === 'failed' ? 'text-red-600' : 'text-amber-600') }}">
                            {{ strtoupper($log->status) }}
                        </span>
                        <span class="text-xs text-gray-400">{{ $log->created_at->diffForHumans() }}</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-0.5">{{ __('pos.code_colon') }} {{ $log->response_code ?? 'N/A' }}</p>
                    @if($log->error_message)
                    <p class="text-xs text-red-400 mt-0.5 truncate">{{ Str::limit($log->error_message, 80) }}</p>
                    @endif
                </div>
                @empty
                <p class="text-xs text-gray-400">{{ __('pos.no_fbr_logs_yet') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</div>

<script>
function copyField(id, btn) {
    const input = document.getElementById(id);
    if (!input || input.value === '') return;
    const wasPassword = input.type === 'password';
    navigator.clipboard.writeText(input.value).then(() => {
        const orig = btn.textContent;
        btn.textContent = @js(__('pos.copied_tick'));
        setTimeout(() => { btn.textContent = orig; }, 1500);
    }).catch(() => {
        input.type = 'text';
        input.select();
        input.setSelectionRange(0, 99999);
        document.execCommand('copy');
        if (wasPassword) input.type = 'password';
    });
}

function toggleAgentKey() {
    const input = document.getElementById('agentKey');
    const btn = document.getElementById('agentKeyToggle');
    if (!input) return;
    if (input.type === 'password') { input.type = 'text'; if (btn) btn.textContent = @js(__('pos.hide_word')); }
    else { input.type = 'password'; if (btn) btn.textContent = @js(__('pos.show_word')); }
}

function testFbrConnection() {
    const btn = document.getElementById('testBtn');
    const resultDiv = document.getElementById('testResult');
    const resultContent = document.getElementById('testResultContent');

    btn.disabled = true;
    btn.textContent = @js(__('pos.testing_ellipsis'));
    btn.classList.add('opacity-50');
    resultDiv.classList.add('hidden');

    fetch('{{ route("fbrpos.testConnection") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        },
    })
    .then(r => r.json())
    .then(data => {
        resultDiv.classList.remove('hidden');
        if (data.success) {
            resultContent.className = 'p-3 rounded-lg text-sm bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-400 border border-blue-200 dark:border-blue-800';
        } else {
            resultContent.className = 'p-3 rounded-lg text-sm bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 border border-red-200 dark:border-red-800';
        }
        resultContent.textContent = data.message;
    })
    .catch(err => {
        resultDiv.classList.remove('hidden');
        resultContent.className = 'p-3 rounded-lg text-sm bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 border border-red-200 dark:border-red-800';
        resultContent.textContent = @js(__('pos.connection_test_failed_prefix')) + err.message;
    })
    .finally(() => {
        btn.disabled = false;
        btn.textContent = @js(__('pos.test_connection'));
        btn.classList.remove('opacity-50');
    });
}
</script>
</x-fbr-pos-layout>
