<x-pos-layout>
{{-- Rider LIVE Tracking (Aug 2026) — Unlimited exclusive.
     Live map (Leaflet + OSM, self-hosted vendor per site-perf convention),
     20s poll off pos_riders denormalized last-known columns, per-rider
     day trail polyline. Locked plans see the upgrade card (admin upsell). --}}

@if($locked)
    <div id="rt-page" class="max-w-xl mx-auto mt-10 px-4">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-8 text-center">
            <div class="text-4xl mb-3">🛵</div>
            <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-2">{{ __('pos.rt_locked_title') }}</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">{{ __('pos.rt_locked_body') }}</p>
            <a href="{{ route('pos.billing') }}" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold">
                {{ __('pos.rt_upgrade_btn') }}
            </a>
        </div>
    </div>
@else
    <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}?v=1">
    <script src="{{ asset('vendor/leaflet/leaflet.js') }}?v=1"></script>
    <style>
        .rt-map { height: calc(100vh - 170px); min-height: 420px; border-radius: 1rem; z-index: 0; }
        .rt-dot { width: 10px; height: 10px; border-radius: 9999px; display: inline-block; }
        @media (max-width: 767px) { .rt-map { height: 52vh; min-height: 320px; } }
        .rt-gap-pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
            box-shadow: 0 1px 4px rgba(0,0,0,.18);
            pointer-events: none;
        }
        .rt-gap-pill.stopped { background: #f59e0b; color: #fff; }
        .rt-gap-pill.offline { background: #6366f1; color: #fff; }
    </style>

    <div class="px-3 sm:px-4 py-3" x-data="riderTracking(@js([
        'dataUrl' => route('pos.riders.tracking.data'),
        'trailUrlBase' => url('/pos/riders/tracking/trail'),
        'companyCity' => $companyCity ?? '',
        'i18n' => [
            'on_duty' => __('pos.rt_on_duty'),
            'off_duty' => __('pos.rt_off_duty'),
            'last_seen' => __('pos.rt_last_seen'),
            'no_location' => __('pos.rt_no_location'),
            'open_deliveries' => __('pos.rt_open_deliveries'),
            'trail' => __('pos.rt_trail'),
            'min_ago' => __('pos.rt_min_ago'),
            'just_now' => __('pos.rt_just_now'),
            'gap_stopped' => __('pos.rt_gap_recording_stopped'),
            'gap_offline' => __('pos.rt_gap_offline_sync'),
        ],
    ]))" x-init="init()">

        <div class="flex items-center justify-between mb-3">
            <div>
                <h1 class="text-base sm:text-lg font-bold text-gray-900 dark:text-white">{{ __('pos.rt_title') }}</h1>
                <p class="text-[11px] text-gray-500 dark:text-gray-400" x-text="statusLine"></p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ url('/downloads/taxnest-rider.apk') }}"
                   class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200">
                    ⬇ {{ __('pos.rt_app_download') }}
                </a>
                <button type="button" @click="load()" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200">
                    ⟳ {{ __('pos.refresh') }}
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            {{-- Rider list --}}
            <div class="md:col-span-1 space-y-2 order-2 md:order-1 max-h-[70vh] overflow-y-auto pr-1">
                <template x-if="!riders.length">
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('pos.rt_no_riders') }}
                    </div>
                </template>
                <template x-for="r in riders" :key="r.id">
                    <button type="button" @click="selectRider(r)"
                        class="w-full text-left bg-white dark:bg-gray-800 rounded-xl border p-3 transition"
                        :class="selected && selected.id === r.id ? 'border-indigo-400 ring-2 ring-indigo-100 dark:ring-indigo-900/40' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300'">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-semibold text-gray-900 dark:text-white truncate" x-text="r.name"></span>
                            <span class="rt-dot" :style="'background:' + dotColor(r)"></span>
                        </div>
                        <div class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">
                            <span x-text="r.on_duty ? i18n.on_duty : i18n.off_duty"></span>
                            <template x-if="r.open_deliveries > 0">
                                <span> · <span x-text="r.open_deliveries"></span> {{ __('pos.rt_bills_short') }}</span>
                            </template>
                        </div>
                        <div class="text-[11px] text-gray-400 dark:text-gray-500" x-text="agoText(r)"></div>
                    </button>
                </template>
            </div>

            {{-- Map --}}
            <div class="md:col-span-3 order-1 md:order-2">
                {{-- Place search (Nominatim, PK-only) — owner request Aug 2026 --}}
                <div class="relative mb-2">
                    <div class="flex gap-2">
                        <input type="text" x-model="searchQ" @keydown.enter.prevent="searchPlace()"
                               autocomplete="off" name="rt_search_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                               placeholder="{{ __('pos.rt_search_placeholder') }}"
                               class="flex-1 px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm text-gray-900 dark:text-white focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400 outline-none">
                        <button type="button" @click="searchPlace()"
                                class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold">
                            <span x-show="!searchBusy">🔍</span><span x-show="searchBusy" x-cloak>…</span>
                        </button>
                    </div>
                    <div x-show="searchResults.length > 0 || searchDone" x-cloak
                         @click.outside="searchResults = []; searchDone = false"
                         class="absolute left-0 right-0 top-full mt-1 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-lg overflow-hidden"
                         style="z-index:1200;">
                        <template x-for="(res, i) in searchResults" :key="i">
                            <button type="button" @click="gotoResult(res)"
                                    class="w-full text-left px-3 py-2 text-xs text-gray-700 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-gray-700 border-b border-gray-100 dark:border-gray-700"
                                    x-text="res.display_name"></button>
                        </template>
                        <template x-if="searchDone && !searchResults.length">
                            <div class="px-3 py-2 text-xs text-gray-400 dark:text-gray-500">{{ __('pos.rt_search_no_results') }}</div>
                        </template>
                    </div>
                </div>
                <div id="rt-map" class="rt-map shadow-sm border border-gray-200 dark:border-gray-700"></div>
                <p class="mt-1.5 text-[10px] text-gray-400 dark:text-gray-500" x-show="selected" x-cloak>
                    <span x-text="i18n.trail"></span>: <span x-text="selected ? selected.name : ''"></span>
                    <button type="button" class="underline ml-1" @click="clearTrail()">{{ __('pos.rt_clear_trail') }}</button>
                </p>
            </div>
        </div>
    </div>

    <script>
    function riderTracking(cfg) {
        return {
            dataUrl: cfg.dataUrl,
            trailUrlBase: cfg.trailUrlBase,
            i18n: cfg.i18n,
            riders: [],
            selected: null,
            statusLine: '',
            map: null,
            markers: {},
            polyline: null,
            gapLayers: [],
            timer: null,
            didFit: false,
            searchQ: '',
            searchResults: [],
            searchBusy: false,
            searchDone: false,
            userCentered: false,
            cityCentered: false,
            init() {
                // PAKISTAN-LOCKED map (owner, Aug 2026: "Pakistan ke map ko focus
                // kiya jaye" — map kabhi India/duniya par bhatak na sake):
                // maxBounds = Pakistan ka box, viscosity 1 = border par sakht rok,
                // minZoom 5 = itna zoom-out hi ho sake ke Pakistan poora dikhe.
                const pkBounds = L.latLngBounds([22.8, 60.4], [37.5, 77.6]);
                this.pkBounds = pkBounds;
                this.map = L.map('rt-map', {
                    zoomControl: true,
                    maxBounds: pkBounds,
                    maxBoundsViscosity: 1.0,
                    minZoom: 5,
                }).setView([31.5204, 74.3587], 12);
                // Carto Voyager basemap — English/Latin place labels (owner rule Aug 2026:
                // OSM default tiles label Pakistani cities in Urdu script; owner wants English).
                L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
                    maxZoom: 19,
                    subdomains: 'abcd',
                    attribution: '© OpenStreetMap © CARTO'
                }).addTo(this.map);
                this.load();
                this.cityCenter();
                this.ipCenter();
                this.timer = setInterval(() => this.load(), 20000);
                document.addEventListener('visibilitychange', () => {
                    if (!document.hidden) this.load();
                });
            },
            load() {
                fetch(this.dataUrl, { headers: { 'Accept': 'application/json' } })
                    .then(r => r.ok ? r.json() : null)
                    .then(j => {
                        if (!j || !j.ok) return;
                        this.riders = j.riders || [];
                        this.renderMarkers();
                        const onDuty = this.riders.filter(r => r.on_duty).length;
                        this.statusLine = onDuty + ' / ' + this.riders.length + ' ' + this.i18n.on_duty.toLowerCase();
                    })
                    .catch(() => {});
            },
            dotColor(r) {
                if (!r.on_duty) return '#9ca3af';
                if (r.seconds_ago === null || r.seconds_ago > 180) return '#f59e0b';
                return '#10b981';
            },
            agoText(r) {
                if (!r.located_at) return this.i18n.no_location;
                const s = r.seconds_ago;
                if (s === null) return this.i18n.no_location;
                if (s < 60) return this.i18n.last_seen + ': ' + this.i18n.just_now;
                return this.i18n.last_seen + ': ' + Math.floor(s / 60) + ' ' + this.i18n.min_ago;
            },
            renderMarkers() {
                const bounds = [];
                this.riders.forEach(r => {
                    if (r.lat === null || r.lng === null) return;
                    bounds.push([r.lat, r.lng]);
                    const color = this.dotColor(r);
                    const popup = '<b>' + this.esc(r.name) + '</b><br>'
                        + (r.on_duty ? this.i18n.on_duty : this.i18n.off_duty)
                        + '<br>' + this.esc(this.agoText(r))
                        + (r.open_deliveries ? '<br>' + this.i18n.open_deliveries + ': ' + r.open_deliveries : '');
                    if (this.markers[r.id]) {
                        this.markers[r.id].setLatLng([r.lat, r.lng]);
                        this.markers[r.id].setStyle({ color: color, fillColor: color });
                        this.markers[r.id].getPopup().setContent(popup);
                    } else {
                        this.markers[r.id] = L.circleMarker([r.lat, r.lng], {
                            radius: 9, weight: 2.5, color: color, fillColor: color, fillOpacity: 0.55
                        }).addTo(this.map).bindPopup(popup);
                    }
                });
                if (!this.didFit && bounds.length) {
                    this.didFit = true;
                    this.map.fitBounds(bounds, { padding: [40, 40], maxZoom: 15 });
                }
            },
            selectRider(r) {
                this.selected = r;
                if (r.lat !== null && r.lng !== null) {
                    this.map.setView([r.lat, r.lng], Math.max(this.map.getZoom(), 14));
                    if (this.markers[r.id]) this.markers[r.id].openPopup();
                }
                fetch(this.trailUrlBase + '/' + r.id, { headers: { 'Accept': 'application/json' } })
                    .then(resp => resp.ok ? resp.json() : null)
                    .then(j => {
                        if (!j || !j.ok) return;
                        this.clearTrailLayers();
                        const pts = j.points || [];
                        const gaps = j.gaps || [];
                        if (!pts.length) return;

                        // Build gap index: after_idx → gap meta
                        const gapByIdx = {};
                        gaps.forEach(g => { gapByIdx[g.after_idx] = g; });

                        // Split pts into solid segments separated by gaps.
                        // A gap at after_idx means: segment ends at pts[after_idx],
                        // next segment starts at pts[after_idx+1].
                        const segmentBreaks = new Set(gaps.map(g => g.after_idx));
                        const segments = [];
                        let seg = [];
                        pts.forEach((p, i) => {
                            seg.push([p[0], p[1]]);
                            if (segmentBreaks.has(i)) {
                                // Segment ends at pts[i] (inclusive).
                                // Next segment starts fresh at pts[i+1] — do NOT
                                // seed it with p, or the dashed connector becomes
                                // zero-length (point-to-itself) and the solid line
                                // across the gap is drawn by the next segment.
                                segments.push({ pts: seg, gapAfter: gapByIdx[i] });
                                seg = [];
                            }
                        });
                        if (seg.length) segments.push({ pts: seg, gapAfter: null });

                        const allBounds = [];
                        const solidStyle = { color: '#4f46e5', weight: 4, opacity: 0.75 };

                        segments.forEach((s, si) => {
                            if (s.pts.length >= 2) {
                                const pl = L.polyline(s.pts, solidStyle).addTo(this.map);
                                this.gapLayers.push(pl);
                                s.pts.forEach(p => allBounds.push(p));
                            } else if (s.pts.length === 1) {
                                allBounds.push(s.pts[0]);
                            }

                            // If there's a gap after this segment, draw a dashed connector.
                            if (s.gapAfter && si + 1 < segments.length) {
                                const fromPt = s.pts[s.pts.length - 1];
                                const toPt   = segments[si + 1].pts[0];
                                const isOffline = s.gapAfter.is_offline_after;
                                const gapColor = isOffline ? '#6366f1' : '#f59e0b';

                                const dash = L.polyline([fromPt, toPt], {
                                    color: gapColor, weight: 2.5,
                                    dashArray: '7 11', opacity: 0.85
                                }).addTo(this.map);
                                this.gapLayers.push(dash);

                                // divIcon pill label at midpoint of the dashed line.
                                const midLat = (fromPt[0] + toPt[0]) / 2;
                                const midLng = (fromPt[1] + toPt[1]) / 2;
                                const mins = s.gapAfter.minutes;
                                const tpl = isOffline ? this.i18n.gap_offline : this.i18n.gap_stopped;
                                const label = tpl.replace(':min', mins);
                                const cls   = isOffline ? 'offline' : 'stopped';
                                const icon  = L.divIcon({
                                    className: '',
                                    html: '<span class="rt-gap-pill ' + cls + '">' + this.esc(label) + '</span>',
                                    iconAnchor: [0, 0],
                                });
                                const marker = L.marker([midLat, midLng], { icon: icon, interactive: false }).addTo(this.map);
                                this.gapLayers.push(marker);
                            }
                        });

                        if (allBounds.length >= 2) {
                            this.map.fitBounds(allBounds, { padding: [40, 40] });
                        } else if (allBounds.length === 1) {
                            this.map.setView(allBounds[0], 15);
                        }
                    })
                    .catch(() => {});
            },
            clearTrailLayers() {
                if (this.polyline) { this.polyline.remove(); this.polyline = null; }
                this.gapLayers.forEach(l => { try { l.remove(); } catch(e) {} });
                this.gapLayers = [];
            },
            clearTrail() {
                this.selected = null;
                this.clearTrailLayers();
            },
            // BEST centering (owner, Aug 2026): company profile ki APNI city par
            // khulo — IP-guess se zyada bharosemand. Nominatim se aik dafa
            // geocode (PK-only), phir localStorage cache (roz-roz lookup nahi).
            // Riders' fitBounds (didFit) aur manual search hamesha jeet-te hain.
            cityCenter() {
                const city = String(cfg.companyCity || '').trim();
                if (!city) return;
                const key = 'rt_city_ll:' + city.toLowerCase();
                try {
                    const c = JSON.parse(localStorage.getItem(key) || 'null');
                    if (c && isFinite(c.lat) && isFinite(c.lng)) { this.applyCityCenter(c.lat, c.lng); return; }
                } catch (e) { /* corrupt cache — fresh lookup */ }
                const url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&countrycodes=pk&accept-language=en&q=' + encodeURIComponent(city);
                fetch(url, { headers: { 'Accept': 'application/json' } })
                    .then(r => r.ok ? r.json() : [])
                    .then(list => {
                        const res = Array.isArray(list) && list.length ? list[0] : null;
                        if (!res) return;
                        const lat = parseFloat(res.lat), lng = parseFloat(res.lon);
                        if (!isFinite(lat) || !isFinite(lng)) return;
                        try { localStorage.setItem(key, JSON.stringify({ lat, lng })); } catch (e) {}
                        this.applyCityCenter(lat, lng);
                    })
                    .catch(() => {});
            },
            applyCityCenter(lat, lng) {
                // Kharab cache / Nominatim anomaly guard: Pakistan se bahar ka
                // point kabhi center na bane (clamped-weird view se bachao).
                if (!this.pkBounds || !this.pkBounds.contains([lat, lng])) return;
                this.cityCentered = true; // late ipCenter() must not override
                if (this.didFit || this.userCentered) return;
                this.map.setView([lat, lng], 13);
            },
            // Fallback: shop ki city ka IP-lookup (owner rule Aug 2026: a Lodhran
            // shop must not open on Lahore). Riders' fitBounds (didFit) always wins.
            ipCenter() {
                fetch('https://ipwho.is/', { headers: { 'Accept': 'application/json' } })
                    .then(r => r.ok ? r.json() : null)
                    .then(j => {
                        if (!j || !j.success || this.didFit || this.userCentered || this.cityCentered) return;
                        // Owner report (Aug 2026): kuch ISP IPs Pakistan se BAHAR geolocate
                        // hote hain (map India par khula). Sirf Pakistan wala result maano;
                        // warna default (Lahore) par hi raho.
                        if (String(j.country_code || '').toUpperCase() !== 'PK') return;
                        const lat = parseFloat(j.latitude), lng = parseFloat(j.longitude);
                        if (!isFinite(lat) || !isFinite(lng)) return;
                        this.map.setView([lat, lng], 13);
                    })
                    .catch(() => {});
            },
            searchPlace() {
                const q = (this.searchQ || '').trim();
                if (!q || this.searchBusy) return;
                this.searchBusy = true;
                this.searchDone = false;
                const url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=5&countrycodes=pk&accept-language=en&q=' + encodeURIComponent(q);
                fetch(url, { headers: { 'Accept': 'application/json' } })
                    .then(r => r.ok ? r.json() : [])
                    .then(list => {
                        this.searchResults = Array.isArray(list) ? list : [];
                        this.searchDone = true;
                        this.searchBusy = false;
                    })
                    .catch(() => { this.searchResults = []; this.searchDone = true; this.searchBusy = false; });
            },
            gotoResult(res) {
                const lat = parseFloat(res.lat), lng = parseFloat(res.lon);
                this.searchResults = [];
                this.searchDone = false;
                if (!isFinite(lat) || !isFinite(lng)) return;
                this.userCentered = true; // late ipCenter() must not override a manual search
                this.map.setView([lat, lng], 14);
            },
            esc(s) {
                return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            }
        };
    }
    </script>
@endif
</x-pos-layout>
