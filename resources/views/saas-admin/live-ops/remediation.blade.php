<x-admin-layout>
<div class="p-4 sm:p-6 max-w-3xl mx-auto">
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-bold text-white">Remediation</h1>
        <a href="{{ route('saas.admin.live-ops') }}" class="text-sm text-indigo-400 hover:underline">Back</a>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-800 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-300">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg border border-red-800 bg-red-950/40 px-4 py-3 text-sm text-red-300">{{ session('error') }}</div>
    @endif

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-4 space-y-2 text-sm text-gray-200 mb-6">
        <p><span class="text-gray-500">action_id</span> {{ $row->action_id }}</p>
        <p><span class="text-gray-500">action</span> {{ $row->action }} ({{ $row->risk }})</p>
        <p><span class="text-gray-500">company</span> {{ $row->company_id }}</p>
        <p><span class="text-gray-500">status</span> {{ $row->status }}</p>
        <p><span class="text-gray-500">proposal</span> {{ $row->proposal }}</p>
        <p><span class="text-gray-500">idempotency</span> {{ $row->idempotency_key }}</p>
        <pre class="text-xs bg-gray-950 rounded-lg p-3 overflow-auto">{{ json_encode($row->parameters, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        @if($row->execution_result)
            <h3 class="text-white font-semibold pt-2">Execution</h3>
            <pre class="text-xs bg-gray-950 rounded-lg p-3 overflow-auto">{{ json_encode($row->execution_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        @endif
        @if($row->verification_result)
            <h3 class="text-white font-semibold pt-2">Verification</h3>
            <pre class="text-xs bg-gray-950 rounded-lg p-3 overflow-auto">{{ json_encode($row->verification_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        @endif
    </div>

    @if($row->status === 'proposed')
        <form method="post" action="{{ route('saas.admin.live-ops.approve', $row->action_id) }}" class="bg-gray-900 border border-gray-800 rounded-xl p-4 space-y-3 mb-4">
            @csrf
            <label class="block text-xs text-gray-500">Owner approval phrase</label>
            <input type="text" name="owner_approval_phrase" required placeholder="{{ $approvalPhrase }}" class="w-full rounded-lg bg-gray-950 border border-gray-700 text-sm text-white px-3 py-2 font-mono">
            <label class="inline-flex items-center gap-2 text-sm text-gray-300">
                <input type="checkbox" name="execute_now" value="1" class="rounded border-gray-600">
                Execute immediately after approve
            </label>
            <div class="flex gap-2">
                <button class="px-3 py-2 rounded-lg bg-emerald-600 text-white text-sm">Approve</button>
            </div>
        </form>
        <form method="post" action="{{ route('saas.admin.live-ops.reject', $row->action_id) }}">
            @csrf
            <button class="px-3 py-2 rounded-lg bg-red-700 text-white text-sm">Reject</button>
        </form>
    @elseif($row->status === 'approved')
        <form method="post" action="{{ route('saas.admin.live-ops.execute', $row->action_id) }}">
            @csrf
            <button class="px-3 py-2 rounded-lg bg-indigo-600 text-white text-sm">Execute approved action</button>
        </form>
    @endif
</div>
</x-admin-layout>
