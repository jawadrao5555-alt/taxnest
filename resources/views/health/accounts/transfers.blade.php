@php
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<x-health-layout>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div>
            <h1 class="text-xl font-black tracking-tight">{{ __('health.acc_transfers_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.acc_transfers_subtitle') }}</p>
        </div>

        @include('health.accounts.partials.nav')

        <form method="GET" action="{{ route('health.accounts.transfers') }}"
              class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 grid grid-cols-2 md:grid-cols-4 gap-3">
            <div>
                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.from') }}</label>
                <input type="date" name="from" value="{{ $from }}" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.to') }}</label>
                <input type="date" name="to" value="{{ $to }}" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div class="flex items-end">
                <button class="w-full px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.apply') }}</button>
            </div>
        </form>

        @if($canManage)
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                {{-- A deposit is not a new pot of money, it is the same rupees in a
                     different place. Both sides are chosen by hand so the bank slip
                     and the drawer count can never disagree about which pot moved. --}}
                <form method="POST" action="{{ route('health.accounts.transfers.store') }}"
                      class="lg:col-span-2 rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                    @csrf
                    <h2 class="font-black">{{ __('health.acc_new_transfer') }}</h2>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.date') }}</label>
                            <input type="date" name="transfer_date" required value="{{ old('transfer_date', now()->toDateString()) }}"
                                   class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_kind') }}</label>
                            <select name="kind" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                @foreach($kinds as $kind)
                                    <option value="{{ $kind }}">{{ __('health.xfer_kind_' . $kind) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.amount') }}</label>
                            <input type="number" step="0.01" min="0.01" name="amount" required value="{{ old('amount') }}"
                                   class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_from_account') }}</label>
                            <select name="from_account_id" required class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                @foreach($fundAccounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->displayName() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_to_account') }}</label>
                            <select name="to_account_id" required class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                @foreach($fundAccounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->displayName() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_reference') }}</label>
                            <input name="reference" maxlength="120" value="{{ old('reference') }}"
                                   class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                    </div>
                    <input name="notes" maxlength="300" placeholder="{{ __('health.acc_memo') }}" value="{{ old('notes') }}"
                           class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <button class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.save') }}</button>
                </form>

                <form method="POST" action="{{ route('health.accounts.bank-accounts.store') }}"
                      class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                    @csrf
                    <h2 class="font-black">{{ __('health.acc_add_bank') }}</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.acc_add_bank_note') }}</p>
                    <input name="title" required maxlength="150" placeholder="{{ __('health.acc_bank_title') }}"
                           class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <input name="bank_name" maxlength="150" placeholder="{{ __('health.acc_bank_name') }}"
                           class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <input name="account_no" maxlength="60" placeholder="{{ __('health.acc_bank_no') }}"
                           class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <div class="grid grid-cols-2 gap-2">
                        <input name="opening_balance" type="number" step="0.01" placeholder="{{ __('health.acc_opening') }}"
                               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        <input name="opening_balance_date" type="date"
                               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </div>
                    <button class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.add') }}</button>

                    @if($bankAccounts->isNotEmpty())
                        <ul class="pt-2 space-y-1 text-sm border-t border-gray-200 dark:border-gray-700">
                            @foreach($bankAccounts as $bank)
                                <li class="flex items-center justify-between gap-2">
                                    <span class="font-bold">{{ $bank->title }}</span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $bank->bank_name }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </form>
            </div>
        @endif

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_date') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_transfer_no') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_kind') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_from_account') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_to_account') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.amount') }}</th>
                            <th class="px-3 py-2.5 text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($transfers as $transfer)
                            <tr class="border-t border-gray-100 dark:border-gray-700/60 {{ $transfer->status === 'reversed' ? 'opacity-50 line-through' : '' }}">
                                <td class="px-3 py-2.5 whitespace-nowrap">{{ optional($transfer->transfer_date)->format('d M Y') }}</td>
                                <td class="px-3 py-2.5 font-mono text-xs">{{ $transfer->transfer_no }}</td>
                                <td class="px-3 py-2.5 text-xs">{{ __('health.xfer_kind_' . $transfer->kind) }}</td>
                                <td class="px-3 py-2.5">{{ $transfer->fromAccount?->displayName() }}</td>
                                <td class="px-3 py-2.5">{{ $transfer->toAccount?->displayName() }}</td>
                                <td class="px-3 py-2.5 text-end font-bold">{{ $money($transfer->amount) }}</td>
                                <td class="px-3 py-2.5 text-end">
                                    @if($canManage && $transfer->status !== 'reversed')
                                        <form method="POST" action="{{ route('health.accounts.transfers.reverse', $transfer->id) }}"
                                              onsubmit="return confirm('{{ __('health.acc_reverse_confirm') }}')">
                                            @csrf
                                            <input type="hidden" name="reason" value="{{ __('health.acc_reason_correction') }}">
                                            <button class="text-xs font-bold text-gray-500 hover:text-rose-600">{{ __('health.acc_reverse') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('health.acc_no_transfers') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{ $transfers->links() }}
    </div>
</x-health-layout>
