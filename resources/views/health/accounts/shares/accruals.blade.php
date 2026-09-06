@php
    $money = fn ($v) => number_format((float) $v, 2);
    $doctorNames = $doctors->pluck('name', 'id');
@endphp
<x-health-layout>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-black tracking-tight">{{ __('health.dsh_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.dsh_subtitle') }}</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('health.accounts.share-rules') }}" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.dsh_rules_title') }}</a>
                @if($canManage)
                    <form method="POST" action="{{ route('health.accounts.shares.accrue') }}">
                        @csrf
                        <input type="hidden" name="from" value="{{ $from }}">
                        <input type="hidden" name="to" value="{{ $to }}">
                        <button class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.dsh_accrue') }}</button>
                    </form>
                @endif
            </div>
        </div>

        @include('health.accounts.partials.nav')

        <form method="GET" action="{{ route('health.accounts.shares') }}"
              class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 grid grid-cols-2 md:grid-cols-5 gap-3">
            <div>
                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.from') }}</label>
                <input type="date" name="from" value="{{ $from }}" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.to') }}</label>
                <input type="date" name="to" value="{{ $to }}" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
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
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ __('health.dsh_' . $status) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end gap-2">
                <button class="flex-1 px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.apply') }}</button>
                <a href="{{ route('health.accounts.shares', array_merge(request()->query(), ['export' => 'csv'])) }}"
                   class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.export_csv') }}</a>
            </div>
        </form>

        @if(!empty($summary))
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-3 py-2.5 text-start">{{ __('health.doctor') }}</th>
                                <th class="px-3 py-2.5 text-end">{{ __('health.dsh_accrued') }}</th>
                                <th class="px-3 py-2.5 text-end">{{ __('health.dsh_approved') }}</th>
                                <th class="px-3 py-2.5 text-end">{{ __('health.dsh_settled') }}</th>
                                <th class="px-3 py-2.5 text-end">{{ __('health.dsh_excluded') }}</th>
                                <th class="px-3 py-2.5 text-end"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($summary as $row)
                                <tr class="border-t border-gray-100 dark:border-gray-700/60">
                                    <td class="px-3 py-2.5 font-bold">{{ $doctorNames[$row['health_doctor_id']] ?? ('#' . $row['health_doctor_id']) }}</td>
                                    <td class="px-3 py-2.5 text-end">{{ $money($row['open_amount']) }}</td>
                                    <td class="px-3 py-2.5 text-end">{{ $money($row['approved_amount']) }}</td>
                                    <td class="px-3 py-2.5 text-end">{{ $money($row['settled_amount']) }}</td>
                                    <td class="px-3 py-2.5 text-end text-gray-400">{{ $money($row['excluded_amount']) }}</td>
                                    <td class="px-3 py-2.5 text-end">
                                        <a href="{{ route('health.accounts.doctor-statement', $row['health_doctor_id']) }}"
                                           class="text-xs font-bold text-teal-700 dark:text-teal-300">{{ __('health.acc_statement') }}</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if($canManage)
            <form method="POST" action="{{ route('health.accounts.settlements.build') }}"
                  class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 flex flex-wrap items-end gap-3">
                @csrf
                <div>
                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.doctor') }}</label>
                    <select name="health_doctor_id" required class="px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        @foreach($doctors as $doctor)
                            <option value="{{ $doctor->id }}">{{ $doctor->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.from') }}</label>
                    <input type="date" name="period_from" required value="{{ $from }}"
                           class="px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.to') }}</label>
                    <input type="date" name="period_to" required value="{{ $to }}"
                           class="px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                </div>
                <button class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.dset_build') }}</button>
            </form>
        @endif

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_date') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.doctor') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_memo') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.dsh_base') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.dsh_rate') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.dsh_share') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.status') }}</th>
                            <th class="px-3 py-2.5 text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($shares as $share)
                            <tr class="border-t border-gray-100 dark:border-gray-700/60 {{ $share->status === 'excluded' ? 'opacity-60' : '' }}">
                                <td class="px-3 py-2.5 whitespace-nowrap">{{ optional($share->accrual_date)->format('d M Y') }}</td>
                                <td class="px-3 py-2.5 font-bold">{{ $share->doctor?->name }}</td>
                                <td class="px-3 py-2.5 text-gray-600 dark:text-gray-300">
                                    {{ $share->description }}
                                    @if($share->exclusion_reason)
                                        <span class="block text-[11px] text-rose-600 dark:text-rose-300">{{ $share->exclusion_reason }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 text-end text-gray-500 dark:text-gray-400">{{ $money($share->base_amount) }}</td>
                                <td class="px-3 py-2.5 text-end text-xs">{{ (float) $share->rate }}</td>
                                <td class="px-3 py-2.5 text-end font-bold">{{ $money($share->share_amount) }}</td>
                                <td class="px-3 py-2.5 text-xs font-bold">
                                    {{ __($share->statusLabelKey()) }}
                                    @if($share->settlement)
                                        <a href="{{ route('health.accounts.settlement', $share->health_doctor_settlement_id) }}"
                                           class="block text-teal-700 dark:text-teal-300">{{ $share->settlement->settlement_no }}</a>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 text-end">
                                    {{-- Excluding never deletes. A share that vanished is a share
                                         the doctor will ask about and nobody can explain. --}}
                                    @if($canManage && $share->status === 'accrued')
                                        <form method="POST" action="{{ route('health.accounts.shares.exclude', $share->id) }}" class="flex gap-1 justify-end">
                                            @csrf
                                            <input name="reason" required maxlength="300" placeholder="{{ __('health.acc_reason') }}"
                                                   class="w-32 px-2 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                            <button class="px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-700 text-xs font-bold">{{ __('health.dsh_exclude') }}</button>
                                        </form>
                                    @elseif($canManage && $share->status === 'excluded')
                                        <form method="POST" action="{{ route('health.accounts.shares.restore', $share->id) }}">
                                            @csrf
                                            <button class="text-xs font-bold text-teal-700 dark:text-teal-300">{{ __('health.dsh_restore') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('health.dsh_none') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{ $shares->links() }}
    </div>
</x-health-layout>
