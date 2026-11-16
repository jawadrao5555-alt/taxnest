<x-app-layout>
    <div class="max-w-3xl mx-auto p-4 space-y-4" data-di-madadgar-read>
        <h1 class="text-xl font-bold">{{ __('madadgar_di.title') }}</h1>
        <p class="text-sm">{{ __('madadgar_di.scope') }}</p>
        <form method="POST" action="{{ route('di.madadgar.read') }}" class="flex flex-wrap gap-3">@csrf
            <label for="di-read-question">{{ __('madadgar_di.report') }}</label>
            <select id="di-read-question" name="question" class="rounded border-gray-300">
                <option value="today billing" @selected(request('question', 'today billing') === 'today billing')>{{ __('madadgar_di.billing') }}</option><option value="today tax breakdown" @selected(request('question', 'today billing') === 'today tax breakdown')>{{ __('madadgar_di.tax') }}</option><option value="today payments" @selected(request('question', 'today billing') === 'today payments')>{{ __('madadgar_di.payments') }}</option>
            </select>
            <button class="px-4 py-2 rounded bg-emerald-700 text-white" type="submit">{{ __('madadgar_di.read') }}</button>
        </form>
        @if($answer)
            @php($evidence = $answer['read_evidence'] ?? [])
            @php($rows = \App\Support\DiReadPresentation::rows($evidence))
            <section class="p-4 bg-gray-50 text-gray-900 rounded space-y-3" data-di-read-result>
                <p>{{ explode("\n", $answer['text'])[0] }}</p>
                @if($rows)
                <dl class="space-y-3" data-di-read-metrics>
                    @foreach($rows as $row)
                    <div class="border-b border-gray-300 pb-2" data-di-metric="{{ $row['key'] }}">
                        <dt class="text-sm">@if($row['classification']){{ __('madadgar_di.'.$row['classification']) }} · @endif{{ __('madadgar_di.'.$row['label_key']) }}</dt>
                        <dd class="font-semibold text-lg">@if($row['value'] === null){{ __('madadgar_di.unknown_value') }}@else{{ $row['money'] ? 'PKR ' : '' }}{{ $row['value'] }}@endif</dd>
                    </div>
                    @endforeach
                </dl>
                @else<p data-di-read-unknown>{{ __('madadgar_di.unavailable') }}</p>@endif
                @if($evidence)
                <dl class="text-sm space-y-2" data-di-read-source>
                    <div><dt>{{ __('madadgar_di.source') }}</dt><dd>{{ implode(', ', array_intersect($evidence['sources'] ?? [], ['invoices','invoice_items'])) ?: __('madadgar_di.unknown_value') }}</dd></div>
                    <div><dt>{{ __('madadgar_di.date') }}</dt><dd>{{ $evidence['report_date'] ?? '' }} ({{ $evidence['timezone'] ?? '' }})</dd></div>
                    <div><dt>{{ __('madadgar_di.range') }}</dt><dd>{{ isset($evidence['branch_id']) ? __('madadgar_di.campus').' #'.$evidence['branch_id'] : __('madadgar_di.company_scope') }}</dd></div>
                    <div><dt>{{ __('madadgar_di.read_at') }}</dt><dd>{{ $evidence['read_at'] ?? '' }}</dd></div>
                </dl>
                @endif
            </section>
            @if(isset($answer['provenance']))<p class="text-xs break-all">{{ __('madadgar_di.audit') }} #{{ $answer['provenance']['read_audit_id'] }} · {{ __('madadgar_di.evidence') }} {{ $answer['provenance']['result_sha256'] }}</p>@endif
        @endif
        <p class="text-sm">{{ __('madadgar_di.caution') }}</p>
    </div>
</x-app-layout>
