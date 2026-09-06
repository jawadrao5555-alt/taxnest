@php
    use App\Models\HealthAccount;
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<x-health-layout>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div>
            <h1 class="text-xl font-black tracking-tight">{{ __('health.acc_chart_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.acc_chart_subtitle') }}</p>
        </div>

        @include('health.accounts.partials.nav')

        @if($canManage)
            <details class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700">
                <summary class="px-4 py-3 font-black cursor-pointer">{{ __('health.acc_add_account') }}</summary>
                <form method="POST" action="{{ route('health.accounts.chart.store') }}" class="p-4 pt-0 grid grid-cols-1 md:grid-cols-4 gap-3">
                    @csrf
                    <div class="md:col-span-2">
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_account') }}</label>
                        <input name="name" required maxlength="190" value="{{ old('name') }}"
                               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_type') }}</label>
                        <select name="type" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            @foreach($types as $type)
                                <option value="{{ $type }}" @selected(old('type') === $type)>{{ __('health.acc_type_' . $type) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_code') }}</label>
                        <input name="code" maxlength="20" value="{{ old('code') }}" placeholder="{{ __('health.acc_code_auto') }}"
                               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_opening') }}</label>
                        <input name="opening_balance" type="number" step="0.01" value="{{ old('opening_balance') }}"
                               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_opening_date') }}</label>
                        <input name="opening_balance_date" type="date" value="{{ old('opening_balance_date') }}"
                               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </div>
                    <label class="flex items-center gap-2 text-sm font-bold self-end pb-2">
                        <input type="checkbox" name="is_cash" value="1" class="rounded"> {{ __('health.acc_is_cash') }}
                    </label>
                    <label class="flex items-center gap-2 text-sm font-bold self-end pb-2">
                        <input type="checkbox" name="is_bank" value="1" class="rounded"> {{ __('health.acc_is_bank') }}
                    </label>
                    <div class="md:col-span-4">
                        <button class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.save') }}</button>
                    </div>
                </form>
            </details>
        @endif

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_code') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_account') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_type') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.acc_balance') }}</th>
                            <th class="px-3 py-2.5 text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            @php $account = $row['account']; @endphp
                            <tr class="border-t border-gray-100 dark:border-gray-700/60 {{ $account->is_active ? '' : 'opacity-50' }}">
                                <td class="px-3 py-2.5 font-mono text-xs">{{ $account->code }}</td>
                                <td class="px-3 py-2.5 font-bold">
                                    <a href="{{ route('health.accounts.reports.ledger', ['account_id' => $account->id]) }}"
                                       class="text-teal-700 dark:text-teal-300">{{ $account->displayName() }}</a>
                                    @if($account->system_key)
                                        <span class="ms-1 text-[10px] uppercase tracking-wide text-gray-400">{{ __('health.acc_system') }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 text-gray-500 dark:text-gray-400">{{ __('health.acc_type_' . $account->type) }}</td>
                                <td class="px-3 py-2.5 text-end font-bold">{{ $money($row['balance']) }}</td>
                                <td class="px-3 py-2.5 text-end">
                                    {{-- An account that has been posted to is never removable. Hiding
                                         it would orphan a figure the trial balance still counts. --}}
                                    @if($canManage && !$account->system_key && !$row['used'])
                                        <form method="POST" action="{{ route('health.accounts.chart.toggle', $account->id) }}">
                                            @csrf
                                            <button class="text-xs font-bold text-gray-500 hover:text-rose-600">
                                                {{ $account->is_active ? __('health.disable') : __('health.enable') }}
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-health-layout>
