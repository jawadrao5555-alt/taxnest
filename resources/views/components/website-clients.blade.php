@props(['product' => null])

@php
    $clients = \App\Models\WebsiteClient::publicCards($product);
@endphp

@if($clients->isNotEmpty())
<section class="border-t border-gray-200 bg-white py-14 sm:py-16" aria-labelledby="our-clients-heading">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <h2 id="our-clients-heading" class="text-center text-3xl font-semibold text-[#052730]">Our Clients</h2>
        <ul class="mt-7 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            @foreach($clients as $client)
            <li class="flex min-w-0 flex-col items-center justify-center rounded-xl border border-gray-200 bg-white px-3 py-5 text-center">
                @if(\App\Services\WebsiteClientLogo::hasPublicLogo($client))
                <img class="mb-3 h-12 w-full max-w-28 object-contain" loading="lazy" width="112" height="48"
                    src="{{ route('website-clients.logo', [$client->id, $client->revision]) }}" alt="">
                @endif
                <p class="max-w-full break-words text-sm font-semibold text-[#052730]">{{ $client->display_name }}</p>
                <p class="mt-1 text-xs text-gray-600">{{ \App\Models\WebsiteClient::PRODUCTS[$client->product] }}</p>
            </li>
            @endforeach
        </ul>
    </div>
</section>
@endif
