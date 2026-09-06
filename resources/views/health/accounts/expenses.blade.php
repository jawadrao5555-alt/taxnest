@php
    use App\Models\HealthExpense;
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<x-health-layout>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-black tracking-tight">{{ __('health.acc_expenses_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.acc_expenses_subtitle') }}</p>
            </div>
            <div class="text-end">
                <div class="text-[11px] uppercase tracking-wide font-bold text-gray-500 dark:text-gray-400">{{ __('health.acc_period_total') }}</div>
                <div class="text-lg font-black">{{ $money($total) }}</div>
            </div>
        </div>

        @include('health.accounts.partials.nav')

        <form method="GET" action="{{ route('health.accounts.expenses') }}"
              class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 grid grid-cols-2 md:grid-cols-4 gap-3">
            <div>
                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.from') }}</label>
                <input type="date" name="from" value="{{ $from }}" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.to') }}</label>
                <input type="date" name="to" value="{{ $to }}" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.category') }}</label>
                <select name="category_id" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <option value="">{{ __('health.all') }}</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" @selected((int) request('category_id') === (int) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end">
                <button class="w-full px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.apply') }}</button>
            </div>
        </form>

        @if($canManage)
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <form method="POST" action="{{ route('health.accounts.expenses.store') }}"
                      class="lg:col-span-2 rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                    @csrf
                    <h2 class="font-black">{{ __('health.acc_new_expense') }}</h2>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.date') }}</label>
                            <input type="date" name="expense_date" required value="{{ old('expense_date', now()->toDateString()) }}"
                                   class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.category') }}</label>
                            <select name="health_expense_category_id" required class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                @foreach($categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.amount') }}</label>
                            <input type="number" step="0.01" min="0.01" name="amount" required value="{{ old('amount') }}"
                                   class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_tax') }}</label>
                            <input type="number" step="0.01" min="0" name="tax_amount" value="{{ old('tax_amount') }}"
                                   class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_pay_mode') }}</label>
                            <select name="pay_mode" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                @foreach(HealthExpense::PAY_MODES as $mode)
                                    <option value="{{ $mode }}">{{ __('health.exp_mode_' . $mode) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_paid_from') }}</label>
                            <select name="paid_from_account_id" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">{{ __('health.acc_default') }}</option>
                                @foreach($fundAccounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->displayName() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_payee') }}</label>
                            <input name="payee" maxlength="190" value="{{ old('payee') }}"
                                   class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.department') }}</label>
                            <select name="health_department_id" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">{{ __('health.acc_unallocated') }}</option>
                                @foreach($departments as $department)
                                    <option value="{{ $department->id }}">{{ $department->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_reference') }}</label>
                            <input name="reference" maxlength="120" value="{{ old('reference') }}"
                                   class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                    </div>
                    <input name="description" maxlength="500" placeholder="{{ __('health.acc_memo') }}" value="{{ old('description') }}"
                           class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <button class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.save') }}</button>
                </form>

                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                    <h2 class="font-black">{{ __('health.acc_categories') }}</h2>
                    <form method="POST" action="{{ route('health.accounts.expense-categories.store') }}" class="flex gap-2">
                        @csrf
                        <input name="name" required maxlength="120" placeholder="{{ __('health.acc_category_name') }}"
                               class="flex-1 px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        <button class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.add') }}</button>
                    </form>
                    <ul class="space-y-1 text-sm">
                        @foreach($categories as $category)
                            <li class="flex items-center justify-between gap-2">
                                <span>{{ $category->name }}</span>
                                <form method="POST" action="{{ route('health.accounts.expense-categories.toggle', $category->id) }}">
                                    @csrf
                                    <button class="text-xs font-bold text-gray-500 hover:text-rose-600">{{ __('health.disable') }}</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_date') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_expense_no') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.category') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_payee') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_pay_mode') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.amount') }}</th>
                            <th class="px-3 py-2.5 text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($expenses as $expense)
                            <tr class="border-t border-gray-100 dark:border-gray-700/60 {{ $expense->status === 'reversed' ? 'opacity-50 line-through' : '' }}">
                                <td class="px-3 py-2.5 whitespace-nowrap">{{ optional($expense->expense_date)->format('d M Y') }}</td>
                                <td class="px-3 py-2.5 font-mono text-xs">{{ $expense->expense_no }}</td>
                                <td class="px-3 py-2.5">{{ $expense->category?->name }}</td>
                                <td class="px-3 py-2.5 text-gray-600 dark:text-gray-300">{{ $expense->payee }}</td>
                                <td class="px-3 py-2.5 text-xs">{{ __('health.exp_mode_' . $expense->pay_mode) }}</td>
                                <td class="px-3 py-2.5 text-end font-bold">{{ $money($expense->total_amount) }}</td>
                                <td class="px-3 py-2.5 text-end">
                                    @if($canManage && $expense->status !== 'reversed')
                                        <form method="POST" action="{{ route('health.accounts.expenses.reverse', $expense->id) }}"
                                              onsubmit="return confirm('{{ __('health.acc_reverse_confirm') }}')">
                                            @csrf
                                            <input type="hidden" name="reason" value="{{ __('health.acc_reason_correction') }}">
                                            <button class="text-xs font-bold text-gray-500 hover:text-rose-600">{{ __('health.acc_reverse') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('health.acc_no_expenses') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{ $expenses->links() }}
    </div>
</x-health-layout>
