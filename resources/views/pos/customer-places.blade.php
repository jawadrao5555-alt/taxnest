<x-pos-layout>
<link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}?v=1">
<script src="{{ asset('vendor/leaflet/leaflet.js') }}?v=1"></script>
<script src="{{ asset('vendor/maplibre/maplibre-gl-csp.js') }}?v=1"></script>
<script src="{{ asset('vendor/maplibre/leaflet-maplibre-gl.js') }}?v=1"></script>
<script src="{{ asset('vendor/maps/nestpos-basemaps.js') }}?v=1"></script>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-5">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.places_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.places_intro') }}</p>
        </div>
        <a href="{{ route('pos.riders.tracking') }}" class="inline-flex items-center justify-center px-3 py-2 rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-200 text-sm font-semibold hover:bg-gray-200 dark:hover:bg-gray-700">
            ← {{ __('pos.rt_title') }}
        </a>
    </div>

    @if(session('success'))
        <div class="mb-4 p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300 text-sm">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 text-sm">
            <ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-6">
        <div class="lg:col-span-2 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-3 shadow-sm">
            <div id="places-map" class="rounded-lg" style="height:430px"></div>
            <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-2">{{ __('pos.places_map_hint') }}</p>
        </div>
        <form method="POST" action="{{ route('pos.riders.tracking.places.store') }}" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-4 shadow-sm">
            @csrf
            <h2 class="font-bold text-gray-900 dark:text-white mb-3">{{ __('pos.place_add_title') }}</h2>
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">{{ __('pos.place_customer_phone') }}</label>
                    <input type="text" name="customer_phone" maxlength="40" autocomplete="off" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">{{ __('pos.place_type') }}</label>
                    <select name="place_type" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm">
                        <option value="home">{{ __('pos.place_type_home') }}</option>
                        <option value="business">{{ __('pos.place_type_business') }}</option>
                        <option value="other">{{ __('pos.place_type_other') }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">{{ __('pos.place_label') }}</label>
                    <input type="text" name="label" maxlength="80" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">{{ __('pos.place_address') }}</label>
                    <textarea name="address" maxlength="500" rows="2" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm"></textarea>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">{{ __('pos.place_lat') }}</label>
                        <input type="number" name="lat" step="0.0000001" min="22.8" max="37.5" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">{{ __('pos.place_lng') }}</label>
                        <input type="number" name="lng" step="0.0000001" min="60.4" max="77.6" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm">
                    </div>
                </div>
                <button class="w-full px-4 py-2 rounded-lg bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold">{{ __('pos.place_add_btn') }}</button>
            </div>
        </form>
    </div>

    <form method="GET" class="flex gap-2 mb-4">
        <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('pos.place_search_ph') }}"
               autocomplete="off" class="flex-1 max-w-md rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm">
        <button class="px-4 py-2 rounded-lg bg-gray-800 dark:bg-gray-700 text-white text-sm font-semibold">{{ __('pos.search') }}</button>
    </form>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        @forelse($places as $place)
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-4 shadow-sm">
                <div class="flex items-start justify-between gap-2 mb-3">
                    <div>
                        <div class="font-bold text-gray-900 dark:text-white">{{ $place->label ?: __('pos.place_type_' . $place->place_type) }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $place->customer?->name ?: ($place->customer_phone ?: __('pos.walk_in')) }}
                        </div>
                    </div>
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $place->is_verified ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300' }}">
                        {{ $place->is_verified ? __('pos.place_verified') : __('pos.place_needs_review') }}
                    </span>
                </div>
                <form method="POST" action="{{ route('pos.riders.tracking.places.update', $place->id) }}" class="space-y-2">
                    @csrf @method('PATCH')
                    <select name="place_type" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs py-1.5">
                        @foreach(['home','business','other'] as $type)
                            <option value="{{ $type }}" @selected($place->place_type === $type)>{{ __('pos.place_type_' . $type) }}</option>
                        @endforeach
                    </select>
                    <input type="text" name="label" value="{{ $place->label }}" maxlength="80" placeholder="{{ __('pos.place_label') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs py-1.5">
                    <textarea name="address" maxlength="500" rows="2" placeholder="{{ __('pos.place_address') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs py-1.5">{{ $place->address }}</textarea>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="number" name="lat" value="{{ $place->lat }}" step="0.0000001" min="22.8" max="37.5" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs py-1.5">
                        <input type="number" name="lng" value="{{ $place->lng }}" step="0.0000001" min="60.4" max="77.6" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs py-1.5">
                    </div>
                    <div class="text-[11px] text-gray-400">{{ __('pos.place_used_count', ['count' => $place->usage_count]) }}@if($place->last_used_at) · {{ $place->last_used_at->format('d M Y h:i A') }}@endif</div>
                    <a href="https://www.google.com/maps/dir/?api=1&destination={{ rawurlencode($place->lat . ',' . $place->lng) }}"
                       target="_blank" rel="noopener"
                       class="inline-flex items-center gap-1 text-xs font-semibold text-blue-600 dark:text-blue-400 hover:underline">
                        ↗ {{ __('pos.rt_open_in_gmaps') }}
                    </a>
                    <button class="w-full px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold">{{ __('pos.place_save_changes') }}</button>
                </form>
                <div class="grid grid-cols-[1fr_auto] gap-2 mt-2">
                    <form method="POST" action="{{ route('pos.riders.tracking.places.merge', $place->id) }}" class="flex gap-1">
                        @csrf
                        <select name="target_id" required class="min-w-0 flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-[11px] py-1">
                            <option value="">{{ __('pos.place_merge_into') }}</option>
                            @foreach($mergeTargets as $target)
                                @php
                                    $sameCustomer = ($place->customer_id && $target->customer_id && (int) $place->customer_id === (int) $target->customer_id)
                                        || (!$place->customer_id && !$target->customer_id && filled($place->customer_phone) && $place->customer_phone === $target->customer_phone);
                                @endphp
                                @if((int) $target->id !== (int) $place->id && $sameCustomer)
                                    <option value="{{ $target->id }}">{{ $target->label ?: __('pos.place_type_' . $target->place_type) }} #{{ $target->id }}</option>
                                @endif
                            @endforeach
                        </select>
                        <button class="px-2 py-1 rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-200 text-[11px] font-semibold">{{ __('pos.place_merge_btn') }}</button>
                    </form>
                    <form method="POST" action="{{ route('pos.riders.tracking.places.destroy', $place->id) }}" onsubmit="return confirm({{ Js::from(__('pos.place_delete_confirm')) }});">
                        @csrf @method('DELETE')
                        <button class="px-2 py-1 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 text-[11px] font-semibold">{{ __('pos.delete') }}</button>
                    </form>
                </div>
            </div>
        @empty
            <div class="md:col-span-2 xl:col-span-3 p-8 text-center rounded-xl border border-dashed border-gray-300 dark:border-gray-700 text-gray-500 dark:text-gray-400">{{ __('pos.places_empty') }}</div>
        @endforelse
    </div>
    <div class="mt-5">{{ $places->links() }}</div>
