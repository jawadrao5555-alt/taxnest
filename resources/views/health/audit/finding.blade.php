@php
    $sevClass = [
        'critical' => 'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200',
        'warning'  => 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200',
        'info'     => 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200',
    ];
    $statusClass = [
        'open'           => 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200',
        'acknowledged'   => 'bg-blue-100 dark:bg-blue-900/40 text-blue-800 dark:text-blue-200',
        'investigating'  => 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-800 dark:text-indigo-200',
        'resolved'       => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-200',
        'false_positive' => 'bg-gray-200 dark:bg-gray-600 text-gray-600 dark:text-gray-300',
    ];
@endphp
{{--
    One finding, and everything behind it.

    This is the page that decides whether the whole feature is trusted. A
    severity badge on its own invites an argument; the exact rows the rule read,
    plus the recorded acts around them, ends one. Nothing here edits an
    operational record — the drill-down links go to the screens that own them.
--}}
<x-health-layout>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div>
            <a href="{{ route('health.audit.show', $run->id) }}" class="text-xs font-bold text-teal-700 dark:text-teal-300 hover:underline">
                &larr; {{ $run->date_from->format('d M Y') }} — {{ $run->date_to->format('d M Y') }}
            </a>
            <div class="flex flex-wrap items-center gap-2 mt-2">
                <span class="px-2.5 py-1 rounded-lg text-[10px] font-black uppercase tracking-wide {{ $sevClass[$finding->severity] ?? $sevClass['info'] }}">
                    {{ __('health.audit_sev_' . $finding->severity) }}
                </span>
                <span class="px-2.5 py-1 rounded-lg text-[10px] font-black uppercase tracking-wide {{ $statusClass[$finding->status] ?? $statusClass['open'] }}">
                    {{ __('health.audit_fstatus_' . $finding->status) }}
                </span>
                <span class="text-[11px] font-bold text-gray-400">{{ __('health.audit_cat_' . $finding->category) }}</span>
            </div>
            <h1 class="text-xl sm:text-2xl font-black tracking-tight mt-2">{{ __('health.audit_rule_' . $finding->rule_key) }}</h1>
            <p class="text-sm text-gray-600 dark:text-gray-300 mt-1">
                {{ __('health.audit_rule_' . $finding->rule_key . '_msg', (array) $finding->params) }}
            </p>
        </div>

        {{-- A finding is a request to look, never a conclusion. Said on the page
             where somebody is most likely to forget it. --}}
        <div class="rounded-2xl bg-teal-50 dark:bg-teal-900/20 border border-teal-200 dark:border-teal-800 px-4 py-3">
            <p class="text-xs text-teal-900 dark:text-teal-100 leading-relaxed">{{ __('health.audit_finding_disclaimer') }}</p>
        </div>

        {{-- ══ At a glance ══ --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
            <dl class="grid sm:grid-cols-3 gap-4 text-sm">
                @if($finding->occurred_on)
                    <div>
                        <dt class="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.audit_when') }}</dt>
                        <dd class="font-black mt-0.5">{{ $finding->occurred_on->format('d M Y') }}</dd>
                    </div>
                @endif
                @if($finding->entity_label)
                    <div>
                        <dt class="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.audit_record') }}</dt>
                        <dd class="font-black mt-0.5">{{ $finding->entity_label }}</dd>
                    </div>
                @endif
                @if($finding->subject_name)
                    <div>
                        <dt class="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.audit_staff_member') }}</dt>
                        <dd class="font-black mt-0.5">{{ $finding->subject_name }}</dd>
                    </div>
                @endif
                @if($finding->amount !== null)
                    <div>
                        <dt class="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.audit_amount') }}</dt>
                        <dd class="font-black mt-0.5">{{ number_format((float) $finding->amount, 2) }}</dd>
                    </div>
                @endif
                @if($finding->variance !== null)
                    <div>
                        <dt class="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.audit_variance') }}</dt>
                        <dd class="font-black mt-0.5 {{ (float) $finding->variance != 0 ? 'text-red-600 dark:text-red-400' : '' }}">{{ number_format((float) $finding->variance, 2) }}</dd>
                    </div>
                @endif
                <div>
                    <dt class="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.audit_rule_version') }}</dt>
                    <dd class="font-black mt-0.5">{{ $finding->rule_version }}</dd>
                </div>
            </dl>

            @if(!empty($links))
                <div class="flex flex-wrap gap-2 mt-4 pt-4 border-t border-gray-100 dark:border-gray-700">
                    @foreach($links as $link)
                        <a href="{{ $link['url'] }}"
                           class="px-3.5 py-2 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-xs font-bold transition">
                            {{ $link['label'] }}
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ══ The rows the rule actually read ══ --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
            <h2 class="text-base font-black">{{ __('health.audit_evidence') }}</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 mb-3">{{ __('health.audit_evidence_hint') }}</p>

            @php $evidence = collect((array) $finding->evidence)->except(['link', 'links']); @endphp

            @if($evidence->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('health.audit_no_evidence') }}</p>
            @else
                <div class="space-y-4">
                    @foreach($evidence as $group => $values)
                        <div class="rounded-xl bg-gray-50 dark:bg-gray-900/40 border border-gray-200 dark:border-gray-700 p-4">
                            <p class="text-[10px] font-black uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $group }}</p>
                            @if(is_array($values))
                                <dl class="grid sm:grid-cols-2 gap-x-6 gap-y-1.5 mt-2 text-sm">
                                    @foreach($values as $key => $value)
                                        <div class="flex items-start justify-between gap-3 border-b border-dashed border-gray-200 dark:border-gray-700 py-1">
                                            <dt class="text-gray-500 dark:text-gray-400">{{ $key }}</dt>
                                            <dd class="font-bold text-end break-all">
                                                {{ is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : ($value === null || $value === '' ? '—' : $value) }}
                                            </dd>
                                        </div>
                                    @endforeach
                                </dl>
                            @else
                                <p class="text-sm font-bold mt-1">{{ $values }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ══ Everything the trail recorded about this record ══ --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
            <h2 class="text-base font-black">{{ __('health.audit_timeline') }}</h2>

            @if($timeline->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">{{ __('health.audit_no_timeline') }}</p>
            @else
                <ol class="mt-3 space-y-3">
                    @foreach($timeline as $event)
                        <li class="flex gap-3">
                            <div class="shrink-0 w-2 h-2 rounded-full bg-teal-600 mt-1.5"></div>
                            <div class="min-w-0 flex-1 pb-3 border-b border-gray-100 dark:border-gray-700 last:border-0">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <p class="text-sm font-black">{{ $event->event }}</p>
                                    <p class="text-xs text-gray-400">{{ $event->occurred_at?->format('d M Y H:i') }}</p>
                                </div>
                                <p class="text-xs text-gray-600 dark:text-gray-300 mt-0.5">
                                    {{ $event->actor_name ?: __('health.audit_actor_system') }}
                                    @if($event->actor_role)<span class="text-gray-400">({{ $event->actor_role }})</span>@endif
                                    @if($event->source)<span class="text-gray-400"> · {{ $event->source }}</span>@endif
                                    @if($event->ip_address)<span class="text-gray-400"> · {{ $event->ip_address }}</span>@endif
                                </p>
                                @if($event->reason)
                                    <p class="text-xs mt-1"><span class="text-gray-500 dark:text-gray-400">{{ __('health.audit_reason') }}:</span> {{ $event->reasonFor(auth()->guard('health')->user()) }}</p>
                                @endif
                                @php
                                    $old = is_array($event->old_values) ? $event->old_values : [];
                                    $new = is_array($event->new_values) ? $event->new_values : [];
                                @endphp
                                @if(!empty($new))
                                    <div class="mt-1.5 text-[11px] font-mono text-gray-600 dark:text-gray-300 space-y-0.5">
                                        @foreach($new as $key => $value)
                                            <div class="break-all">
                                                <span class="text-gray-400">{{ $key }}:</span>
                                                @if(array_key_exists($key, $old))
                                                    <span class="line-through opacity-60">{{ is_array($old[$key]) ? json_encode($old[$key]) : ($old[$key] ?? '—') }}</span>
                                                    <span class="mx-1">&rarr;</span>
                                                @endif
                                                <span class="font-bold">{{ is_array($value) ? json_encode($value) : ($value ?? '—') }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>

        {{-- ══ The same finding, earlier ══ --}}
        @if($history->isNotEmpty())
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <h2 class="text-base font-black">{{ __('health.audit_seen_before') }}</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 mb-3">{{ __('health.audit_seen_before_hint') }}</p>
                <ul class="space-y-2 text-sm">
                    @foreach($history as $past)
                        <li class="flex flex-wrap items-center justify-between gap-2 border-b border-dashed border-gray-200 dark:border-gray-700 pb-2">
                            <a href="{{ route('health.audit.show', $past->health_audit_run_id) }}" class="text-teal-700 dark:text-teal-300 font-bold hover:underline">
                                {{ __('health.audit_run_number', ['id' => $past->health_audit_run_id]) }}
                            </a>
                            <span class="px-2 py-0.5 rounded-lg text-[10px] font-black uppercase tracking-wide {{ $statusClass[$past->status] ?? $statusClass['open'] }}">
                                {{ __('health.audit_fstatus_' . $past->status) }}
                            </span>
                            <span class="text-xs text-gray-400">{{ optional($past->created_at)->format('d M Y') }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- ══ The investigation ══ --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
            <h2 class="text-base font-black">{{ __('health.audit_investigation') }}</h2>

            @if($finding->notes->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">{{ __('health.audit_no_notes') }}</p>
            @else
                <ol class="mt-3 space-y-3">
                    @foreach($finding->notes->sortBy('id') as $note)
                        <li class="rounded-xl bg-gray-50 dark:bg-gray-900/40 border border-gray-200 dark:border-gray-700 p-3">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <p class="text-xs font-black">
                                    {{ $note->actor_name ?: '—' }}
                                    @if($note->actor_role)<span class="text-gray-400 font-normal">({{ $note->actor_role }})</span>@endif
                                </p>
                                <p class="text-[11px] text-gray-400">{{ optional($note->created_at)->format('d M Y H:i') }}</p>
                            </div>
                            @if($note->status_from !== $note->status_to)
                                <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">
                                    {{ __('health.audit_fstatus_' . $note->status_from) }} &rarr;
                                    <span class="font-black">{{ __('health.audit_fstatus_' . $note->status_to) }}</span>
                                </p>
                            @endif
                            @if($note->body)
                                <p class="text-sm mt-1.5 whitespace-pre-line">{{ $note->bodyFor(auth()->guard('health')->user()) }}</p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif

            @if($canManage)
                {{-- Two separate acts: recording a decision, and adding to the
                     record without changing it. Keeping them apart is what lets
                     "we are still asking" be written down without pretending the
                     question is settled. --}}
                <form method="POST" action="{{ route('health.audit.finding.status', $finding->id) }}" class="mt-5 pt-5 border-t border-gray-200 dark:border-gray-700 space-y-3">
                    @csrf
                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.audit_record_decision') }}</label>
                    <div class="grid sm:grid-cols-3 gap-3">
                        <select name="status" class="sm:col-span-1 rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            @foreach(\App\Models\HealthAuditFinding::STATUSES as $st)
                                <option value="{{ $st }}" @selected($finding->status === $st)>{{ __('health.audit_fstatus_' . $st) }}</option>
                            @endforeach
                        </select>
                        <input type="text" name="note" maxlength="2000" placeholder="{{ __('health.audit_decision_note_placeholder') }}"
                               class="sm:col-span-2 rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </div>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('health.audit_close_needs_note_hint') }}</p>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('health.audit_note_privacy_hint') }}</p>
                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                        {{ __('health.audit_save_decision') }}
                    </button>
                </form>

                <form method="POST" action="{{ route('health.audit.finding.note', $finding->id) }}" class="mt-4 space-y-3">
                    @csrf
                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.audit_add_note') }}</label>
                    <textarea name="body" rows="2" maxlength="2000" required
                              class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm"
                              placeholder="{{ __('health.audit_note_placeholder') }}"></textarea>
                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-sm font-bold transition">
                        {{ __('health.audit_add_note') }}
                    </button>
                </form>
            @else
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                    {{ __('health.audit_manage_denied') }}
                </p>
            @endif
        </div>

        <p class="text-[11px] text-gray-400 font-mono break-all">{{ __('health.audit_fingerprint') }}: {{ $finding->fingerprint }}</p>
    </div>
</x-health-layout>
