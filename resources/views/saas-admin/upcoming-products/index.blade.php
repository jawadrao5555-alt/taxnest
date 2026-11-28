<x-admin-layout>
    <main class="tn-content-rail mx-auto max-w-5xl space-y-4 p-4 sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-2xl font-bold">Upcoming Products</h1>
            <a class="tn-admin-action rounded-lg px-3 py-2" href="{{ route('saas.admin.upcoming-products.create') }}">Add product</a>
        </div>
        <p>Manage the public roadmap and confirmed interest. Publication does not create a checkout or subscription.</p>
        <p><a class="underline" href="{{ route('upcoming-products.index') }}">View public page</a></p>
        @if(session('status'))<p class="tn-admin-notice tn-admin-notice--success rounded-lg px-3 py-2" role="status">{{ session('status') }}</p>@endif
        @forelse($products as $product)
            <article class="tn-account-panel flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3">
                <div>
                    <h2 class="font-semibold">{{ $product->name }}</h2>
                    <p class="text-sm">
                        {{ \App\Models\UpcomingProduct::STATUSES[$product->status] }} ·
                        @if($product->status === \App\Models\UpcomingProduct::RELEASED)
                            Off public roadmap
                        @else
                            {{ $product->published_at ? 'Published' : 'Draft' }}
                        @endif
                    </p>
                </div>
                <div class="flex flex-wrap gap-3 text-sm">
                    <a class="underline" href="{{ route('saas.admin.upcoming-products.edit', $product) }}">Edit {{ $product->name }}</a>
                    <a class="underline" href="{{ route('saas.admin.upcoming-products.interests', $product) }}">Confirmed interest ({{ $product->confirmed_interests_count }})</a>
                </div>
            </article>
        @empty
            <p>No roadmap products.</p>
        @endforelse
        {{ $products->links() }}
    </main>
</x-admin-layout>
