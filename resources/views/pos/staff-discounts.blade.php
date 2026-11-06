<x-pos-layout>
<div class="max-w-5xl mx-auto p-4 space-y-4">
    <h1 class="text-2xl font-bold">Staff / family discount requests</h1>
    <p>Request banne par bill, payment ya fiscal submission nahi hoti. Owner ki approval ke baad cashier usi cart se payment complete kare.</p>
    <a class="underline" href="{{ route('pos.invoice.create') }}">Back to sale</a>
    @if($errors->any()) <p class="text-red-700">{{ $errors->first() }}</p> @endif
    @if(session('success')) <p class="text-green-700">{{ session('success') }}</p> @endif
    @if($canReview)
    <form method="POST" action="{{ route('pos.staff-discounts.settings') }}" class="p-3 border rounded">
        @csrf
        <label><select name="enabled"><option value="0" @selected(!$company->pos_staff_discount_enabled)>Off</option><option value="1" @selected($company->pos_staff_discount_enabled)>On</option></select> Staff / family approval feature</label>
        <button class="border px-3 py-1 rounded">Save preference</button>
    </form>
    @endif
    @forelse($requests as $row)
    @php($snapshot = json_decode($row->order_snapshot, true) ?? [])
    <article class="border rounded p-4 space-y-2" data-staff-request="{{ $row->id }}">
        <h2 class="font-bold">Request #{{ $row->id }} — {{ $row->status }} — {{ $employees->firstWhere('id', $row->employee_id)?->name ?? 'Former employee #'.$row->employee_id }}</h2>
        <p>{{ $row->kind }}: {{ $row->reason }}</p>
        <p>Eligible subtotal Rs {{ number_format($row->eligible_subtotal, 2) }}. Proposed {{ $row->proposed_value }} {{ $row->proposed_type === 'percentage' ? '%' : 'rupees' }}. Cashier #{{ $row->requester_id }}; branch {{ $row->branch_id ?? 'legacy' }}.</p>
        <ul>@foreach(($snapshot['items'] ?? []) as $item)<li>{{ $item['quantity'] ?? '' }} × {{ $item['name'] ?? $item['item_name'] ?? '' }} — Rs {{ $item['lineTotal'] ?? '' }}</li>@endforeach</ul>
        @if($row->status === 'approved')<p>Approved: {{ $row->approved_value }} {{ $row->approved_type === 'percentage' ? '%' : 'rupees' }}. Return to the original cart and check approval.</p>@endif
        @if(in_array($row->status, ['pending', 'approved']))
        @if((int)$row->requester_id === (int)auth('pos')->id())<a class="underline" href="{{ route('pos.invoice.create', ['staff_request'=>$row->id]) }}">Resume original cart</a>@endif
        <form method="POST" action="{{ route('pos.staff-discounts.review', $row->id) }}" class="flex flex-wrap gap-2">
            @csrf<input type="hidden" name="revision" value="{{ $row->revision }}">
            @if($canReview)
            <select name="discount_type"><option value="percentage" @selected(($row->approved_type ?? $row->proposed_type) === 'percentage')>Percent</option><option value="amount" @selected(($row->approved_type ?? $row->proposed_type) === 'amount')>Rupees</option></select>
            <input aria-label="Approved discount" name="discount_value" type="number" step="0.01" min="0.01" value="{{ $row->approved_value ?? $row->proposed_value }}" class="w-28">
            <button name="action" value="approve" class="border rounded p-2">Approve / revise</button>
            <button name="action" value="reject" class="border rounded p-2">Reject</button>
            @endif
            <button name="action" value="cancel" class="border rounded p-2">Cancel request</button>
        </form>
        @endif
    </article>
    @empty <p>No requests.</p> @endforelse
</div>
</x-pos-layout>
