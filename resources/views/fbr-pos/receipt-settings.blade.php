<x-fbr-pos-layout>
@php $ps = $company->posReceiptStyle(); @endphp
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    @include('fbr-pos.partials.back-link')
    <a href="{{ route('fbrpos.customize') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400 hover:text-blue-600 dark:hover:text-blue-400 transition mb-3">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        {{ __('pos.back_to_customize') }}
    </a>
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-1">🖋️ {{ __('pos.receipt_print_style') }}</h1>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">{{ __('pos.fbr_receipt_style_sub') }}</p>

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

    <form method="POST" action="{{ route('fbrpos.receipt-settings') }}" class="space-y-5">
        @csrf

        {{-- Bold toggle --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-5">
            <label class="flex items-start gap-3 cursor-pointer p-3 rounded-lg border {{ $ps['bold'] ? 'border-blue-400 bg-blue-50/40 dark:bg-blue-900/10' : 'border-gray-200 dark:border-gray-700' }} transition">
                <input type="checkbox" name="rp_style_bold" value="1" {{ $ps['bold'] ? 'checked' : '' }}
                       class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4">
                <span class="flex-1 min-w-0">
                    <span class="block text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.bold_receipt_print') }}</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.bold_receipt_hint') }}</span>
                </span>
            </label>
        </div>

        {{-- Logo position --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-5">
            <label class="block text-sm font-medium text-gray-800 dark:text-gray-200 mb-2">{{ __('pos.logo_style_on_receipt') }}</label>
            <select name="rp_logo_style" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500">
                <option value="side"   {{ $ps['logo'] === 'side'   ? 'selected' : '' }}>{{ __('pos.logo_style_compact') }}</option>
                <option value="center" {{ $ps['logo'] === 'center' ? 'selected' : '' }}>{{ __('pos.logo_style_large') }}</option>
            </select>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.logo_style_hint') }}</p>
        </div>

        {{-- Order Matching (Aug 2026) — mirrors PRA receipt-settings placement.
             Applies to receipt AND to KOT (when kitchen_printer_enabled is on). --}}
        @if(\Illuminate\Support\Facades\Schema::hasColumn('companies', 'order_match_style'))
        @php $omPref = in_array($company->order_match_style ?? 'off', ['off','token','code'], true) ? ($company->order_match_style ?? 'off') : 'off'; @endphp
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-5">
            <label class="block text-sm font-medium text-gray-800 dark:text-gray-200 mb-1">🔢 {{ __('pos.order_match_title') }}</label>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('pos.order_match_intro') }}</p>
            <div class="space-y-2">
                <label class="flex items-start gap-2 cursor-pointer">
                    <input type="radio" name="rp_order_match" value="off" {{ $omPref === 'off' ? 'checked' : '' }} class="mt-0.5 text-blue-600 focus:ring-blue-500">
                    <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.order_match_off') }} <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('pos.order_match_off_hint') }}</span></span>
                </label>
                <label class="flex items-start gap-2 cursor-pointer">
                    <input type="radio" name="rp_order_match" value="token" {{ $omPref === 'token' ? 'checked' : '' }} class="mt-0.5 text-blue-600 focus:ring-blue-500">
                    <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.order_match_token') }} <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('pos.order_match_token_hint') }}</span></span>
                </label>
                <label class="flex items-start gap-2 cursor-pointer">
                    <input type="radio" name="rp_order_match" value="code" {{ $omPref === 'code' ? 'checked' : '' }} class="mt-0.5 text-blue-600 focus:ring-blue-500">
                    <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.order_match_code') }} <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('pos.order_match_code_hint') }}</span></span>
                </label>
            </div>
        </div>
        @endif

        <div class="flex items-center justify-between gap-3 pt-1">
            <a href="{{ route('fbrpos.customize') }}"
               class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                {{ __('pos.back_to_customize') }}
            </a>
            <button type="submit"
                    class="px-8 py-2.5 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition shadow-sm">
                {{ __('pos.save_receipt_settings') }}
            </button>
        </div>
    </form>
</div>
</x-fbr-pos-layout>
