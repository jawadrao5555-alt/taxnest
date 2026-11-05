<x-health-layout>
<div class="max-w-5xl mx-auto p-5 space-y-5">
    <h1 class="text-2xl font-bold">{{ __(\App\Services\HealthModuleService::moduleLabelKey($service)) }}</h1>
    @if(in_array($service.'.manage', $capabilities))
    <details class="rounded border p-4"><summary>Test catalogue</summary>
        <form class="mt-3 grid gap-2 sm:grid-cols-2" method="post" action="{{ route('health.'.$service.'.catalog') }}">@csrf
            @foreach(['code'=>'Code','name'=>'Test name','price'=>'Price (PKR)','unit'=>'Result unit','reference_interval'=>'Reference interval'] as $field => $label)
                <label>{{ $label }} <input class="block border rounded p-1" name="{{ $field }}" @required(in_array($field, ['code','name','price']))></label>
            @endforeach
            <button class="rounded bg-teal-700 text-white p-2">Add test</button>
        </form>
    </details>
    @endif
    @if(in_array($service.'.order', $capabilities) && $tests->isNotEmpty())
    <form class="rounded border p-4 flex flex-wrap items-end gap-3" method="post" action="{{ route('health.'.$service.'.order') }}">@csrf
        <input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
        <label>Patient record ID <input class="block rounded border" type="number" name="patient_id" min="1" required></label>
        <label>Test <select class="block rounded border" name="test_id">@foreach($tests as $test)
            <option value="{{ $test->id }}">{{ $test->name }} · PKR {{ $test->price }}</option>@endforeach</select></label>
        <button class="rounded bg-teal-700 text-white p-2">Order test</button>
    </form>
    @endif
    <h2 class="font-bold">Worklist</h2>
    @forelse($orders as $order)
        <section class="rounded border p-3 space-y-2">
            <p><strong>#{{ $order->id }} {{ $order->test_name }}</strong> · Patient {{ $order->health_patient_id }} · {{ ucfirst($order->status) }}</p>
            @if($order->status === 'ordered' && in_array($service.'.collect', $capabilities))
                <form method="post" action="{{ route('health.'.$service.'.collect', $order->id) }}">@csrf
                <label>{{ $service === 'lab' ? 'Sample barcode' : 'Study accession' }} <input class="border rounded" name="accession" required></label>
                <button class="underline">Record collection</button></form>
            @elseif($order->status === 'collected' && in_array($service.'.result', $capabilities))
                <form method="post" action="{{ route('health.'.$service.'.release', $order->id) }}">@csrf
                <label>Result <textarea class="block border rounded w-full" name="result" required></textarea></label>
                <button class="underline">Sign and release</button></form>
            @elseif($order->status === 'released' && (in_array($service.'.result', $capabilities) || in_array('clinical.view', $capabilities)))
                <a class="underline" href="{{ route('health.'.$service.'.report', $order->id) }}">Open PDF report</a>
                @if(in_array($service.'.result', $capabilities))
                <details class="border rounded p-3"><summary>Correct signed report (revision {{ $order->amendments_count }})</summary>
                    <p>The original and previous signed results remain in the report history. Inform the treating clinician of the correction.</p>
                    <form method="post" action="{{ route('health.'.$service.'.amend', $order->id) }}">@csrf
                        <input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <input type="hidden" name="expected_revision" value="{{ $order->amendments_count }}">
                        <label>Complete corrected result <textarea class="block border rounded w-full" name="result" maxlength="12000" required></textarea></label>
                        <label>Reason for correction <textarea class="block border rounded w-full" name="reason" maxlength="2000" required></textarea></label>
                        <button class="underline">Sign corrected report</button>
                    </form>
                </details>
                @endif
            @endif
        </section>
    @empty <p>No orders.</p> @endforelse
    {{ $orders->links() }}
</div>
</x-health-layout>
