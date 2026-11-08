<x-admin-layout>
<div class="p-4 sm:p-6 max-w-4xl mx-auto">
    <a href="{{ route('saas.admin.support-cases') }}" class="text-sm text-gray-400 hover:text-gray-200">&larr; Support cases</a>
    <div class="mt-3 rounded-xl border border-gray-800 bg-gray-900 p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white">Case #{{ $supportCase->id }} · {{ $supportCase->subject }}</h1>
                <p class="mt-1 text-sm text-gray-400">{{ $supportCase->company?->account_code ? $supportCase->company->account_code.' · ' : '' }}{{ $supportCase->company?->name ?? $supportCase->company_name_snapshot ?? 'Deleted company record' }}</p>
            </div>
            <span class="rounded-full border border-gray-700 px-3 py-1 text-xs font-bold text-gray-300">{{ strtoupper($supportCase->status) }}</span>
        </div>

        <dl class="mt-5 grid gap-3 sm:grid-cols-2 text-xs">
            <div><dt class="text-gray-500">Owner</dt><dd class="mt-1 font-semibold text-gray-200">{{ $supportCase->assignedAdmin?->name ?? 'Unassigned' }}</dd></div>
            <div><dt class="text-gray-500">Priority</dt><dd class="mt-1 font-semibold text-gray-200">{{ strtoupper($supportCase->priority ?? \App\Models\SupportCase::PRIORITY_NORMAL) }}</dd></div>
            <div><dt class="text-gray-500">SLA due</dt><dd class="mt-1 font-semibold text-gray-200">{{ $supportCase->sla_due_at?->format('d M Y, h:i A') ?? 'Not set' }}</dd></div>
            <div><dt class="text-gray-500">Opened</dt><dd class="mt-1 text-gray-300">{{ $supportCase->opened_at?->format('d M Y, h:i A') }}</dd></div>
            <div><dt class="text-gray-500">Source</dt><dd class="mt-1 font-mono text-gray-300">{{ $supportCase->source_box }} / {{ $supportCase->source_uid }}</dd></div>
            <div><dt class="text-gray-500">Last resolution build</dt><dd class="mt-1 font-mono text-gray-300">{{ $supportCase->resolution_build ?? 'Not resolved yet' }}</dd></div>
        </dl>

        <form method="POST" action="{{ route('saas.admin.support-inbox.case.assign', $supportCase) }}" class="mt-5 flex flex-col sm:flex-row gap-3 sm:items-end">
            @csrf
            <div class="flex-1">
                <label for="case-owner" class="block text-xs font-medium text-gray-400 mb-1">Case owner</label>
                <select id="case-owner" name="assigned_admin_id" required class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2">
                    @foreach($supportOwners as $owner)
                        <option value="{{ $owner->id }}" @selected((int) $supportCase->assigned_admin_id === (int) $owner->id)>{{ $owner->name }} · {{ $owner->role }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="case-priority" class="block text-xs font-medium text-gray-400 mb-1">Case priority</label>
                <select id="case-priority" name="priority" class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2">
                    @foreach(\App\Models\SupportCase::PRIORITIES as $priority)
                        <option value="{{ $priority }}" @selected(old('priority', $supportCase->priority ?? \App\Models\SupportCase::PRIORITY_NORMAL) === $priority)>{{ strtoupper($priority) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="case-sla" class="block text-xs font-medium text-gray-400 mb-1">SLA due (optional)</label>
                <input id="case-sla" name="sla_due_at" type="datetime-local" value="{{ old('sla_due_at', $supportCase->sla_due_at?->format('Y-m-d\\TH:i')) }}" class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2" />
            </div>
            <button type="submit" class="px-4 py-2 rounded-lg bg-gray-700 hover:bg-gray-600 text-white text-sm font-semibold">Update owner, priority and SLA</button>
        </form>

        @if($supportCase->status !== 'resolved')
            <form method="POST" action="{{ route('saas.admin.support-inbox.case.resolve', $supportCase) }}" class="mt-5 grid gap-3 sm:grid-cols-2">
                @csrf
                <div><label for="case-build" class="block text-xs font-medium text-gray-400 mb-1">Resolution build / release</label><input id="case-build" name="resolution_build" required maxlength="100" placeholder="e.g. 1.13.35 or commit SHA" class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2" /></div>
                <div><label for="case-resolution-note" class="block text-xs font-medium text-gray-400 mb-1">Resolution evidence</label><textarea id="case-resolution-note" name="resolution_note" required maxlength="2000" rows="2" class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2"></textarea></div>
                <div class="sm:col-span-2 flex justify-end"><button type="submit" class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold">Resolve explicitly</button></div>
            </form>
        @else
            <form method="POST" action="{{ route('saas.admin.support-inbox.case.reopen', $supportCase) }}" class="mt-5 flex flex-col sm:flex-row gap-3 sm:items-end">
                @csrf
                <div class="flex-1"><label for="case-reopen-note" class="block text-xs font-medium text-gray-400 mb-1">Reopen evidence</label><textarea id="case-reopen-note" name="reopen_note" required maxlength="2000" rows="2" class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2"></textarea></div>
                <button type="submit" class="px-4 py-2 rounded-lg bg-amber-600 hover:bg-amber-500 text-white text-sm font-semibold">Reopen case</button>
            </form>
        @endif
    </div>

    <section class="mt-4 rounded-xl border border-gray-800 bg-gray-900 p-5" aria-labelledby="case-links-heading">
        <h2 id="case-links-heading" class="text-sm font-bold text-white">Related evidence and duplicate messages</h2>
        <p class="mt-2 text-xs text-gray-400">Link another case already attributed to this company. Its source message and timeline remain separate. A link does not resolve either case. The latest 50 links are shown; earlier links remain in the timeline.</p>
        <ul class="mt-3 space-y-2 text-xs text-gray-300">
            @forelse($caseLinks as $link)
                <li><a class="text-blue-300 underline" href="{{ route('saas.admin.support-cases.show', $link['case_id']) }}">{{ ucfirst($link['kind']) }} case #{{ $link['case_id'] }}</a> — {{ $link['note'] }}</li>
            @empty
                <li>No linked case evidence.</li>
            @endforelse
        </ul>
        @if($supportCase->company_id)
        <form method="POST" action="{{ route('saas.admin.support-inbox.case.link', $supportCase) }}" class="mt-4 grid gap-3 sm:grid-cols-2">
            @csrf
            <div><label for="related-case-id" class="block text-xs text-gray-400 mb-1">Related case number</label><input id="related-case-id" name="related_case_id" type="number" min="1" required class="w-full rounded-lg bg-gray-800 text-white border border-gray-700 p-2" /></div>
            <div><label for="case-link-kind" class="block text-xs text-gray-400 mb-1">Link type</label><select id="case-link-kind" name="kind" class="w-full rounded-lg bg-gray-800 text-white border border-gray-700 p-2"><option value="related">Related evidence</option><option value="duplicate">Duplicate message</option></select></div>
            <div class="sm:col-span-2"><label for="case-link-note" class="block text-xs text-gray-400 mb-1">Link evidence note</label><textarea id="case-link-note" name="note" required minlength="3" maxlength="1000" class="w-full rounded-lg bg-gray-800 text-white border border-gray-700 p-2"></textarea></div>
            <button class="justify-self-start rounded-lg bg-gray-700 text-white px-4 py-2 text-sm" type="submit">Link case evidence</button>
        </form>
        @endif
    </section>

    <section class="mt-4 rounded-xl border border-gray-800 bg-gray-900 p-5" aria-labelledby="case-timeline-heading">
        <h2 id="case-timeline-heading" class="text-sm font-bold text-white">Immutable timeline</h2>
        <ol class="mt-4 space-y-4">
            @foreach($supportCase->events as $event)
                <li class="border-l-2 border-gray-700 pl-3 text-xs">
                    <div class="flex flex-wrap gap-x-2 text-gray-300"><strong>{{ ucfirst($event->event_type) }}</strong><span>{{ $event->actor?->name ?? 'Former administrator' }}</span><time class="text-gray-500">{{ $event->occurred_at?->format('d M Y, h:i A') }}</time>@if($event->resolution_build)<code class="text-emerald-300">{{ $event->resolution_build }}</code>@endif</div>
                    @if($event->note)<p class="mt-1 whitespace-pre-wrap text-gray-400">{{ $event->note }}</p>@endif
                </li>
            @endforeach
        </ol>
    </section>
</div>
</x-admin-layout>
