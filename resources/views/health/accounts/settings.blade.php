@php
    use App\Models\HealthAccountingSetting;
@endphp
<x-health-layout>
    <div class="max-w-3xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div>
            <h1 class="text-xl font-black tracking-tight">{{ __('health.acc_settings_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.acc_settings_subtitle') }}</p>
        </div>

        @include('health.accounts.partials.nav')

        <form method="POST" action="{{ route('health.accounts.settings.update') }}"
              class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 space-y-4">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_fy_start') }}</label>
                    <select name="fiscal_year_start_month" @disabled(!$canManage)
                            class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        @for($m = 1; $m <= 12; $m++)
                            <option value="{{ $m }}" @selected((int) $settings->fiscal_year_start_month === $m)>
                                {{ \Carbon\Carbon::create(null, $m, 1)->format('F') }}
                            </option>
                        @endfor
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_books_start') }}</label>
                    <input type="date" name="books_start_date" @disabled(!$canManage)
                           value="{{ $settings->books_start_date instanceof \DateTimeInterface ? $settings->books_start_date->toDateString() : $settings->books_start_date }}"
                           class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ __('health.acc_books_start_note') }}</p>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_doctor_basis') }}</label>
                <select name="doctor_share_basis" @disabled(!$canManage)
                        class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    @foreach(HealthAccountingSetting::BASES as $basis)
                        <option value="{{ $basis }}" @selected($settings->doctor_share_basis === $basis)>{{ __('health.acc_basis_' . $basis) }}</option>
                    @endforeach
                </select>
                {{-- Billed vs collected is the single biggest disagreement between a
                     hospital and its consultants. It is a setting, stated once, and
                     frozen onto each accrual — not something the finance desk can
                     reinterpret when the month looks bad. --}}
                <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ __('health.acc_doctor_basis_note') }}</p>
            </div>

            <label class="flex items-center gap-3 p-3 rounded-xl bg-gray-50 dark:bg-gray-900/40">
                <input type="checkbox" name="auto_post_enabled" value="1" class="rounded" @checked($settings->auto_post_enabled) @disabled(!$canManage)>
                <span>
                    <span class="block font-bold text-sm">{{ __('health.acc_auto_post') }}</span>
                    <span class="block text-[11px] text-gray-500 dark:text-gray-400">{{ __('health.acc_auto_post_note') }}</span>
                </span>
            </label>

            <label class="flex items-center gap-3 p-3 rounded-xl bg-gray-50 dark:bg-gray-900/40">
                <input type="checkbox" name="doctor_shares_enabled" value="1" class="rounded" @checked($settings->doctor_shares_enabled) @disabled(!$canManage)>
                <span>
                    <span class="block font-bold text-sm">{{ __('health.acc_doctor_shares') }}</span>
                    <span class="block text-[11px] text-gray-500 dark:text-gray-400">{{ __('health.acc_doctor_shares_note') }}</span>
                </span>
            </label>

            @if($canManage)
                <button class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.save') }}</button>
            @endif
        </form>
    </div>
</x-health-layout>