</div>

<script>
(function () {
    const map = L.map('places-map', {
        maxBounds: [[22.8, 60.4], [37.5, 77.6]],
        maxBoundsViscosity: 1.0,
        minZoom: 5,
        maxZoom: 21
    }).setView([30.3753, 69.3451], 6);
    const streets = NestPosBasemaps.streets({ maxZoom: 21 });
    const satellite = NestPosBasemaps.satellite({ maxZoom: 21 });
    streets.addTo(map);
    const saved = L.layerGroup().addTo(map);
    const arrivals = L.layerGroup();
    L.control.layers(
        { @js(__('pos.rt_layer_streets')): streets, @js(__('pos.rt_layer_satellite')): satellite },
        { @js(__('pos.places_saved_layer')): saved, @js(__('pos.places_arrivals_layer')): arrivals },
        { collapsed: false }
    ).addTo(map);
    const esc = value => String(value || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    fetch(@js(route('pos.riders.tracking.places.data', [], false)), { headers: { Accept: 'application/json' } })
        .then(r => r.ok ? r.json() : null)
        .then(j => {
            if (!j || !j.ok) return;
            const bounds = [];
            (j.places || []).forEach(p => {
                if (!isFinite(p.lat) || !isFinite(p.lng)) return;
                bounds.push([p.lat, p.lng]);
                L.marker([p.lat, p.lng]).addTo(saved).bindPopup(
                    '<b>' + esc(p.label || p.type) + '</b><br>' + esc(p.address)
                    + '<br><a target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&destination='
                    + encodeURIComponent(p.lat + ',' + p.lng) + '">' + esc(@js(__('pos.rt_open_in_gmaps'))) + '</a>'
                );
            });
            (j.arrivals || []).forEach(a => {
                if (!isFinite(a.lat) || !isFinite(a.lng)) return;
                L.circleMarker([a.lat, a.lng], {
                    radius: 5, color: a.verified ? '#10b981' : '#f59e0b',
                    fillColor: a.verified ? '#10b981' : '#f59e0b', fillOpacity: .65
                }).addTo(arrivals).bindPopup(
                    '<b>' + esc(a.label || a.type) + '</b>'
                    + (a.rider ? '<br>' + esc(a.rider) : '')
                    + (a.captured_at ? '<br>' + esc(new Date(a.captured_at).toLocaleString()) : '')
                );
            });
            if (bounds.length) map.fitBounds(bounds, { padding: [30, 30], maxZoom: 16 });
        }).catch(() => {});
})();
</script>
</x-pos-layout>