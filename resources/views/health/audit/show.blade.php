@php
    use App\Services\HealthAudit\HealthAuditPackService;

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
    The result of one press.

    Summary first, then the categories, then the rows. The order is deliberate:
    an owner who opens this screen wants to know within two seconds whether
    anything needs them today, and only then which of it needs them first.
--}}
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <a href="{{ route('health.audit') }}" class="text-xs font-bold text-teal-700 dark:text-teal-300 hover:underline">&larr; {{ __('health.audit_title') }}</a>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight mt-1">
                    {{ $run->date_from->format('d M Y') }} — {{ $run->date_to->format('d M Y') }}
                </h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                    {{ __('health.audit_run_by', ['name' => $run->actor_name ?: '—', 'at' => optional($run->completed_at)->format('d M Y H:i') ?: '—']) }}
                    · {{ __('health.audit_ruleset', ['version' => $run->ruleset_version]) }}
                </p>
            </div>

            @if($canExport)
                <div class="flex flex-wrap items-center gap-2">
                    @if($run->pack_status === 'ready' && $packVerified)
                        <a href="{{ route('health.audit.pack.download', $run->id) }}"
                           class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                            {{ __('health.audit_pack_download') }}
                            <span class="opacity-70 text-xs">({{ number_format(($run->pack_size ?? 0) / 1024, 0) }} KB)</span>
                        </a>
                    @endif
                    <form method="POST" action="{{ route('health.audit.pack', $run->id) }}">
                        @csrf
                        <button type="submit"
                                class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-sm font-bold transition">
                            {{ $run->pack_status === 'ready' ? __('health.audit_pack_rebuild') : __('health.audit_pack_build') }}
                        </button>
                    </form>
                </div>
            @endif
        </div>

        {{-- ══ Scope: what this run actually looked at ══ --}}
        @php $confined = is_array($run->scope_branch_ids) || is_array($run->scope_department_ids); @endphp
        @if($run->branch_id || $run->health_department_id || $run->health_doctor_id || $run->subject_user_id || $confined)
            <div class="rounded-2xl bg-gray-50 dark:bg-gray-900/40 border border-gray-200 dark:border-gray-700 px-4 py-3">
                <p class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.audit_scope') }}</p>
                @if($run->branch_id || $run->health_department_id || $run->health_doctor_id || $run->subject_user_id)
                    <p class="text-sm mt-1">{{ __('health.audit_scope_narrowed') }}</p>
                @endif
                @if($confined)
                    {{-- Computed inside the runner's own posting, not the whole organisation. --}}
                    <p class="text-sm mt-1">{{ __('health.audit_scope_confined') }}</p>
                @endif
            </div>
        @endif

        {{-- ══ An incomplete run says so, loudly ══ --}}
        @if($run->rules_failed)
            <div class="rounded-2xl bg-amber-50 dark:bg-amber-900/20 border border-amber-300 dark:border-amber-800 p-4">
                <p class="text-sm font-black text-amber-900 dark:text-amber-100">{{ __('health.audit_incomplete_heading', ['count' => $run->rules_failed]) }}</p>
                <p class="text-xs text-amber-800 dark:text-amber-200 mt-1">{{ __('health.audit_incomplete_body') }}</p>
                @if($run->error_message)
                    <p class="text-xs font-mono text-amber-700 dark:text-amber-300 mt-2 break-all">{{ $run->error_message }}</p>
                @endif
            </div>
        @endif

        @if($run->pack_status === 'ready' && $packVerified === false)
            <div class="rounded-2xl bg-red-50 dark:bg-red-900/20 border border-red-300 dark:border-red-800 p-4">
                <p class="text-sm font-black text-red-900 dark:text-red-100">{{ __('health.audit_pack_integrity_failed') }}</p>
            </div>
        @endif

        {{-- ══ Summary cards ══ --}}
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
                <p class="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.audit_risk_score') }}</p>
                <p class="text-3xl font-black mt-1 {{ $run->risk_score >= 80 ? 'text-emerald-600 dark:text-emerald-400' : ($run->risk_score >= 50 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400') }}">
                    {{ $run->risk_score }}
                </p>
                <p class="text-[10px] text-gray-400 mt-0.5">{{ __('health.audit_out_of_100') }}</p>
            </div>
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
                <p class="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.audit_sev_critical') }}</p>
                <p class="text-3xl font-black mt-1 {{ $run->findings_critical ? 'text-red-600 dark:text-red-400' : 'text-gray-400' }}">{{ $run->findings_critical }}</p>
            </div>
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
                <p class="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.audit_sev_warning') }}</p>
                <p class="text-3xl font-black mt-1 {{ $run->findings_warning ? 'text-amber-600 dark:text-amber-400' : 'text-gray-400' }}">{{ $run->findings_warning }}</p>
            </div>
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
                <p class="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.audit_sev_info') }}</p>
                <p class="text-3xl font-black mt-1 text-gray-500 dark:text-gray-300">{{ $run->findings_info }}</p>
            </div>
            {{-- "Nothing found" means one thing over 40,000 recorded acts and a
                 completely different thing over none, so the denominator is on
                 the screen next to the answer. --}}
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
                <p class="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.audit_events_scanned') }}</p>
                <p class="text-3xl font-black mt-1 text-gray-700 dark:text-gray-200">{{ number_format($run->events_scanned) }}</p>
                <p class="text-[10px] text-gray-400 mt-0.5">{{ __('health.audit_rules_run', ['count' => $run->rules_run]) }}</p>
            </div>
        </div>

        {{-- ══ Compared with the last time the same question was asked ══ --}}
        @if($previous)
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 px-5 py-4">
                <p class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.audit_vs_previous') }}</p>
                <p class="text-sm mt-1">
                    @if($previous->result_hash === $run->result_hash)
                        {{ __('health.audit_same_as_previous') }}
                    @else
                        {{ __('health.audit_changed_from_previous', [
                            'before' => $previous->findings_total,
                            'after' => $run->findings_total,
                            'date' => optional($previous->completed_at)->format('d M Y'),
                        ]) }}
                    @endif
                </p>
            </div>
        @endif

        {{-- ══ Where the findings sit ══ --}}
        @if($byRule->isNotEmpty())
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <h2 class="text-base font-black mb-3">{{ __('health.audit_by_check') }}</h2>
                <div class="flex flex-wrap gap-2">
                    @foreach($byRule as $row)
                        <a href="{{ route('health.audit.show', ['id' => $run->id, 'rule' => $row->rule_key]) }}"
                           class="inline-flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-bold {{ $sevClass[$row->severity] ?? $sevClass['info'] }} hover:opacity-80 transition">
                            <span>{{ __('health.audit_rule_' . $row->rule_key) }}</span>
                            <span class="px-1.5 py-0.5 rounded-md bg-white/60 dark:bg-black/30 font-black">{{ $row->c }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ══ Filters over the findings list ══ --}}
        <form method="GET" class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 grid sm:grid-cols-4 gap-3">
            <select name="severity" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                <option value="">{{ __('health.audit_all_severities') }}</option>
                @foreach(['critical', 'warning', 'info'] as $sev)
                    <option value="{{ $sev }}" @selected(request('severity') === $sev)>{{ __('health.audit_sev_' . $sev) }}</option>
                @endforeach
            </select>
            <select name="category" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                <option value="">{{ __('health.audit_all_categories') }}</option>
                @foreach($categories as $cat)
                    <option value="{{ $cat }}" @selected(request('category') === $cat)>{{ __('health.audit_cat_' . $cat) }}</option>
                @endforeach
            </select>
            <select name="status" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                <option value="">{{ __('health.audit_all_statuses') }}</option>
                <option value="open_only" @selected(request('status') === 'open_only')>{{ __('health.audit_needs_attention') }}</option>
                @foreach(\App\Models\HealthAuditFinding::STATUSES as $st)
                    <option value="{{ $st }}" @selected(request('status') === $st)>{{ __('health.audit_fstatus_' . $st) }}</option>
                @endforeach
            </select>
            <button type="submit" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-sm font-bold transition">
                {{ __('health.audit_apply') }}
            </button>
            @if(request('rule'))
                <input type="hidden" name="rule" value="{{ request('rule') }}">
            @endif
        </form>

        {{-- ══ The findings ══ --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            @if($findings->isEmpty())
                <div class="px-5 py-12 text-center">
                    <p class="text-base font-black">{{ __('health.audit_nothing_found') }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('health.audit_nothing_found_hint') }}</p>
                </div>
            @else
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($findings as $finding)
                        <a href="{{ route('health.audit.finding', $finding->id) }}"
                           class="block px-5 py-4 hover:bg-gray-50 dark:hover:bg-gray-700/40 transition">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="px-2 py-0.5 rounded-lg text-[10px] font-black uppercase tracking-wide {{ $sevClass[$finding->severity] ?? $sevClass['info'] }}">
                                            {{ __('health.audit_sev_' . $finding->severity) }}
                                        </span>
                                        <span class="px-2 py-0.5 rounded-lg text-[10px] font-black uppercase tracking-wide {{ $statusClass[$finding->status] ?? $statusClass['open'] }}">
                                            {{ __('health.audit_fstatus_' . $finding->status) }}
                                        </span>
                                        <span class="text-[11px] font-bold text-gray-400">{{ __('health.audit_cat_' . $finding->category) }}</span>
                                    </div>
                                    <p class="text-sm font-black mt-1.5">{{ __('health.audit_rule_' . $finding->rule_key) }}</p>
                                    <p class="text-sm text-gray-600 dark:text-gray-300 mt-0.5">
                                        {{ __('health.audit_rule_' . $finding->rule_key . '_msg', (array) $finding->params) }}
                                    </p>
                                </div>
                                <div class="text-end shrink-0">
                                    @if($finding->occurred_on)
                                        <p class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ $finding->occurred_on->format('d M Y') }}</p>
                                    @endif
                                    @if($finding->amount !== null)
                                        <p class="text-sm font-black mt-0.5">{{ number_format((float) $finding->amount, 2) }}</p>
                                    @endif
                                    @if($finding->subject_name)
                                        <p class="text-xs text-gray-400 mt-0.5">{{ $finding->subject_name }}</p>
                                    @endif
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>

                <div class="px-5 py-4 border-t border-gray-200 dark:border-gray-700">
                    {{ $findings->links() }}
                </div>
            @endif
        </div>
    </div>
</x-health-layout>
