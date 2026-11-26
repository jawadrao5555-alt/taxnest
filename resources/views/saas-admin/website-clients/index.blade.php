<x-admin-layout>
<div class="mx-auto max-w-5xl space-y-4 p-4 sm:p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold text-white">Website Clients</h1>
        <a href="{{ route('saas.admin.website-clients.create') }}" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">Add client</a>
    </div>
    @if(session('success'))<p role="status" class="text-emerald-200">{{ session('success') }}</p>@endif
    @if($errors->any())<p role="alert" class="text-red-200">{{ $errors->first() }}</p>@endif
    @forelse($entries as $entry)
    <article class="space-y-3 rounded-xl border border-gray-800 bg-gray-900 p-4 text-gray-100">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-semibold">{{ $entry->display_name }}</h2>
                <p class="text-sm text-gray-300">{{ \App\Models\WebsiteClient::PRODUCTS[$entry->product] }} · {{ $entry->published_at ? 'Published' : 'Draft' }}</p>
            </div>
            <a href="{{ route('saas.admin.website-clients.edit', $entry->id) }}" class="text-sm underline">Review / Edit</a>
        </div>
        @if($entry->published_at)
        <form method="POST" action="{{ route('saas.admin.website-clients.visibility', $entry->id) }}">
            @csrf
            <input type="hidden" name="revision" value="{{ $entry->revision }}">
            <button name="action" value="unpublish" class="rounded-lg border border-gray-600 px-3 py-2 text-sm">Unpublish</button>
        </form>
        @endif
    </article>
    @empty
        <p class="text-gray-300">No clients have been added.</p>
    @endforelse
    {{ $entries->links() }}
</div>
</x-admin-layout>
