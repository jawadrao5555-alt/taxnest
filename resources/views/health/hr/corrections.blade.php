@php
    use App\Models\HealthAttendanceCorrection;
@endphp
{{--
    The correction queue — every request to change an attendance record, with
    who asked, why, who decided and when.

    Nothing here edits a punch. An approved correction either ADDS a manual
    punch to the timeline or marks an existing one disregarded; the original row
    and its device stamp survive either way.
--}}
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div>
            <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.hr_corrections_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.hr_corrections_subtitle') }}</p>
        </div>

        <form method="GET" class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.hr_status') }}</label>
                <select name="status" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    <option value="all" @selected($status === 'all')>{{ __('health.hr_all') }}</option>
                    @foreach($statuses as $option)
                        <option value="{{ $option }}" @selected($status === $option)>{{ __(HealthAttendanceCorrection::statusLabelKey($option)) }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                {{ __('health.hr_apply') }}
            </button>
        </form>

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            @if($corrections->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.hr_no_corrections') }}</p>
            @else
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($corrections as $correction)
                        @php
                            $member = $names->get((int) $correction->user_id);
                            $requester = $names->get((int) $correction->requested_by);
                            $reviewer = $names->get((int) $correction->reviewed_by);
                            $date = \Illuminate\Support\Carbon::parse($correction->attendance_date);
                        @endphp
                        <div class="px-5 py-4 space-y-2">
                            <div class="flex flex-wrap items-center gap-3">
                                <div class="flex-1 min-w-[240px]">
                                    <p class="text-sm font-black">
                                        {{ $member->name ?? __('health.hr_unknown_staff') }}
                                        <span class="ms-1.5 text-xs font-bold text-gray-500 dark:text-gray-400">{{ $date->translatedFormat('d M Y') }}</span>
                                    </p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                        {{ __(HealthAttendanceCorrection::typeLabelKey($correction->type)) }}
                                        @if($correction->punch_at)
                                            &middot; {{ \Illuminate\Support\Carbon::parse($correction->punch_at)->format('d M H:i') }}
                                            @if($correction->direction) ({{ __('health.hr_dir_' . $correction->direction) }}) @endif
                                        @endif
                                        @if($correction->requested_status)
                                            &middot; {{ __(\App\Models\HealthAttendanceDay::statusLabelKey($correction->requested_status)) }}
                                        @endif
                                        @if($correction->requested_minutes !== null)
                                            &middot; {{ \App\Models\HealthAttendanceDay::hoursLabel($correction->requested_minutes) }}
                                        @endif
                                    </p>
                                    <p class="text-xs text-gray-600 dark:text-gray-300 mt-1">{{ $correction->reason }}</p>
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">
                                        {{ __('health.hr_requested_by', [
                                            'name' => $requester->name ?? __('health.hr_unknown_staff'),
                                            'when' => $correction->created_at?->translatedFormat('d M Y H:i') ?? '',
                                        ]) }}
                                    </p>
                                </div>

                                <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wide
                                    @switch($correction->status)
                                        @case('approved') bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 @break
                                        @case('rejected') bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300 @break
                                        @default bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300
                                    @endswitch">
                                    {{ __(HealthAttendanceCorrection::statusLabelKey($correction->status)) }}
                                </span>

                                <a href="{{ route('health.hr.attendance.day', ['userId' => $correction->user_id, 'date' => $date->toDateString()]) }}"
                                   class="text-xs font-bold text-teal-700 dark:text-teal-300 hover:underline">{{ __('health.hr_open') }}</a>
                            </div>

                            @if($correction->reviewed_at)
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">
                                    {{ __('health.hr_reviewed_by', [
                                        'name' => $reviewer->name ?? __('health.hr_unknown_staff'),
                                        'when' => $correction->reviewed_at->translatedFormat('d M Y H:i'),
                                    ]) }}
                                    @if($correction->review_note) — {{ $correction->review_note }} @endif
                                </p>
                            @endif

                            @if($correction->status === 'pending' && $canApprove)
                                <form method="POST" action="{{ route('health.hr.corrections.review', $correction->id) }}"
                                      class="flex flex-wrap items-end gap-2 border-t border-gray-200 dark:border-gray-700 pt-3">
                                    @csrf
                                    <input type="text" name="review_note" maxlength="500" placeholder="{{ __('health.hr_review_note') }}"
                                           class="flex-1 min-w-[200px] rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                    <button type="submit" name="decision" value="approved"
                                            class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold transition">{{ __('health.hr_approve') }}</button>
                                    <button type="submit" name="decision" value="rejected"
                                            class="px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold transition">{{ __('health.hr_reject') }}</button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-health-layout>
