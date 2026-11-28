<x-admin-layout>
    <main class="tn-content-rail mx-auto max-w-4xl space-y-4 p-4 sm:p-6">
        <p><a class="underline" href="{{ route('saas.admin.upcoming-products.index') }}">Back to Upcoming Products</a></p>
        <h1 class="text-2xl font-bold">Confirmed interest for {{ $product->name }}</h1>
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <label>Purpose
                <select name="purpose" class="block rounded-lg border border-slate-500 bg-white p-2 text-slate-900 dark:bg-slate-900 dark:text-white">
                    <option value="">All purposes</option>
                    @foreach(\App\Models\UpcomingProductInterest::PURPOSES as $key => $label)
                        <option value="{{ $key }}" @selected($purpose === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <button class="tn-admin-action rounded-lg px-3 py-2">Filter</button>
        </form>
        @forelse($interests as $interest)
            <article class="tn-account-panel space-y-1 rounded-lg border p-3">
                <h2 class="break-all font-semibold">{{ $interest->email }}</h2>
                <p>{{ \App\Models\UpcomingProductInterest::PURPOSES[$interest->purpose] }}</p>
                <p class="text-sm">Consent {{ $interest->consent_version }} · Confirmed {{ $interest->confirmed_at->format('j M Y, H:i') }}</p>
            </article>
        @empty
            <p>No confirmed interest for this selection.</p>
        @endforelse
        {{ $interests->links() }}
    </main>
</x-admin-layout>
