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
    {{-- Vector basemap stack — see public/vendor/maps/nestpos-basemaps.js for why
         the delivery maps no longer use raster tiles (English label rule). --}}
    <script src="{{ asset('vendor/maplibre/maplibre-gl-csp.js') }}?v=1"></script>
    <script src="{{ asset('vendor/maplibre/leaflet-maplibre-gl.js') }}?v=1"></script>
    <script src="{{ asset('vendor/maps/nestpos-basemaps.js') }}?v=2"></script>
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
        /* Task #1102: rider warning badges (map + list) */
        .rt-warn-pill {
            display: inline-block;
            padding: 1px 7px;
            border-radius: 9999px;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
            box-shadow: 0 1px 4px rgba(0,0,0,.18);
            /* Urdu/Arabic script is strong-RTL via Unicode Bidi; plaintext
               lets each label self-direct so "GPS/net off" stays LTR and
               "جی پی ایس/نیٹ بند" stays RTL inside the same pill class. */
            unicode-bidi: plaintext;
        }
        .rt-warn-pill.idle { background: #f59e0b; color: #fff; }
        .rt-warn-pill.silent { background: #ef4444; color: #fff; }
        /* Task #1106: battery kam hai */
        .rt-warn-pill.battery { background: #dc2626; color: #fff; }
        /* Task #1357: server ne upload reject kiya */
        .rt-warn-pill.reject { background: #4f46e5; color: #fff; }
        /* Task #1405: purana app build / app kabhi khola hi nahi */
        .rt-warn-pill.oldapp { background: #b45309; color: #fff; }
        .rt-warn-pill.noapp { background: #7c3aed; color: #fff; }
        /* .rt-warn-map is the Leaflet divIcon container.  Leaflet defaults it to
           12×12 px, so a transform here shifts by only 6px — not by the pill's
           actual width.  The centering transform is applied INLINE on the pill
           span itself inside renderMarkers() so translate(-50%) uses the pill's
           own rendered width, working correctly for any language. */
        .rt-warn-map { pointer-events: none; }
    </style>

    <div class="px-3 sm:px-4 py-3" x-data="riderTracking(@js([
        {{-- Relative URLs (route(..., false)) — absolute route() URLs drop the
             :5000 port behind the dev proxy → cross-origin fetch = CORS death.
             Relative = same-origin everywhere (dev + live). --}}
        'dataUrl' => route('pos.riders.tracking.data', [], false),
        'placesDataUrl' => route('pos.riders.tracking.places.data', [], false),
        'trailUrlBase' => '/pos/riders/tracking/trail',
        'companyCity' => $companyCity ?? '',
        'shopLat' => $shopLat,
        'shopLng' => $shopLng,
        'shopSaveUrl' => route('pos.riders.tracking.shop', [], false),
        'resolveLinkUrl' => route('pos.riders.tracking.resolve', [], false),
        'i18n' => [
            'shop_label' => __('pos.rt_shop_label'),
            'shop_hint' => __('pos.rt_shop_hint'),
            'shop_saved' => __('pos.rt_shop_saved'),
            'shop_error' => __('pos.rt_shop_error'),
            'on_duty' => __('pos.rt_on_duty'),
            'off_duty' => __('pos.rt_off_duty'),
            'last_seen' => __('pos.rt_last_seen'),
            'no_location' => __('pos.rt_no_location'),
            'open_deliveries' => __('pos.rt_open_deliveries'),
            'days_short' => __('pos.rt_days_short'),
            'trail' => __('pos.rt_trail'),
            'min_ago' => __('pos.rt_min_ago'),
            'just_now' => __('pos.rt_just_now'),
            'gap_stopped' => __('pos.rt_gap_recording_stopped'),
            'gap_offline' => __('pos.rt_gap_offline_sync'),
            'pin_dropped' => __('pos.rt_pin_dropped'),
            {{-- Task #1102: warnings, playback, auto-off --}}
            'idle_badge' => __('pos.rt_idle_badge'),
            'silent_badge' => __('pos.rt_silent_badge'),
            'auto_off_note' => __('pos.rt_auto_off_note'),
            'play' => __('pos.rt_play'),
            'pause' => __('pos.rt_pause'),
            'stopped_here' => __('pos.rt_stopped_here'),
            'speed_legend' => __('pos.rt_speed_legend'),
            {{-- Task #1106: battery kam hai indicator --}}
            'battery_low_badge' => __('pos.rt_battery_low_badge'),
            'battery_label' => __('pos.rt_battery_label'),
            {{-- Task #1357: satellite/galiyan, Google link, late-sync --}}
            'layer_streets' => __('pos.rt_layer_streets'),
            'layer_satellite' => __('pos.rt_layer_satellite'),
            'layer_known_places' => __('pos.places_saved_layer'),
            'layer_arrivals' => __('pos.places_arrivals_layer'),
            'learned_approach' => __('pos.places_learned_approach'),
            'open_in_gmaps' => __('pos.rt_open_in_gmaps'),
            'gap_offline_at' => __('pos.rt_gap_offline_sync_at'),
            'late_legend' => __('pos.rt_late_legend'),
            'late_point' => __('pos.rt_late_point'),
            'last_upload' => __('pos.rt_last_upload'),
            'upload_lag' => __('pos.rt_upload_lag'),
            'reject_duty_off' => __('pos.rt_reject_duty_off'),
            'reject_plan_locked' => __('pos.rt_reject_plan_locked'),
            'reject_too_old' => __('pos.rt_reject_too_old'),
            'reject_other' => __('pos.rt_reject_other'),
            {{-- Task #1405: purane app par baithe riders --}}
            'app_label' => __('pos.rt_app_label'),
            'app_old_badge' => __('pos.rt_app_old_badge'),
            'app_never_badge' => __('pos.rt_app_never_badge'),
            'app_update_to' => __('pos.rt_app_update_to'),
        ],
    ]))" x-init="init()">

        <div class="flex items-center justify-between mb-3">
            <div>
                <h1 class="text-base sm:text-lg font-bold text-gray-900 dark:text-white">{{ __('pos.rt_title') }}</h1>
                <p class="text-[11px] text-gray-500 dark:text-gray-400" x-text="statusLine"></p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('pos.riders.tracking.places') }}"
                   class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200">
                    📍 {{ __('pos.places_manage_link') }}
                </a>
                {{-- Task #1103: rider performance report link --}}
                <a href="{{ route('pos.riders.report') }}"
                   class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200">
                    📊 {{ __('pos.nav_rider_report') }}
                </a>
                <a href="{{ url('/downloads/taxnest-rider.apk') }}"
                   class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200">
                    ⬇ {{ __('pos.rt_app_download') }}
                </a>
                <button type="button" @click="load()" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200">
                    ⟳ {{ __('pos.refresh') }}
                </button>
                {{-- Task #320 (ZFC): dukan ki location pin-on-map --}}
                <button type="button" @click="toggleSetShop()"
                        class="px-3 py-1.5 rounded-lg text-xs font-semibold"
                        :class="settingShop ? 'bg-amber-500 text-white' : 'bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200'">
                    🏪 {{ __('pos.rt_set_shop') }}
                </button>
            </div>
        </div>

        {{-- Shop pin mode: hint + Save/Cancel bar --}}
        <div x-show="settingShop" x-cloak class="mb-3 flex flex-wrap items-center gap-2 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-xl px-3 py-2">
            <span class="text-xs text-amber-800 dark:text-amber-200 flex-1 min-w-[180px]">{{ __('pos.rt_shop_hint') }}</span>
            <button type="button" @click="saveShop()" :disabled="!pendingShop || shopBusy"
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-emerald-600 hover:bg-emerald-700 text-white disabled:opacity-40">
                {{ __('pos.save') }}
            </button>
            <button type="button" @click="cancelSetShop()"
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200">
                {{ __('pos.cancel') }}
            </button>
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
                                <span :class="r.oldest_open_days >= 1 ? 'text-red-600 dark:text-red-400 font-bold' : ''"> · <span x-text="r.open_deliveries"></span> {{ __('pos.rt_bills_short') }}<span x-show="r.oldest_open_days >= 1" x-text="' · ' + r.oldest_open_days + ' ' + i18n.days_short"></span></span>
                            </template>
                        </div>
                        {{-- Task #1102: warning badges + auto-off note --}}
                        <template x-if="r.is_silent">
                            <div class="mt-1"><span class="rt-warn-pill silent" x-text="i18n.silent_badge"></span></div>
                        </template>
                        <template x-if="r.is_idle">
                            <div class="mt-1"><span class="rt-warn-pill idle" x-text="i18n.idle_badge"></span></div>
                        </template>
                        {{-- Task #1106: battery — % chip always (when reported), red low-battery pill on ≤20% while on duty --}}
                        <template x-if="r.low_battery">
                            <div class="mt-1"><span class="rt-warn-pill battery" x-text="'🪫 ' + i18n.battery_low_badge + ' (' + r.battery_pct + '%)'"></span></div>
                        </template>
                        <template x-if="!r.low_battery && r.battery_pct !== null && r.on_duty">
                            <div class="mt-1 text-[10px] text-gray-400 dark:text-gray-500" x-text="'🔋 ' + i18n.battery_label + ' ' + r.battery_pct + '%'"></div>
                        </template>
                        <template x-if="r.auto_off">
                            <div class="mt-1 text-[10px] text-gray-400 dark:text-gray-500" x-text="i18n.auto_off_note"></div>
                        </template>
                        {{-- Task #1357: server ne is rider ka upload reject kiya tha --}}
                        <template x-if="r.reject_reason">
                            <div class="mt-1"><span class="rt-warn-pill reject" x-text="rejectText(r)"></span></div>
                        </template>
                        {{-- Task #1405: app ka build — purana hai ya kabhi khola hi nahi --}}
                        <template x-if="r.app_never">
                            <div class="mt-1"><span class="rt-warn-pill noapp" x-text="'📵 ' + i18n.app_never_badge"></span></div>
                        </template>
                        <template x-if="r.app_outdated">
                            <div class="mt-1"><span class="rt-warn-pill oldapp" x-text="appOldText(r)"></span></div>
                        </template>
                        <template x-if="r.app_version && !r.app_outdated">
                            <div class="mt-1 text-[10px] text-gray-400 dark:text-gray-500" x-text="'📱 ' + i18n.app_label + ' v' + r.app_version"></div>
                        </template>
                        <div class="text-[11px] text-gray-400 dark:text-gray-500" x-text="agoText(r)"></div>
                        {{-- Task #1357: location ka waqt aur upload ka waqt alag hon to dono dikhao --}}
                        <template x-if="uploadLate(r)">
                            <div class="text-[10px] font-semibold text-indigo-600 dark:text-indigo-400" x-text="uploadText(r)"></div>
                        </template>
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
                            <div class="px-3 py-2">
                                <div class="text-xs text-gray-400 dark:text-gray-500">{{ __('pos.rt_search_no_results') }}</div>
                                <div class="mt-1 text-[11px] text-amber-700 dark:text-amber-300">{{ __('pos.rt_search_drag_hint') }}</div>
                            </div>
                        </template>
                    </div>
                </div>
                <div id="rt-map" class="rt-map shadow-sm border border-gray-200 dark:border-gray-700"></div>
                <p class="mt-1.5 text-[10px] text-gray-400 dark:text-gray-500" x-show="selected" x-cloak>
                    <span x-text="i18n.trail"></span>: <span x-text="selected ? selected.name : ''"></span>
                    <button type="button" class="underline ml-1" @click="clearTrail()">{{ __('pos.rt_clear_trail') }}</button>
                    {{-- Task #1102: trail playback + speed legend --}}
                    <template x-if="trailPts.length >= 2">
                        <span>
                            <button type="button" class="ml-2 px-2 py-0.5 rounded-md text-[10px] font-bold text-white"
                                    :class="playing ? 'bg-gray-500 hover:bg-gray-600' : 'bg-indigo-600 hover:bg-indigo-700'"
                                    @click="togglePlay()">
                                <span x-text="playing ? '⏸ ' + i18n.pause : '▶ ' + i18n.play"></span>
                            </button>
                            <span class="ml-1.5 font-semibold text-gray-600 dark:text-gray-300" x-show="playReadout" x-text="playReadout"></span>
                            <span class="ml-1.5" x-text="i18n.speed_legend"></span>
                            {{-- Task #1357: jo hissa live nahi tha, legend usay saaf kehti hai --}}
                            <template x-if="lateCount > 0">
                                <span class="ml-1.5 font-semibold" style="color:#6366f1" x-text="lateLegendText()"></span>
                            </template>
                        </span>
                    </template>
                </p>
                <p class="mt-1 text-[10px] font-semibold text-amber-700 dark:text-amber-300"
                   x-show="approachCount > 0" x-cloak>
                    <span style="display:inline-block;width:22px;border-top:2px dashed #d97706;vertical-align:middle;margin-right:4px"></span>
                    <span x-text="i18n.learned_approach"></span>
                </p>
            </div>
        </div>
    </div>

    <script>
    function riderTracking(cfg) {
        return {
            dataUrl: cfg.dataUrl,
            placesDataUrl: cfg.placesDataUrl,
            trailUrlBase: cfg.trailUrlBase,
            i18n: cfg.i18n,
            riders: [],
            selected: null,
            statusLine: '',
            map: null,
            markers: {},
            placeLayer: null,
            arrivalLayer: null,
            approachCount: 0,
            warnBadges: {},   // Task #1102: rider-id → warning pill marker
            polyline: null,
            gapLayers: [],
            // Task #1102: trail playback state
            trailPts: [],
            // Task #1357: loaded trail ka kitna hissa late (offline buffer se) aaya
            lateCount: 0,
            lateLastSync: '',
            playing: false,
            playIdx: 0,
            playTimer: null,
            playMarker: null,
            playReadout: '',
            timer: null,
            didFit: false,
            searchQ: '',
            searchResults: [],
            searchBusy: false,
            searchDone: false,
            userCentered: false,
            cityCentered: false,
            // Task #320: dukan ki location (ZFC)
            shopLat: (cfg.shopLat !== null && isFinite(cfg.shopLat)) ? cfg.shopLat : null,
            shopLng: (cfg.shopLng !== null && isFinite(cfg.shopLng)) ? cfg.shopLng : null,
            shopMarker: null,
            settingShop: false,
            pendingShop: null,
            shopBusy: false,
            init() {
                // PAKISTAN-LOCKED map (owner, Aug 2026: "Pakistan ke map ko focus
                // kiya jaye" — map kabhi India/duniya par bhatak na sake):
                // maxBounds = Pakistan ka box, viscosity 1 = border par sakht rok,
                // minZoom 5 = itna zoom-out hi ho sake ke Pakistan poora dikhe.
                const pkBounds = L.latLngBounds([22.8, 60.4], [37.5, 77.6]);
                this.pkBounds = pkBounds;
                // Defensive: if a stray double Alpine boot already bound Leaflet to
                // this container, reclaim it — otherwise L.map throws
                // "Map container is already initialized" and the map dies.
                const mapEl = document.getElementById('rt-map');
                if (mapEl && mapEl._leaflet_id) { mapEl._leaflet_id = null; mapEl.innerHTML = ''; }
                this.map = L.map('rt-map', {
                    zoomControl: true,
                    // Let the page consume a normal mouse-wheel scroll. Deliberate
                    // map zoom remains available through the controls, double-click,
                    // and touch pinch.
                    scrollWheelZoom: false,
                    maxBounds: pkBounds,
                    maxBoundsViscosity: 1.0,
                    minZoom: 5,
                    // Task #1357: over-zoom past the providers' native zoom (tiles
                    // get scaled) so a trail inside a 3-metre gali stays readable.
                    maxZoom: 21,
                }).setView([31.5204, 74.3587], 12);
                // Both basemaps come from the shared helper so this map and the
                // customer's tracking link can never drift apart. Streets =
                // OpenFreeMap vector tiles with every label forced to
                // name:en -> name:latin -> name (owner rule Aug 2026: raster tiles
                // print Pakistani roads in Urdu script). Satellite = Esri World
                // Imagery (free, no API key — free street data has no lanes for
                // small abadis like "Doctor Amir Ali Gali") with the SAME labels
                // painted on top, so switching layers never switches language.
                const streetsLayer = NestPosBasemaps.streets({ maxZoom: 21 });
                const satelliteLayer = NestPosBasemaps.satellite({ maxZoom: 21 });
                streetsLayer.rtKey = 'streets';
                satelliteLayer.rtKey = 'sat';
                // Chuni hui layer browser mein yaad rehti hai. Satellite tiles
                // sirf usi waqt load hote hain jab woh layer map par ho — baaki
                // sab ke liye page ki speed pehle jaisi hi rehti hai.
                let savedLayer = 'streets';
                try { savedLayer = localStorage.getItem('rt_basemap') || 'streets'; } catch (e) {}
                (savedLayer === 'sat' ? satelliteLayer : streetsLayer).addTo(this.map);
                const layerOptions = {};
                layerOptions[this.i18n.layer_streets] = streetsLayer;
                layerOptions[this.i18n.layer_satellite] = satelliteLayer;
                this.placeLayer = L.layerGroup().addTo(this.map);
                this.arrivalLayer = L.layerGroup();
                const overlays = {};
                overlays[this.i18n.layer_known_places] = this.placeLayer;
                overlays[this.i18n.layer_arrivals] = this.arrivalLayer;
                // collapsed:false — dukandar ko dono naam saaf nazar aayein (collapsed
                // control sirf ek chhota sa icon dikhata hai).
                L.control.layers(layerOptions, overlays, { position: 'topright', collapsed: false }).addTo(this.map);
                this.map.on('baselayerchange', (e) => {
                    const key = (e.layer && e.layer.rtKey) === 'sat' ? 'sat' : 'streets';
                    try { localStorage.setItem('rt_basemap', key); } catch (err) {}
                });
                // Task #320: pin-mode click — dukan ki jagah chunna
                this.map.on('click', (e) => {
                    if (!this.settingShop) return;
                    this.pendingShop = { lat: e.latlng.lat, lng: e.latlng.lng };
                    this.renderShopMarker(this.pendingShop.lat, this.pendingShop.lng, true);
                });
                this.load();
                this.loadPlaces();
                // Dukan set ho to map SIDHA dukan par khule (ZFC request) —
                // city/IP centering ki zaroorat hi nahi.
                if (this.shopLat !== null && this.shopLng !== null) {
                    this.renderShopMarker(this.shopLat, this.shopLng, false);
                    this.cityCentered = true; // late ipCenter() must not override
                    this.map.setView([this.shopLat, this.shopLng], 14);
                } else {
                    this.cityCenter();
                    this.ipCenter();
                }
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
            loadPlaces() {
                if (!this.placesDataUrl || !this.placeLayer || !this.arrivalLayer) return;
                fetch(this.placesDataUrl, { headers: { 'Accept': 'application/json' } })
                    .then(r => r.ok ? r.json() : null)
                    .then(j => {
                        if (!j || !j.ok) return;
                        this.placeLayer.clearLayers();
                        this.arrivalLayer.clearLayers();
                        this.approachCount = 0;
                        (j.approaches || []).forEach(a => {
                            const points = (a.points || [])
                                .map(p => [Number(p[0]), Number(p[1])])
                                .filter(p => isFinite(p[0]) && isFinite(p[1]));
                            if (points.length < 2) return;
                            NestPosBasemaps.deliveryTrail(points, {
                                learned: true,
                                color: '#d97706',
                            }).addTo(this.placeLayer);
                            this.approachCount++;
                        });
                        (j.places || []).forEach(p => {
                            if (!isFinite(p.lat) || !isFinite(p.lng)) return;
                            const emoji = p.type === 'home' ? '🏠' : (p.type === 'business' ? '🏢' : '📍');
                            const icon = L.divIcon({
                                className: '',
                                html: '<div style="font-size:22px;filter:drop-shadow(0 1px 2px rgba(0,0,0,.3))">' + emoji + '</div>',
                                iconSize: [24, 24], iconAnchor: [12, 22]
                            });
                            L.marker([p.lat, p.lng], { icon, zIndexOffset: 200 })
                                .addTo(this.placeLayer)
                                .bindPopup('<b>' + this.esc(p.label || p.type) + '</b>'
                                    + (p.address ? '<br>' + this.esc(p.address) : '')
                                    + '<br>' + this.gmapsLink(p.lat, p.lng));
                        });
                        (j.arrivals || []).forEach(a => {
                            if (!isFinite(a.lat) || !isFinite(a.lng)) return;
                            L.circleMarker([a.lat, a.lng], {
                                radius: 5,
                                color: a.verified ? '#10b981' : '#f59e0b',
                                fillColor: a.verified ? '#10b981' : '#f59e0b',
                                fillOpacity: .65,
                            }).addTo(this.arrivalLayer).bindPopup(
                                '<b>' + this.esc(a.label || a.type) + '</b>'
                                + (a.rider ? '<br>' + this.esc(a.rider) : '')
                                + (a.captured_at ? '<br>' + this.esc(new Date(a.captured_at).toLocaleString()) : '')
                                + '<br>' + this.gmapsLink(a.lat, a.lng)
                            );
                        });
                    }).catch(() => {});
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
                    // Task #1102: warning badge — silent (red) beats idle (amber).
                    // Task #1106: low battery is third priority (connectivity
                    // problems are more urgent than a draining phone).
                    const warn = r.is_silent ? 'silent' : (r.is_idle ? 'idle' : (r.low_battery ? 'battery' : null));
                    const warnLabel = warn === 'silent' ? this.i18n.silent_badge
                        : (warn === 'idle' ? this.i18n.idle_badge
                        : (warn === 'battery' ? ('🪫 ' + this.i18n.battery_low_badge + ' (' + r.battery_pct + '%)') : ''));
                    const popup = '<b>' + this.esc(r.name) + '</b><br>'
                        + (r.on_duty ? this.i18n.on_duty : this.i18n.off_duty)
                        + '<br>' + this.esc(this.agoText(r))
                        + (r.open_deliveries ? '<br>' + this.i18n.open_deliveries + ': ' + r.open_deliveries : '')
                        + (r.battery_pct !== null ? '<br>' + (r.low_battery ? '🪫' : '🔋') + ' '
                            + this.esc(this.i18n.battery_label) + ' <b' + (r.low_battery ? ' style="color:#dc2626"' : '') + '>'
                            + r.battery_pct + '%</b>' : '')
                        + (warn ? '<br><b style="color:' + (warn === 'silent' ? '#ef4444' : (warn === 'idle' ? '#d97706' : '#dc2626')) + '">'
                            + this.esc(warnLabel) + '</b>' : '')
                        // Task #1357: der se aaya upload, reject ki wajah, aur gali
                        // dekhne ke liye Google Maps link.
                        + (this.uploadLate(r) ? '<br><span style="color:#4f46e5;font-weight:600">' + this.esc(this.uploadText(r)) + '</span>' : '')
                        + (r.reject_reason ? '<br><span style="color:#4f46e5;font-weight:600">' + this.esc(this.rejectText(r)) + '</span>' : '')
                        // Task #1405: is phone par app ka kaun sa build hai.
                        + this.appPopupLine(r)
                        + '<br>' + this.gmapsLink(r.lat, r.lng);
                    // Pill floats above the dot; recreating it every poll is cheap (few riders).
                    if (this.warnBadges[r.id]) {
                        this.warnBadges[r.id].remove();
                        delete this.warnBadges[r.id];
                    }
                    if (warn) {
                        const bicon = L.divIcon({
                            className: 'rt-warn-map',
                            // Transform on the PILL SPAN itself (not the container):
                            // Leaflet's default divIcon container is 12×12 px, so
                            // translate(-50%) on the container shifts only 6 px.
                            // On the inline-block pill span, translate(-50%) uses the
                            // pill's own rendered width → correct centering in any lang.
                            // iconAnchor:[0,0] = container top-left at marker position;
                            // the pill's transform then moves it left by half its width
                            // and fully above the dot with a 10 px gap.
                            html: '<span class="rt-warn-pill ' + warn + '" style="display:inline-block;transform:translate(-50%,calc(-100% - 10px));">' + this.esc(warnLabel) + '</span>',
                            iconAnchor: [0, 0],
                        });
                        this.warnBadges[r.id] = L.marker([r.lat, r.lng], {
                            icon: bicon, interactive: false, zIndexOffset: 800
                        }).addTo(this.map);
                    }
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
                    // Dukan set ho to woh bhi frame mein rahe (rider start point).
                    if (this.shopLat !== null && this.shopLng !== null) bounds.push([this.shopLat, this.shopLng]);
                    this.map.fitBounds(bounds, { padding: [40, 40], maxZoom: 15 });
                }
            },
            // ---- Task #320: dukan ki location (ZFC) ----
            renderShopMarker(lat, lng, isPending) {
                const html = '<div style="font-size:26px; line-height:1; filter: drop-shadow(0 1px 2px rgba(0,0,0,.35));'
                    + (isPending ? ' opacity:.75;' : '') + '">🏪</div>';
                const icon = L.divIcon({ className: '', html: html, iconSize: [26, 26], iconAnchor: [13, 24] });
                // Pending pin must be DRAGGABLE (ZFC: "pin ko apni dukan par ghaseet lein").
                // Leaflet can't toggle draggable after creation reliably — recreate when mode changes.
                if (this.shopMarker && this.shopMarkerDraggable !== isPending) {
                    this.shopMarker.remove();
                    this.shopMarker = null;
                }
                if (this.shopMarker) {
                    this.shopMarker.setLatLng([lat, lng]);
                    this.shopMarker.setIcon(icon);
                } else {
                    this.shopMarker = L.marker([lat, lng], { icon: icon, zIndexOffset: 500, draggable: isPending })
                        .addTo(this.map).bindPopup('<b>' + this.esc(this.i18n.shop_label) + '</b>');
                    this.shopMarkerDraggable = isPending;
                    if (isPending) {
                        this.shopMarker.on('dragend', () => {
                            const ll = this.shopMarker.getLatLng();
                            this.pendingShop = { lat: ll.lat, lng: ll.lng };
                        });
                    }
                }
            },
            // Google Maps link / raw "lat, lng" paste → direct pin (no geocoder needed).
            // Handles: .../@31.52,74.35,17z · ...!3d31.52!4d74.35 · ?q=31.52,74.35 (also %2C) · "31.52, 74.35"
            parseLatLng(q) {
                const pats = [
                    /!3d(-?\d{1,2}\.\d+)!4d(-?\d{1,3}\.\d+)/,
                    /@(-?\d{1,2}\.\d+),(-?\d{1,3}\.\d+)/,
                    /[?&](?:q|query|ll|destination)=(-?\d{1,2}\.\d+)(?:%2C|,)\s*(-?\d{1,3}\.\d+)/i,
                    /^\s*(-?\d{1,2}\.\d+)\s*[, ]\s*(-?\d{1,3}\.\d+)\s*$/,
                ];
                for (const re of pats) {
                    const m = q.match(re);
                    if (!m) continue;
                    const lat = parseFloat(m[1]), lng = parseFloat(m[2]);
                    if (isFinite(lat) && isFinite(lng) && this.pkBounds && this.pkBounds.contains([lat, lng])) {
                        return { lat, lng };
                    }
                }
                return null;
            },
            dropPin(lat, lng) {
                this.searchResults = [];
                this.searchDone = false;
                this.userCentered = true;
                this.settingShop = true;
                this.pendingShop = { lat, lng };
                this.renderShopMarker(lat, lng, true);
                this.map.setView([lat, lng], 17);
                this.statusLine = this.i18n.pin_dropped;
            },
            toggleSetShop() {
                if (this.settingShop) { this.cancelSetShop(); return; }
                this.settingShop = true;
                this.pendingShop = null;
            },
            cancelSetShop() {
                this.settingShop = false;
                this.pendingShop = null;
                // Pin wapis saved jagah par (ya hata do agar kabhi save hi nahi hui)
                if (this.shopLat !== null && this.shopLng !== null) {
                    this.renderShopMarker(this.shopLat, this.shopLng, false);
                } else if (this.shopMarker) {
                    this.shopMarker.remove();
                    this.shopMarker = null;
                }
            },
            saveShop() {
                if (!this.pendingShop || this.shopBusy) return;
                this.shopBusy = true;
                fetch(cfg.shopSaveUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                    },
                    body: JSON.stringify({ lat: this.pendingShop.lat, lng: this.pendingShop.lng }),
                })
                    .then(r => r.json().then(j => ({ ok: r.ok, j })))
                    .then(({ ok, j }) => {
                        this.shopBusy = false;
                        if (!ok || !j || !j.ok) { alert(this.i18n.shop_error); return; }
                        this.shopLat = j.lat;
                        this.shopLng = j.lng;
                        this.settingShop = false;
                        this.pendingShop = null;
                        this.renderShopMarker(this.shopLat, this.shopLng, false);
                        this.statusLine = this.i18n.shop_saved;
                    })
                    .catch(() => { this.shopBusy = false; alert(this.i18n.shop_error); });
            },
            selectRider(r) {
                this.selected = r;
                if (r.lat !== null && r.lng !== null) {
                    // Task #1357: 14 se 16 — gali level par khulo, sirf sarak nahi.
                    this.map.setView([r.lat, r.lng], Math.max(this.map.getZoom(), 16));
                    if (this.markers[r.id]) this.markers[r.id].openPopup();
                }
                fetch(this.trailUrlBase + '/' + r.id, { headers: { 'Accept': 'application/json' } })
                    .then(resp => resp.ok ? resp.json() : null)
                    .then(j => {
                        if (!j || !j.ok) return;
                        this.clearTrailLayers();
                        const pts = j.points || [];
                        const gaps = j.gaps || [];
                        this.trailPts = pts; // Task #1102: playback source
                        // Task #1357: kitna hissa live nahi tha, aur kab sync hua.
                        this.lateCount = j.late_count || 0;
                        this.lateLastSync = j.late_last_sync || '';
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
                            // Keep the FULL point (lat, lng, 'H:i', epoch) — the
                            // speed colouring needs the timestamp (Task #1102).
                            seg.push(p);
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

                        segments.forEach((s, si) => {
                            if (s.pts.length >= 2) {
                                // Task #1102: speed-coloured sub-polylines instead
                                // of one solid indigo line.
                                this.drawSpeedSegment(s.pts);
                                s.pts.forEach(p => allBounds.push([p[0], p[1]]));
                            } else if (s.pts.length === 1) {
                                allBounds.push([s.pts[0][0], s.pts[0][1]]);
                            }

                            // If there's a gap after this segment, draw a dashed connector.
                            if (s.gapAfter && si + 1 < segments.length) {
                                const fromPt = [s.pts[s.pts.length - 1][0], s.pts[s.pts.length - 1][1]];
                                const toPt   = [segments[si + 1].pts[0][0], segments[si + 1].pts[0][1]];
                                const isOffline = s.gapAfter.is_offline_after;
                                const gapColor = isOffline ? '#6366f1' : '#f59e0b';

                                const dash = NestPosBasemaps.deliveryTrail([fromPt, toPt], {
                                    late: isOffline,
                                    dashed: true,
                                    color: gapColor,
                                }).addTo(this.map);
                                this.gapLayers.push(dash);

                                // divIcon pill label at midpoint of the dashed line.
                                const midLat = (fromPt[0] + toPt[0]) / 2;
                                const midLng = (fromPt[1] + toPt[1]) / 2;
                                const mins = s.gapAfter.minutes;
                                // Task #1357: offline gap ab saaf batata hai ke yeh
                                // hissa live nahi tha, aur kis waqt sync hua.
                                const tpl = isOffline
                                    ? (s.gapAfter.synced_at ? this.i18n.gap_offline_at : this.i18n.gap_offline)
                                    : this.i18n.gap_stopped;
                                const label = tpl.replace(':min', mins).replace(':time', s.gapAfter.synced_at || '');
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

                        // Task #1102: dots where the rider stood still ≥3 min.
                        this.markStops(pts);

                        if (allBounds.length >= 2) {
                            // Task #1357: map ab 21 tak ja sakta hai — auto-fit ko
                            // cap karo warna thoda sa chala hua rider poora zoom
                            // kar deta hai.
                            this.map.fitBounds(allBounds, { padding: [40, 40], maxZoom: 18 });
                        } else if (allBounds.length === 1) {
                            this.map.setView(allBounds[0], 17);
                        }
                    })
                    .catch(() => {});
            },
            // ---- Task #1102: speed colouring, stops, playback ----
            // Equirectangular distance in metres — fine for adjacent GPS fixes.
            distM(a, b) {
                const R = 6371000, rad = Math.PI / 180;
                const dLat = (b[0] - a[0]) * rad;
                const dLng = (b[1] - a[1]) * rad * Math.cos(((a[0] + b[0]) / 2) * rad);
                return Math.sqrt(dLat * dLat + dLng * dLng) * R;
            },
            // km/h between two trail points, or null when timestamps unusable.
            segSpeed(a, b) {
                const t1 = a[3], t2 = b[3];
                if (!t1 || !t2 || t2 <= t1) return null;
                return this.distM(a, b) / (t2 - t1) * 3.6;
            },
            speedColor(kmh) {
                if (kmh === null) return '#4f46e5';
                if (kmh < 6) return '#f97316';    // slow — walking pace / crawling
                if (kmh <= 25) return '#10b981';  // normal city riding
                return '#4f46e5';                 // fast
            },
            // One solid segment → runs of same-colour pairs merged into polylines
            // (avoids thousands of 2-point layers on long trails).
            drawSpeedSegment(segPts) {
                let run = [segPts[0]], runColor = null, runLate = null;
                const flush = () => {
                    if (run.length >= 2) {
                        // Task #1357: jo hissa baad mein (offline buffer se) aaya
                        // woh dashed indigo — offline gap pill wale rang mein, taake
                        // owner ko saaf dikhe ke yeh live nahi tha.
                        const popupPts = run.slice();
                        const line = NestPosBasemaps.deliveryTrail(run.map(p => [p[0], p[1]]), {
                            late: runLate,
                            color: runColor || '#009b8a',
                            onClick: (e) => this.showTrailPopup(e, popupPts),
                        }).addTo(this.map);
                        this.gapLayers.push(line);
                    }
                };
                for (let k = 1; k < segPts.length; k++) {
                    const c = this.speedColor(this.segSpeed(segPts[k - 1], segPts[k]));
                    const late = this.isLate(segPts[k]);
                    if (runColor === null) { runColor = c; runLate = late; }
                    if (c !== runColor || late !== runLate) {
                        flush();
                        run = [segPts[k - 1]];
                        runColor = c;
                        runLate = late;
                    }
                    run.push(segPts[k]);
                }
                flush();
            },
            // Task #1357: trail par kahin bhi click → qareeb tareen point, uska
            // waqt, live tha ya baad mein sync hua, aur "Google Maps mein kholen"
            // (gali ka naam wahin se milta hai).
            showTrailPopup(e, pts) {
                let best = pts[0], bestD = Infinity;
                const here = [e.latlng.lat, e.latlng.lng];
                pts.forEach(p => {
                    const d = this.distM(here, p);
                    if (d < bestD) { bestD = d; best = p; }
                });
                if (!best) return;
                const html = '<b>' + this.esc(best[2] || '') + '</b>'
                    + (this.isLate(best) ? '<br><span style="color:#4f46e5;font-weight:600">'
                        + this.esc(this.i18n.late_point.replace(':time', best[5] || '')) + '</span>' : '')
                    + '<br>' + this.gmapsLink(best[0], best[1]);
                L.popup().setLatLng([best[0], best[1]]).setContent(html).openOn(this.map);
            },
            // Cluster consecutive points within ~45 m; ≥3 min inside = a stop dot.
            markStops(pts) {
                let i = 0;
                while (i < pts.length) {
                    if (!pts[i][3]) { i++; continue; }
                    let j = i;
                    while (j + 1 < pts.length && pts[j + 1][3]
                        && this.distM(pts[i], pts[j + 1]) < 45) j++;
                    const dur = (pts[j][3] || 0) - (pts[i][3] || 0);
                    if (j > i && dur >= 180) {
                        const label = this.i18n.stopped_here.replace(':min', Math.round(dur / 60));
                        const cm = L.circleMarker([pts[i][0], pts[i][1]], {
                            radius: 7, weight: 2, color: '#b45309', fillColor: '#f59e0b', fillOpacity: 0.9
                        }).addTo(this.map).bindPopup('<b>' + this.esc(label) + '</b><br>' + this.esc(pts[i][2] || '')
                            // Task #1357: stop par bhi "Google Maps mein kholen".
                            + '<br>' + this.gmapsLink(pts[i][0], pts[i][1]));
                        this.gapLayers.push(cm);
                        i = j + 1;
                    } else {
                        i++;
                    }
                }
            },
            togglePlay() {
                if (this.playing) { this.pausePlay(); return; }
                if (this.trailPts.length < 2) return;
                if (this.playIdx >= this.trailPts.length - 1) this.playIdx = 0;
                this.playing = true;
                this.ensurePlayMarker();
                // Whole day compresses to ~45 s regardless of point count.
                const iv = Math.max(20, Math.min(280, Math.round(45000 / this.trailPts.length)));
                this.playTimer = setInterval(() => this.playTick(), iv);
            },
            pausePlay() {
                this.playing = false;
                if (this.playTimer) { clearInterval(this.playTimer); this.playTimer = null; }
            },
            ensurePlayMarker() {
                const p = this.trailPts[this.playIdx];
                if (!p) return;
                if (this.playMarker) { this.playMarker.setLatLng([p[0], p[1]]); return; }
                const icon = L.divIcon({
                    className: '',
                    html: '<div style="font-size:24px; line-height:1; filter: drop-shadow(0 1px 2px rgba(0,0,0,.4));">🛵</div>',
                    iconSize: [24, 24], iconAnchor: [12, 12],
                });
                this.playMarker = L.marker([p[0], p[1]], { icon: icon, zIndexOffset: 900, interactive: false })
                    .addTo(this.map);
            },
            playTick() {
                if (this.playIdx >= this.trailPts.length - 1) { this.pausePlay(); return; }
                this.playIdx++;
                const prev = this.trailPts[this.playIdx - 1];
                const cur = this.trailPts[this.playIdx];
                if (this.playMarker) this.playMarker.setLatLng([cur[0], cur[1]]);
                const kmh = this.segSpeed(prev, cur);
                this.playReadout = (cur[2] || '')
                    + (kmh !== null ? ' · ' + Math.round(kmh) + ' km/h' : '');
            },
            clearTrailLayers() {
                if (this.polyline) { this.polyline.remove(); this.polyline = null; }
                // Task #1357: trail-point popup apni polyline ke baad zinda na rahe.
                try { this.map.closePopup(); } catch (e) {}
                this.lateCount = 0;
                this.lateLastSync = '';
                this.gapLayers.forEach(l => { try { l.remove(); } catch(e) {} });
                this.gapLayers = [];
                // Task #1102: reset playback with the layers it animates over.
                this.pausePlay();
                this.playIdx = 0;
                this.playReadout = '';
                if (this.playMarker) { try { this.playMarker.remove(); } catch(e) {} this.playMarker = null; }
            },
            clearTrail() {
                this.selected = null;
                this.clearTrailLayers();
                this.trailPts = [];
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
                // Google Maps link / coordinates paste — pin lands directly, no geocoder.
                const ll = this.parseLatLng(q);
                if (ll) { this.dropPin(ll.lat, ll.lng); return; }
                // Redirect-only Google share link (maps.app.goo.gl/…) — no coords
                // in the URL; server follows the redirects (browser can't, CORS).
                if (/^https?:\/\/(maps\.app\.goo\.gl|goo\.gl|g\.co|(?:www\.|maps\.)?google\.com(?:\.pk)?)\//i.test(q)) {
                    this.resolveLink(q);
                    return;
                }
                this.searchBusy = true;
                this.searchDone = false;
                const url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=5&countrycodes=pk&accept-language=en&q=' + encodeURIComponent(q);
                fetch(url, { headers: { 'Accept': 'application/json' } })
                    .then(r => r.ok ? r.json() : [])
                    .then(list => {
                        const results = Array.isArray(list) ? list : [];
                        if (results.length) {
                            this.searchResults = results;
                            this.searchDone = true;
                            this.searchBusy = false;
                            return;
                        }
                        // Photon fallback (komoot) — better small-business/POI coverage
                        // than Nominatim for names only listed as OSM POIs.
                        return this.photonSearch(q);
                    })
                    .catch(() => this.photonSearch(q).catch(() => {
                        this.searchResults = []; this.searchDone = true; this.searchBusy = false;
                    }));
            },
            resolveLink(q) {
                this.searchBusy = true;
                this.searchDone = false;
                fetch(cfg.resolveLinkUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                    },
                    body: JSON.stringify({ url: q }),
                })
                    .then(r => r.json().catch(() => null))
                    .then(j => {
                        this.searchBusy = false;
                        if (j && j.ok && isFinite(j.lat) && isFinite(j.lng)) {
                            this.dropPin(j.lat, j.lng);
                        } else {
                            this.searchResults = [];
                            this.searchDone = true; // shows no-results + drag hint
                        }
                    })
                    .catch(() => { this.searchBusy = false; this.searchResults = []; this.searchDone = true; });
            },
            photonSearch(q) {
                // Pakistan bbox keeps results local (Photon has no countrycodes param).
                const url = 'https://photon.komoot.io/api/?limit=5&lang=en&bbox=60.4,22.8,77.6,37.5&q=' + encodeURIComponent(q);
                return fetch(url, { headers: { 'Accept': 'application/json' } })
                    .then(r => r.ok ? r.json() : null)
                    .then(j => {
                        const feats = (j && Array.isArray(j.features)) ? j.features : [];
                        this.searchResults = feats
                            .filter(f => f && f.geometry && Array.isArray(f.geometry.coordinates))
                            .map(f => {
                                const p = f.properties || {};
                                const name = [p.name, p.street, p.district, p.city, p.state]
                                    .filter(Boolean).join(', ');
                                return {
                                    display_name: name || (f.geometry.coordinates[1] + ', ' + f.geometry.coordinates[0]),
                                    lat: f.geometry.coordinates[1],
                                    lon: f.geometry.coordinates[0],
                                };
                            })
                            .filter(r => this.pkBounds && this.pkBounds.contains([parseFloat(r.lat), parseFloat(r.lon)]));
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
                if (this.settingShop) {
                    // Pin-mode: search result becomes the (draggable) pending pin.
                    this.pendingShop = { lat, lng };
                    this.renderShopMarker(lat, lng, true);
                    this.statusLine = this.i18n.pin_dropped;
                    this.map.setView([lat, lng], 17);
                    return;
                }
                this.map.setView([lat, lng], 14);
            },
            // ---- Task #1357: Google link + late-sync helpers ----
            isLate(p) {
                return !!(p && p.length > 4 && p[4]);
            },
            gmapsUrl(lat, lng) {
                return 'https://www.google.com/maps/search/?api=1&query='
                    + encodeURIComponent(Number(lat).toFixed(6) + ',' + Number(lng).toFixed(6));
            },
            // Sirf free deep link — na Google ki tiles, na koi API key (owner rule).
            gmapsLink(lat, lng) {
                if (lat === null || lng === null || !isFinite(lat) || !isFinite(lng)) return '';
                return '<a href="' + this.gmapsUrl(lat, lng) + '" target="_blank" rel="noopener"'
                    + ' style="color:#4f46e5;font-weight:600;text-decoration:underline">'
                    + this.esc(this.i18n.open_in_gmaps) + '</a>';
            },
            agoSecsText(s) {
                if (s === null || s === undefined) return '';
                if (s < 60) return this.i18n.just_now;
                return Math.floor(s / 60) + ' ' + this.i18n.min_ago;
            },
            // "Phone ne location to li thi, bheji der se" — sirf tab dikhao jab
            // fix ka waqt aur upload ka waqt numaya tor par alag hon (2+ min).
            uploadLate(r) {
                return r.upload_lag_secs !== null && r.upload_lag_secs !== undefined
                    && r.upload_lag_secs >= 120
                    && r.upload_secs_ago !== null && r.upload_secs_ago !== undefined;
            },
            uploadText(r) {
                return '📤 ' + this.i18n.last_upload + ': ' + this.agoSecsText(r.upload_secs_ago)
                    + ' · ' + this.i18n.upload_lag.replace(':min', Math.round(r.upload_lag_secs / 60));
            },
            rejectText(r) {
                const label = this.i18n['reject_' + r.reject_reason] || this.i18n.reject_other;
                const ago = this.agoSecsText(r.reject_secs_ago);
                return '⛔ ' + label + (ago ? ' · ' + ago : '');
            },
            // ---- Task #1405: kaun sa rider purane app par hai ----
            // "Purana app v1.6.0 · nayi 1.7.0" — dukandar ko yehi banda chase
            // karna hai (na push milta hai, na background delivery sync).
            appOldText(r) {
                return '📵 ' + this.i18n.app_old_badge + ' v' + r.app_version
                    + (r.app_latest ? ' · ' + this.i18n.app_update_to.replace(':ver', r.app_latest) : '');
            },
            // Marker popup ki app line — teenon halat (purana / kabhi nahi khola
            // / theek) ek hi jagah se banti hain.
            appPopupLine(r) {
                if (r.app_never) {
                    return '<br><span style="color:#7c3aed;font-weight:600">📵 '
                        + this.esc(this.i18n.app_never_badge) + '</span>';
                }
                if (r.app_outdated) {
                    return '<br><span style="color:#b45309;font-weight:600">'
                        + this.esc(this.appOldText(r)) + '</span>';
                }
                if (r.app_version) {
                    return '<br>📱 ' + this.esc(this.i18n.app_label) + ' v' + this.esc(r.app_version);
                }
                return '';
            },
            lateLegendText() {
                return this.i18n.late_legend
                    .replace(':n', this.lateCount)
                    .replace(':time', this.lateLastSync || '');
            },
            esc(s) {
                return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            }
        };
    }
    </script>
@endif
</x-pos-layout>
