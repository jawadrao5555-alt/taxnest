<x-admin-layout>
<div class="mx-auto max-w-5xl space-y-4 p-4 sm:p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold text-white">Website Clients</h1>
        <a href="{{ route('saas.admin.website-clients.create') }}" class="text-sm text-gray-300 underline">Add manually</a>
    </div>
    @if(session('success'))<p role="status" class="text-emerald-200">{{ session('success') }}</p>@endif
    @if($errors->any())<p role="alert" class="text-red-200">{{ $errors->first() }}</p>@endif

    <section class="space-y-3 rounded-xl border border-gray-800 bg-gray-900 p-4 text-gray-100" aria-labelledby="company-candidates-heading">
        <h2 id="company-candidates-heading" class="text-lg font-semibold">Company candidates</h2>
        <p class="text-sm text-gray-300">Approved, active companies appear here for review. Confirm production use and permission before publishing a card.</p>
        <form method="GET" action="{{ route('saas.admin.website-clients.index') }}" class="flex flex-wrap items-end gap-2">
            <label class="block text-sm">Company name or account code
                <input name="q" value="{{ $search }}" maxlength="120" class="mt-1 block w-full rounded-lg border-gray-700 bg-gray-950">
            </label>
            <button class="rounded-lg border border-gray-600 px-3 py-2 text-sm">Search</button>
            @if($search !== '')<a href="{{ route('saas.admin.website-clients.index') }}" class="p-2 text-sm underline">Clear search</a>@endif
        </form>
        <ul class="divide-y divide-gray-800">
            @forelse($candidates as $candidate)
            <li class="flex flex-wrap items-center justify-between gap-2 py-3">
                <div>
                    <p class="font-semibold">{{ $candidate->name }}</p>
                    <p class="text-sm text-gray-300">{{ \App\Models\WebsiteClient::PRODUCTS[$candidate->website_product] }}@if($candidate->account_code) · {{ $candidate->account_code }}@endif</p>
                </div>
                <a href="{{ route('saas.admin.website-clients.create', ['company' => $candidate->id]) }}" class="rounded-lg bg-emerald-700 px-3 py-2 text-sm font-semibold text-white" aria-label="Review {{ $candidate->name }}">Review candidate</a>
            </li>
            @empty
            <li class="py-3 text-sm text-gray-300">No companies match.</li>
            @endforelse
        </ul>
        {{ $candidates->links() }}
    </section>

    <h2 class="text-lg font-semibold text-white">Saved client cards</h2>
    @forelse($entries as $entry)
    <article class="space-y-3 rounded-xl border border-gray-800 bg-gray-900 p-4 text-gray-100">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-semibold">{{ $entry->display_name }}</h2>
                <p class="text-sm text-gray-300">{{ \App\Models\WebsiteClient::PRODUCTS[$entry->product] }} · {{ in_array($entry->id, $publicIds, true) ? 'Published' : ($entry->published_at ? 'Hidden' : 'Draft') }}</p>
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
