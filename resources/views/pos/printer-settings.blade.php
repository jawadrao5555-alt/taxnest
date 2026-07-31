<x-pos-layout>
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <a href="{{ route('pos.customize') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400 hover:text-purple-600 dark:hover:text-purple-400 transition mb-3">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        {{ __('pos.back_to_customize') }}
    </a>
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">{{ __('pos.printer_settings') }}</h1>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">{{ __('pos.printer_settings_sub') }}</p>

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 text-sm border border-emerald-200 dark:border-emerald-800">{{ session('success') }}</div>
    @endif

    @if($errors->any())
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm border border-red-200 dark:border-red-800">
        <ul class="list-disc list-inside">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- Agent status --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-5 mb-5">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <div class="flex items-center gap-3">
                <span class="inline-flex w-2.5 h-2.5 rounded-full {{ $agentOnline ? 'bg-emerald-500' : 'bg-gray-400' }}"></span>
                <div>
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.desktop_agent_status', ['status' => $agentOnline ? __('pos.online') : __('pos.offline')]) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        @if($company->agent_last_seen)
                            {{ __('pos.last_seen_prefix') }} {{ $company->agent_last_seen->diffForHumans() }}@if($company->agent_version) · v{{ $company->agent_version }}@endif
                        @else
                            {{ __('pos.agent_never_connected') }}
                        @endif
                    </p>
                </div>
            </div>
            <a href="{{ route('pos.agent') }}" class="text-xs font-semibold text-purple-600 dark:text-purple-400 hover:underline">{{ __('pos.agent_setup_link') }}</a>
        </div>
        @if(!$agentOnline)
        <div class="mt-3 p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-xs text-amber-800 dark:text-amber-300">
            {{ __('pos.silent_print_needs_agent') }}
        </div>
        @endif
    </div>

    <form method="POST" action="{{ route('pos.printer-settings') }}" class="space-y-5">
        @csrf

        {{-- Master toggle --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-5">
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="silent_print_enabled" value="1" {{ $settings['silent_print_enabled'] ? 'checked' : '' }} class="mt-0.5 rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                <span>
                    <span class="block text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.enable_silent_printing') }}</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.enable_silent_printing_hint') }}</span>
                </span>
            </label>
        </div>

        {{-- Printer pickers --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1">{{ __('pos.choose_printers') }}</h3>
            {{-- Pickers stay VISIBLE even before the agent reports printers
                 (customer feedback Jul 2026 — Pizza Master couldn't find where
                 the kitchen printer is set). Empty list = dropdowns show only
                 "Not set" + the amber hint explains how to fill them. --}}
            @if(count($settings['available_printers']))
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
                    {{ __('pos.printers_found_on_agent') }}
                    @if($settings['printers_reported_at'])
                        ({{ __('pos.updated_prefix') }} {{ \Carbon\Carbon::parse($settings['printers_reported_at'])->diffForHumans() }})
                    @endif
                </p>
            @else
                <div class="mb-4 p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-xs text-amber-800 dark:text-amber-300">
                    {!! __('pos.no_printers_reported_html', ['link' => '<a href="' . e(route('pos.agent')) . '" class="font-semibold underline">' . e(__('pos.desktop_agent')) . '</a>']) !!}
                </div>
            @endif
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">{{ __('pos.bill_receipt_printer') }}</label>
                        <select name="receipt_printer" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                            <option value="">{{ __('pos.opt_not_set_popup') }}</option>
                            @foreach($settings['available_printers'] as $p)
                            <option value="{{ $p['name'] }}" {{ $settings['receipt_printer'] === $p['name'] ? 'selected' : '' }}>{{ $p['displayName'] ?? $p['name'] }}{{ !empty($p['isDefault']) ? ' ' . __('pos.default_paren') : '' }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.bill_printer_hint') }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">{{ __('pos.kitchen_kot_printer') }}</label>
                        <select name="kot_printer" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                            <option value="">{{ __('pos.opt_not_set_popup') }}</option>
                            @foreach($settings['available_printers'] as $p)
                            <option value="{{ $p['name'] }}" {{ $settings['kot_printer'] === $p['name'] ? 'selected' : '' }}>{{ $p['displayName'] ?? $p['name'] }}{{ !empty($p['isDefault']) ? ' ' . __('pos.default_paren') : '' }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.kot_printer_hint') }}</p>
                    </div>
                    {{-- Counter KOT Copy (owner request 30 Jul 2026): DINE-IN orders
                         only — every KOT also prints one full copy on this counter
                         printer when the tick is ON. Other order types ignore it. --}}
                    <div>
                        <div class="flex items-center justify-between gap-2 mb-1.5">
                            <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300">{{ __('pos.counter_kot_copy_printer') }} <span class="font-normal text-gray-400">{{ __('pos.dine_in_only_paren') }}</span></label>
                            <label class="flex items-center gap-1.5 cursor-pointer select-none">
                                <input type="checkbox" name="counter_kot_enabled" value="1" {{ $settings['counter_kot_enabled'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-3.5 h-3.5">
                                <span class="text-[11px] font-semibold text-gray-600 dark:text-gray-400">{{ __('pos.use_it_toggle') }}</span>
                            </label>
                        </div>
                        <select name="counter_kot_printer" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                            <option value="">{{ __('pos.opt_not_set') }}</option>
                            @foreach($settings['available_printers'] as $p)
                            <option value="{{ $p['name'] }}" {{ $settings['counter_kot_printer'] === $p['name'] ? 'selected' : '' }}>{{ $p['displayName'] ?? $p['name'] }}{{ !empty($p['isDefault']) ? ' ' . __('pos.default_paren') : '' }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.counter_kot_hint') }}</p>
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="w-full sm:w-auto px-6 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-sm font-bold transition shadow-sm">{{ __('pos.save_printer_settings') }}</button>
                    </div>
                </div>
        </div>
    </form>

    {{-- Recent failed jobs --}}
    @if($recentFailed->count())
    <div class="mt-6 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-5">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">{{ __('pos.recent_failed_prints') }}</h3>
        <div class="space-y-2">
            @foreach($recentFailed as $job)
            <div class="flex items-start justify-between gap-3 p-2.5 rounded-lg border border-red-100 dark:border-red-900/40 bg-red-50/50 dark:bg-red-900/10">
                <div class="min-w-0">
                    <p class="text-xs font-semibold text-gray-800 dark:text-gray-200">
                        {{ $job->type === 'bill' ? __('pos.bill_word_short') : __('pos.kot_word') }} #{{ $job->type === 'bill' ? $job->transaction_id : $job->restaurant_order_id }}
                        <span class="text-gray-400 font-normal">→ {{ $job->target_printer }}</span>
                    </p>
                    <p class="text-[11px] text-red-600 dark:text-red-400 truncate">{{ $job->error }}</p>
                </div>
                <span class="text-[11px] text-gray-400 whitespace-nowrap">{{ $job->created_at->diffForHumans() }}</span>
            </div>
            @endforeach
        </div>
    </div>
    @endif
</div>
</x-pos-layout>
