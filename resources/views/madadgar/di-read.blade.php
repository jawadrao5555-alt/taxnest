<x-app-layout>
    <div class="max-w-3xl mx-auto p-4 space-y-4" data-di-madadgar-read>
        <h1 class="text-xl font-bold">Madadgar: DI recorded totals</h1>
        <p class="text-sm">Owner-only, current company and authorized branch. Stored records only. No external AI, billing changes or fiscal submission.</p>
        <form method="POST" action="{{ route('di.madadgar.read') }}" class="flex flex-wrap gap-3">@csrf
            <label for="di-read-question">Today's report</label>
            <select id="di-read-question" name="question" class="rounded border-gray-300">
                <option value="today billing">Billing</option><option value="today tax breakdown">Stored tax breakdown</option><option value="today payments">Recorded payment fields</option>
            </select>
            <button class="px-4 py-2 rounded bg-emerald-700 text-white" type="submit">Read records</button>
        </form>
        @if($answer)
            <pre class="whitespace-pre-wrap break-words p-4 bg-gray-50 text-gray-900 rounded" data-di-read-result>{{ $answer['text'] }}</pre>
            @if(isset($answer['provenance']))<p class="text-xs break-all">Audit #{{ $answer['provenance']['read_audit_id'] }} · Evidence {{ $answer['provenance']['result_sha256'] }}</p>@endif
        @endif
        <p class="text-sm">Missing fields mean unknown, not zero. DI locked billing can include sandbox records and is not proof of production revenue or received cash.</p>
    </div>
</x-app-layout>
