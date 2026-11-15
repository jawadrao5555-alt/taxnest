<x-app-layout>
    <div class="max-w-3xl mx-auto p-4 space-y-4" data-di-madadgar-read>
        <h1 class="text-xl font-bold">{{ __('madadgar_di.title') }}</h1>
        <p class="text-sm">{{ __('madadgar_di.scope') }}</p>
        <form method="POST" action="{{ route('di.madadgar.read') }}" class="flex flex-wrap gap-3">@csrf
            <label for="di-read-question">{{ __('madadgar_di.report') }}</label>
            <select id="di-read-question" name="question" class="rounded border-gray-300">
                <option value="today billing">{{ __('madadgar_di.billing') }}</option><option value="today tax breakdown">{{ __('madadgar_di.tax') }}</option><option value="today payments">{{ __('madadgar_di.payments') }}</option>
            </select>
            <button class="px-4 py-2 rounded bg-emerald-700 text-white" type="submit">{{ __('madadgar_di.read') }}</button>
        </form>
        @if($answer)
            <pre class="whitespace-pre-wrap break-words p-4 bg-gray-50 text-gray-900 rounded" data-di-read-result>{{ $answer['text'] }}</pre>
            @if(isset($answer['provenance']))<p class="text-xs break-all">{{ __('madadgar_di.audit') }} #{{ $answer['provenance']['read_audit_id'] }} · {{ __('madadgar_di.evidence') }} {{ $answer['provenance']['result_sha256'] }}</p>@endif
        @endif
        <p class="text-sm">{{ __('madadgar_di.caution') }}</p>
    </div>
</x-app-layout>
