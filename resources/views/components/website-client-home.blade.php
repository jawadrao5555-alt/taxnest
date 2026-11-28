@php
    $clients = \App\Models\WebsiteClient::publicCards();
    $logos = $clients->filter(fn ($client) => \App\Services\WebsiteClientLogo::hasPublicLogo($client))
        ->mapWithKeys(fn ($client) => [$client->id => route('website-clients.logo', [$client->id, $client->revision])]);
    $moving = $clients->count() > 1;
    $repetitions = $moving ? (int) ceil(4 / $clients->count()) : 1;
@endphp

@if($clients->isNotEmpty())
    <section class="public-width home-clients" aria-labelledby="our-clients-heading" data-home-clients>
        <h2 id="our-clients-heading">
            <a href="{{ route('website-clients.index') }}" data-client-directory-link>
                Our Clients <span aria-hidden="true">→</span>
            </a>
        </h2>
    </section>

    <aside class="client-reel" aria-label="Client reel" data-client-reel data-moving="{{ $moving ? 'true' : 'false' }}"
        style="--reel-duration: {{ 4 * $repetitions * $clients->count() }}s" hidden>
        <div class="client-reel__bar">
            <a href="{{ route('website-clients.index') }}">Our Clients <span aria-hidden="true">→</span></a>
            <div class="client-reel__actions">
                @if($moving)
                    <button type="button" data-client-reel-pause>Pause</button>
                @endif
                <button type="button" data-client-reel-close aria-label="Close client reel"><span aria-hidden="true">×</span></button>
            </div>
        </div>
        <div class="client-reel__viewport">
            <div class="client-reel__track" data-client-reel-track>
                @for($group = 0; $group < ($moving ? 2 : 1); $group++)
                    <ul class="client-reel__group{{ $group ? ' client-reel__group--copy' : '' }}" @if($group) aria-hidden="true" @endif>
                        @for($repeat = 0; $repeat < $repetitions; $repeat++)
                            @foreach($clients as $client)
                                <li class="client-reel__card{{ $repeat ? ' client-reel__card--repeat' : '' }}" @if($repeat) aria-hidden="true" @endif>
                                    @if(isset($logos[$client->id]))
                                        <img src="{{ $logos[$client->id] }}" width="44" height="36" alt="">
                                    @else
                                        <span class="client-reel__monogram" aria-hidden="true">{{ mb_strtoupper(mb_substr($client->display_name, 0, 1)) }}</span>
                                    @endif
                                    <span class="client-reel__name" title="{{ $client->display_name }}">{{ $client->display_name }}</span>
                                </li>
                            @endforeach
                        @endfor
                    </ul>
                @endfor
            </div>
        </div>
    </aside>
    <script src="{{ asset('js/client-reel.js') }}" defer></script>
@endif
