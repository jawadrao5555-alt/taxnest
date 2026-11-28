<x-admin-layout>
<div class="tn-website-clients mx-auto max-w-5xl space-y-4 p-4 sm:p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold">Website Clients</h1>
        <a href="{{ route('saas.admin.website-clients.create') }}" class="tn-website-client-action">Add manually</a>
    </div>
    <section class="tn-website-client-panel space-y-3 rounded-xl border p-4" aria-labelledby="company-candidates-heading">
        <h2 id="company-candidates-heading" class="text-lg font-semibold">Company candidates</h2>
        <p class="text-sm">Approved, active companies appear here for review.</p>
        <div class="grid gap-4 lg:grid-cols-2">
            @include('saas-admin.website-clients.company-lookup')
            <form method="GET" action="{{ route('saas.admin.website-clients.index') }}" class="flex flex-wrap items-end gap-2">
                <label class="block min-w-0 flex-auto text-sm font-medium">Company name, public name or account code
                    <input name="q" value="{{ $search }}" maxlength="120" class="tn-website-client-field mt-1 block w-full rounded-lg">
                </label>
                <label class="block min-w-0 flex-auto text-sm font-medium">Product
                    <select name="product" class="tn-website-client-field mt-1 block w-full rounded-lg">
                        <option value="">All products</option>
                        @foreach(\App\Models\WebsiteClient::PRODUCTS as $code => $name)
                            <option value="{{ $code }}" @selected($product === $code)>{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                <button type="submit" class="tn-website-client-action">Search</button>
            </form>
        </div>
        @if($search !== '' || $product !== null)<a href="{{ route('saas.admin.website-clients.index') }}" class="inline-block text-sm underline">Clear filters</a>@endif
        <ul class="tn-website-client-candidates divide-y">
            @forelse($candidates as $candidate)
            <li class="flex flex-wrap items-center justify-between gap-2 py-3">
                <div class="min-w-0 break-words">
                    <p class="font-semibold">{{ $candidate->name }}</p>
                    <p class="text-sm">{{ \App\Models\WebsiteClient::PRODUCTS[$candidate->website_product] }} · Company ID {{ $candidate->product_company_id ?? $candidate->id }}@if($candidate->account_code) · {{ $candidate->account_code }}@endif</p>
                    <p class="text-sm">{{ in_array($candidate->id, $paidCandidateIds, true) ? 'Active paid subscription' : 'No active paid subscription' }}</p>
                </div>
                <a href="{{ route('saas.admin.website-clients.create', ['company' => $candidate->id]) }}" class="tn-website-client-action" aria-label="Review {{ $candidate->name }}">Review candidate</a>
            </li>
            @empty
            <li class="py-3 text-sm">No companies match.</li>
            @endforelse
        </ul>
        {{ $candidates->links() }}
    </section>

    <h2 class="text-lg font-semibold">Saved client cards</h2>
    @forelse($entries as $entry)
    <article class="tn-website-client-panel space-y-3 rounded-xl border p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="break-words font-semibold">{{ $entry->display_name }}</h3>
                <p class="text-sm">
                    {{ \App\Models\WebsiteClient::PRODUCTS[$entry->product] }} &middot;
                    @if(in_array($entry->id, $publicIds, true))
                        Published
                    @elseif(!$entry->company_id)
                        Company link required
                    @elseif(!$entry->published_at)
                        Draft
                    @elseif(in_array($entry->id, $pendingIds, true))
                        Hidden &middot; Payment pending
                    @elseif(in_array($entry->id, $approvedIds, true))
                        Hidden &middot; No active paid subscription
                    @else
                        Hidden &middot; Company or product ineligible
                    @endif
                </p>
            </div>
            <a href="{{ route('saas.admin.website-clients.edit', $entry->id) }}" class="tn-website-client-action">Review / Edit</a>
        </div>
        @if($entry->published_at)
        <form method="POST" action="{{ route('saas.admin.website-clients.visibility', $entry->id) }}">
            @csrf
            <input type="hidden" name="revision" value="{{ $entry->revision }}">
            <button name="action" value="unpublish" class="tn-website-client-action">Unpublish</button>
        </form>
        @endif
    </article>
    @empty
        <p class="tn-website-client-panel rounded-xl border p-4 text-sm">{{ $search !== '' || $product !== null ? 'No saved client cards match.' : 'No clients have been added.' }}</p>
    @endforelse
    {{ $entries->links() }}
</div>
</x-admin-layout>
