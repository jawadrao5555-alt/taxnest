<x-pos-layout>
    <div class="max-w-5xl mx-auto p-4 space-y-4">
        <h1 class="text-xl font-bold">Delivery Udhaar Book</h1>
        @if($errors->any())
            <p role="alert" class="text-red-700">{{ $errors->first() }}</p>
        @endif
        @if(session('success'))
            <p role="status" class="text-emerald-700">{{ session('success') }}</p>
        @endif
        <form method="GET" class="flex gap-2">
            <input name="search" value="{{ request('search') }}" maxlength="100" aria-label="Customer or rider" placeholder="Customer, phone or rider" class="rounded">
            <button class="px-3 py-2 rounded bg-blue-700 text-white">Search</button>
        </form>
        @forelse($accounts as $account)
            <article class="p-4 border rounded space-y-2">
                <h2 class="font-semibold">{{ $account->customer_name ?: 'Customer' }} {{ $account->customer_phone }}</h2>
                <p>{{ $account->business_date }} · {{ $account->rider_name }}</p>
                <p>Rider cash Rs. {{ number_format($account->received_amount, 2) }} · Udhaar Rs. {{ number_format($account->credit_amount, 2) }} · Balance Rs. {{ number_format($account->outstanding_amount, 2) }}</p>
                @if($account->outstanding_amount > 0)
                    <form method="POST" action="{{ route('pos.delivery-accounts.collect', $account->id) }}" class="flex flex-wrap gap-2 items-center">
                        @csrf
                        <input type="hidden" name="request_id" value="{{ Str::uuid() }}">
                        <label>Cash received <input type="number" name="amount" min="0.01" max="{{ $account->outstanding_amount }}" step="0.01" required class="rounded"></label>
                        <button class="px-3 py-2 rounded bg-emerald-700 text-white">Record recovery</button>
                    </form>
                @endif
                @foreach($events->get($account->id, []) as $event)
                    <p class="text-sm">{{ $event->business_date }} · {{ $event->kind === 'collection' ? 'Recovered' : 'Credit reversed' }} · Rs. {{ number_format($event->amount, 2) }}</p>
                @endforeach
            </article>
        @empty
            <p>No matching customer credit.</p>
        @endforelse
        {{ $accounts->links() }}
    </div>
</x-pos-layout>
