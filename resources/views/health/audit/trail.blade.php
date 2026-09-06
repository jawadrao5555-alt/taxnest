{{--
    The recorded acts themselves.

    The findings answer "what looks wrong". This answers "what happened", which
    is the question an owner asks the moment they stop trusting a summary. The
    chain check lives here rather than on the summary screen because the honest
    place to say "rows are missing" is on the page showing the rows.
--}}
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div>
            <a href="{{ route('health.audit') }}" class="text-xs font-bold text-teal-700 dark:text-teal-300 hover:underline">&larr; {{ __('health.audit_title') }}</a>
            <h1 class="text-xl sm:text-2xl font-black tracking-tight mt-1">{{ __('health.audit_trail_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.audit_trail_subtitle') }}</p>
        </div>

        {{-- ══ Is the trail itself intact? ══ --}}
        @php
            $anchorStatus = $chain['anchor']['status'] ?? 'empty';
            $anchorBroken = !in_array($anchorStatus, ['intact', 'empty'], true);
            $broken = ($chain['altered'] ?? 0) + ($chain['missing'] ?? 0) + ($anchorBroken ? 1 : 0);
        @endphp
        <div class="rounded-2xl p-4 border {{ $broken
            ? 'bg-red-50 dark:bg-red-900/20 border-red-300 dark:border-red-800'
            : 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-800' }}">
            <p class="text-sm font-black {{ $broken ? 'text-red-900 dark:text-red-100' : 'text-emerald-900 dark:text-emerald-100' }}">
                @if($broken)
                    {{ __('health.audit_chain_broken', ['altered' => $chain['altered'] ?? 0, 'missing' => $chain['missing'] ?? 0]) }}
                @else
                    {{ __('health.audit_chain_intact', ['count' => number_format($chain['checked'] ?? 0)]) }}
                @endif
            </p>
            <p class="text-xs mt-1 {{ $broken ? 'text-red-800 dark:text-red-200' : 'text-emerald-800 dark:text-emerald-200' }}">
                {{ __('health.audit_chain_hint') }}
            </p>
            @if($broken && !empty($chain['altered_ids']))
                <p class="text-[11px] font-mono mt-2 text-red-700 dark:text-red-300 break-all">
                    {{ __('health.audit_chain_altered_ids') }}: {{ implode(', ', $chain['altered_ids']) }}
                </p>
            @endif
            @if($broken && !empty($chain['missing_ids']))
                <p class="text-[11px] font-mono mt-1 text-red-700 dark:text-red-300 break-all">
                    {{ __('health.audit_chain_missing_ids') }}: {{ implode(', ', $chain['missing_ids']) }}
                </p>
            @endif
            @if($anchorBroken)
                <p class="text-xs font-bold mt-2 text-red-800 dark:text-red-200">
                    {{ __('health.audit_anchor_' . $anchorStatus, [
                        'expected' => number_format($chain['anchor']['expected_count'] ?? 0),
                        'actual' => number_format($chain['anchor']['actual_count'] ?? 0),
                    ]) }}
                </p>
            @endif
        </div>

        {{-- ══ Filters ══ --}}
        <form method="GET" class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 grid sm:grid-cols-2 lg:grid-cols-5 gap-3">
            <input type="date" name="date_from" value="{{ $from }}" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            <input type="date" name="date_to" value="{{ $to }}" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            <select name="category" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                <option value="">{{ __('health.audit_all_categories') }}</option>
                @foreach($categories as $cat)
                    <option value="{{ $cat }}" @selected(request('category') === $cat)>{{ __('health.audit_cat_' . $cat) }}</option>
                @endforeach
            </select>
            <select name="actor_user_id" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                <option value="">{{ __('health.audit_all_staff') }}</option>
                @foreach($staff as $member)
                    <option value="{{ $member->id }}" @selected((string) request('actor_user_id') === (string) $member->id)>{{ $member->name }}</option>
                @endforeach
            </select>
            <div class="flex gap-2">
                <input type="text" name="q" value="{{ request('q') }}" placeholder="{{ __('health.audit_search') }}"
                       class="flex-1 min-w-0 rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                    {{ __('health.audit_apply') }}
                </button>
            </div>
        </form>

        {{-- ══ The acts ══ --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            @if($events->isEmpty())
                <p class="px-5 py-12 text-sm text-center text-gray-500 dark:text-gray-400">{{ __('health.audit_no_events') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-3 text-start font-bold">{{ __('health.audit_when') }}</th>
                                <th class="px-4 py-3 text-start font-bold">{{ __('health.audit_event') }}</th>
                                <th class="px-4 py-3 text-start font-bold">{{ __('health.audit_staff_member') }}</th>
                                <th class="px-4 py-3 text-start font-bold">{{ __('health.audit_record') }}</th>
                                <th class="px-4 py-3 text-end font-bold">{{ __('health.audit_amount') }}</th>
                                <th class="px-4 py-3 text-start font-bold">{{ __('health.audit_reason') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach($events as $event)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40 {{ $event->is_sensitive ? 'bg-amber-50/60 dark:bg-amber-900/10' : '' }}">
                                    <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
                                        {{ $event->occurred_at?->format('d M H:i') }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="font-bold">{{ $event->event }}</span>
                                        <span class="block text-[11px] text-gray-400">{{ __('health.audit_cat_' . $event->category) }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        {{ $event->actor_name ?: __('health.audit_actor_system') }}
                                        <span class="block text-[11px] text-gray-400">{{ $event->actor_role }}{{ $event->ip_address ? ' · ' . $event->ip_address : '' }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        {{ $event->entity_label ?: '—' }}
                                        <span class="block text-[11px] text-gray-400">{{ $event->entity_type }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-end font-bold">{{ $event->amount === null ? '—' : number_format((float) $event->amount, 2) }}</td>
                                    <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-300 max-w-xs">{{ $event->reasonFor(auth()->guard('health')->user()) ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="px-5 py-4 border-t border-gray-200 dark:border-gray-700">
                    {{ $events->links() }}
                </div>
            @endif
        </div>

        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('health.audit_trail_privacy_note') }}</p>
    </div>
</x-health-layout>
