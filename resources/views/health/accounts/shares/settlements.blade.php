@php
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<x-health-layout>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div>
            <h1 class="text-xl font-black tracking-tight">{{ __('health.dset_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.dset_subtitle') }}</p>
        </div>

        @include('health.accounts.partials.nav')

        <form method="GET" action="{{ route('health.accounts.settlements') }}"
              class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 grid grid-cols-2 md:grid-cols-4 gap-3">
            <div>
                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.doctor') }}</label>
                <select name="doctor_id" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <option value="">{{ __('health.all') }}</option>
                    @foreach($doctors as $doctor)
                        <option value="{{ $doctor->id }}" @selected((int) request('doctor_id') === (int) $doctor->id)>{{ $doctor->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.status') }}</label>
                <select name="status" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <option value="">{{ __('health.all') }}</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ __('health.dset_' . $status) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end">
                <button class="w-full px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.apply') }}</button>
            </div>
        </form>

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2.5 text-start">{{ __('health.dset_no') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.doctor') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.dset_period') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.dset_gross') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.dset_deduction') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.dset_net') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($settlements as $settlement)
                            <tr class="border-t border-gray-100 dark:border-gray-700/60">
                                <td class="px-3 py-2.5 font-bold">
                                    <a href="{{ route('health.accounts.settlement', $settlement->id) }}" class="text-teal-700 dark:text-teal-300">{{ $settlement->settlement_no }}</a>
                                </td>
                                <td class="px-3 py-2.5">{{ $settlement->doctor?->name }}</td>
                                <td class="px-3 py-2.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ optional($settlement->period_from)->format('d M Y') }} &rarr; {{ optional($settlement->period_to)->format('d M Y') }}
                                </td>
                                <td class="px-3 py-2.5 text-end">{{ $money($settlement->gross_amount) }}</td>
                                <td class="px-3 py-2.5 text-end text-gray-500 dark:text-gray-400">{{ $money($settlement->deduction_amount) }}</td>
                                <td class="px-3 py-2.5 text-end font-bold">{{ $money($settlement->net_amount) }}</td>
                                <td class="px-3 py-2.5">
                                    <span class="px-2 py-0.5 rounded-lg text-[11px] font-bold
                                        @class([
                                            'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200' => $settlement->status === 'draft',
                                            'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' => $settlement->status === 'approved',
                                            'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' => $settlement->status === 'paid',
                                            'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300' => $settlement->status === 'reversed',
                                        ])">
                                        {{ __($settlement->statusLabelKey()) }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('health.dset_none') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{ $settlements->links() }}
    </div>
</x-health-layout>
