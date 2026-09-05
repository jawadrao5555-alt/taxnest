@php
    use App\Models\HealthLeaveRequest;
@endphp
{{--
    Leave requests and their single review decision.

    An approved or rejected request is never re-reviewed — it is cancelled and
    re-filed — so "who allowed this, and why" always has exactly one answer.
--}}
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5"
         x-data="{ form: false, reviewing: null, reviewDecision: 'approved' }">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.hr_leave_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.hr_leave_subtitle') }}</p>
            </div>
            @if($canManage)
                <button type="button" @click="form = !form"
                        class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                    {{ __('health.hr_leave_file') }}
                </button>
            @endif
        </div>

        {{-- ── File on somebody's behalf ── --}}
        @if($canManage)
            <div x-show="form" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" action="{{ route('health.hr.leave.store') }}" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    @csrf
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_staff') }}</label>
                        <select name="user_id" required class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            <option value="">{{ __('health.hr_pick_staff') }}</option>
                            @foreach($staff as $member)
                                <option value="{{ $member->id }}">{{ $member->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_leave_type') }}</label>
                        <select name="health_leave_type_id" required class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            @foreach($leaveTypes as $type)
                                @continue(!$type->is_active)
                                <option value="{{ $type->id }}">{{ $type->name }}{{ $type->is_paid ? '' : ' — ' . __('health.hr_unpaid') }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex items-end">
                        <label class="inline-flex items-center gap-2 text-xs font-bold pb-2.5">
                            <input type="hidden" name="is_half_day" value="0">
                            <input type="checkbox" name="is_half_day" value="1" class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                            {{ __('health.hr_half_day') }}
                        </label>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_from') }}</label>
                        <input type="date" name="start_date" required value="{{ now()->toDateString() }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_to') }}</label>
                        <input type="date" name="end_date" required value="{{ now()->toDateString() }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_reason') }}</label>
                        <input type="text" name="reason" required maxlength="500"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div class="sm:col-span-2 lg:col-span-3">
                        <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">
                            {{ __('health.hr_submit') }}
                        </button>
                    </div>
                </form>
            </div>
        @endif

        {{-- ── Filters ── --}}
        <form method="GET" class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_status') }}</label>
                <select name="status" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    <option value="all" @selected($status === 'all')>{{ __('health.hr_all') }}</option>
                    @foreach($statuses as $option)
                        <option value="{{ $option }}" @selected($status === $option)>{{ __(HealthLeaveRequest::statusLabelKey($option)) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_staff') }}</label>
                <select name="user_id" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    <option value="0">{{ __('health.hr_all') }}</option>
                    @foreach($staff as $member)
                        <option value="{{ $member->id }}" @selected($userId === (int) $member->id)>{{ $member->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_month') }}</label>
                <input type="month" name="month" value="{{ $month }}"
                       class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
            </div>
            <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                {{ __('health.hr_apply') }}
            </button>
        </form>

        {{-- ── The queue ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            @if($requests->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.hr_leave_none') }}</p>
            @else
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($requests as $leave)
                        @php
                            $member = $names->get((int) $leave->user_id);
                            $type = $leaveTypes->firstWhere('id', (int) $leave->health_leave_type_id);
                            $used = $balances[(int) $leave->user_id][(int) $leave->health_leave_type_id] ?? 0;
                            $quota = (float) ($type->annual_quota_days ?? 0);
                        @endphp
                        <div class="px-5 py-4 space-y-2">
                            <div class="flex flex-wrap items-center gap-3">
                                <div class="flex-1 min-w-[220px]">
                                    <p class="text-sm font-black">
                                        {{ $member->name ?? __('health.hr_unknown_staff') }}
                                        <span class="ms-1.5 text-xs font-bold text-gray-500 dark:text-gray-400">{{ $type->name ?? '—' }}</span>
                                    </p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                        {{ $leave->start_date->translatedFormat('d M Y') }}
                                        @if(!$leave->start_date->isSameDay($leave->end_date))
                                            – {{ $leave->end_date->translatedFormat('d M Y') }}
                                        @endif
                                        &middot; {{ __('health.hr_days_count', ['days' => rtrim(rtrim((string) $leave->days, '0'), '.')]) }}
                                        @if($leave->is_half_day) &middot; {{ __('health.hr_half_day') }} @endif
                                        @if($type && !$type->is_paid) &middot; {{ __('health.hr_unpaid') }} @endif
                                    </p>
                                    <p class="text-xs text-gray-600 dark:text-gray-300 mt-1">{{ $leave->reason }}</p>
                                </div>

                                {{-- The approver sees the balance before deciding,
                                     instead of approving from memory. --}}
                                @if($quota > 0)
                                    <span class="text-[11px] font-bold px-2 py-1 rounded-lg bg-gray-100 dark:bg-gray-700 tabular-nums">
                                        {{ __('health.hr_balance_used', ['used' => rtrim(rtrim(number_format($used, 1), '0'), '.'), 'quota' => rtrim(rtrim(number_format($quota, 1), '0'), '.')]) }}
                                    </span>
                                @endif

                                <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wide
                                    @switch($leave->status)
                                        @case('approved') bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 @break
                                        @case('rejected') bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300 @break
                                        @case('cancelled') bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-300 @break
                                        @default bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300
                                    @endswitch">
                                    {{ __(HealthLeaveRequest::statusLabelKey($leave->status)) }}
                                </span>

                                @if($leave->status === 'pending' && $canApprove)
                                    <button type="button" @click="reviewing = reviewing === {{ (int) $leave->id }} ? null : {{ (int) $leave->id }}"
                                            class="px-3 py-1.5 rounded-lg text-xs font-bold border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">
                                        {{ __('health.hr_review') }}
                                    </button>
                                @endif
                                @if(in_array($leave->status, ['pending', 'approved'], true))
                                    <form method="POST" action="{{ route('health.hr.leave.cancel', $leave->id) }}"
                                          onsubmit="return confirm('{{ __('health.hr_confirm_cancel') }}')">
                                        @csrf
                                        <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-bold text-gray-500 dark:text-gray-400 hover:underline">
                                            {{ __('health.hr_cancel_request') }}
                                        </button>
                                    </form>
                                @endif
                            </div>

                            {{-- The decision trail, once there is one. --}}
                            @if($leave->reviewed_at)
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">
                                    {{ __('health.hr_reviewed_by', [
                                        'name' => $names->get((int) $leave->reviewed_by)->name ?? __('health.hr_unknown_staff'),
                                        'when' => $leave->reviewed_at->translatedFormat('d M Y H:i'),
                                    ]) }}
                                    @if($leave->review_note) — {{ $leave->review_note }} @endif
                                </p>
                            @endif

                            @if($leave->status === 'pending' && $canApprove)
                                <form x-show="reviewing === {{ (int) $leave->id }}" x-cloak method="POST"
                                      action="{{ route('health.hr.leave.review', $leave->id) }}"
                                      class="flex flex-wrap items-end gap-3 border-t border-gray-200 dark:border-gray-700 pt-3">
                                    @csrf
                                    <div class="flex-1 min-w-[200px]">
                                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_review_note') }}</label>
                                        <input type="text" name="review_note" maxlength="500"
                                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                    </div>
                                    <button type="submit" name="decision" value="approved"
                                            class="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold transition">
                                        {{ __('health.hr_approve') }}
                                    </button>
                                    <button type="submit" name="decision" value="rejected"
                                            class="px-4 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-sm font-bold transition">
                                        {{ __('health.hr_reject') }}
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-health-layout>
