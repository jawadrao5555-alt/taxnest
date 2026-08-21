<!DOCTYPE html>
{{-- Task 1105: PUBLIC customer live tracking page ("aapka rider yahan hai").
     NO login, NO session (stateless route), NO company data beyond shop name +
     rider position + delivery status. Copy is hardcoded Roman Urdu + English —
     the customer never picked a POS locale. Fetches use RELATIVE paths only
     (route() forces https on plain-http dev). States:
       gone = bad token / plan lapse / link expired  (HTTP 410)
       done = delivered or returned (read-only, no map poll)
       live = map + 10s poll --}}
<html lang="ur">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $shopName ? $shopName . ' — Live Tracking' : 'Live Tracking' }}</title>
    @if($state === 'live')
    <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}?v=1">
    <script src="{{ asset('vendor/leaflet/leaflet.js') }}?v=1"></script>
    @endif
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f3f4f6; color: #111827;
            display: flex; flex-direction: column;
        }
        .hdr {
            background: #0A4D5C; color: #fff; padding: 12px 16px;
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
            flex-shrink: 0;
        }
        .hdr .shop { font-size: 15px; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .hdr .brand { font-size: 10px; opacity: .75; white-space: nowrap; }
        .status-bar {
            background: #fff; border-bottom: 1px solid #e5e7eb; padding: 10px 16px;
            display: flex; align-items: center; gap: 10px; flex-shrink: 0;
        }
        .chip {
            display: inline-block; padding: 3px 10px; border-radius: 999px;
            font-size: 12px; font-weight: 700;
        }
        .chip.preparing  { background: #fef3c7; color: #92400e; }
        .chip.assigned   { background: #e5e7eb; color: #374151; }
        .chip.dispatched { background: #ede9fe; color: #5b21b6; }
        .chip.delivered  { background: #d1fae5; color: #065f46; }
        .chip.returned   { background: #fee2e2; color: #991b1b; }
        .eta { font-size: 12px; color: #4b5563; font-weight: 600; }
        #map { flex: 1; min-height: 200px; z-index: 0; }
        .note { padding: 8px 16px; font-size: 11px; color: #6b7280; background: #fff; border-top: 1px solid #e5e7eb; flex-shrink: 0; }
        .center-card {
            flex: 1; display: flex; align-items: center; justify-content: center; padding: 24px;
        }
        .card {
            background: #fff; border: 1px solid #e5e7eb; border-radius: 16px;
            padding: 28px 22px; max-width: 340px; text-align: center;
            box-shadow: 0 1px 4px rgba(0,0,0,.06);
        }
        .card .big { font-size: 40px; margin-bottom: 10px; }
        .card h1 { font-size: 17px; margin-bottom: 8px; }
        .card p { font-size: 13px; color: #6b7280; line-height: 1.6; }
        .stale, .last-seen { display: none; padding: 6px 16px; font-size: 11px; font-weight: 600; color: #92400e; background: #fef3c7; flex-shrink: 0; }
        /* Task #1401: bilingual layer names must stay readable on a phone. */
        .leaflet-control-layers { font-size: 12px; line-height: 1.6; }
        .leaflet-control-layers-base label { white-space: nowrap; }
    </style>
</head>
<body>
@if($state === 'gone')
    <div class="hdr"><span class="shop">Live Tracking</span><span class="brand">TaxNest POS</span></div>
    <div class="center-card">
        <div class="card">
            <div class="big">🔗</div>
            <h1>{{ __('pos.rt_pub_expired_h', [], 'rur') }}</h1>
            <p>{{ __('pos.rt_pub_expired_p', [], 'rur') }}<br>{{ __('pos.rt_pub_expired_p', [], 'en') }}</p>
        </div>
    </div>
@elseif($state === 'done')
    <div class="hdr"><span class="shop">{{ $shopName }}</span><span class="brand">TaxNest POS</span></div>
    <div class="center-card">
        <div class="card">
            @if(($boot['status'] ?? '') === 'returned')
            <div class="big">↩️</div>
            <h1>{{ __('pos.rt_pub_returned_h', [], 'rur') }}</h1>
            <p>{{ __('pos.rt_pub_returned_p', [], 'rur') }}<br>{{ __('pos.rt_pub_returned_p', [], 'en') }}</p>
            @else
            <div class="big">✅</div>
            <h1>{{ __('pos.rt_pub_delivered_h', [], 'rur') }}</h1>
            <p>{{ __('pos.rt_pub_delivered_p', [], 'rur') }}<br>{{ __('pos.rt_pub_delivered_p', [], 'en') }}</p>
            @endif
        </div>
    </div>
@else
    <div class="hdr"><span class="shop">{{ $shopName }}</span><span class="brand">TaxNest POS</span></div>
    <div class="status-bar">
        <span id="st-chip" class="chip preparing">…</span>
        <span id="st-eta" class="eta"></span>
    </div>
    <div id="stale-note" class="stale">{{ __('pos.rt_pub_stale', [], 'rur') }} ({{ __('pos.rt_pub_stale', [], 'en') }})</div>
    <div id="last-seen-note" class="last-seen"></div>
    <div id="map"></div>
    <div class="note">{{ __('pos.rt_pub_note', [], 'rur') }} · {{ __('pos.rt_pub_note', [], 'en') }}</div>
    @php
        // Task #1401: page has no locale (bilingual by design) — Roman Urdu ·
        // English, collapsed to one word when both languages read the same.
        $bi = function (string $key) {
            $rur = __('pos.' . $key, [], 'rur');
            $en  = __('pos.' . $key, [], 'en');
            return $rur === $en ? $rur : $rur . ' · ' . $en;
        };
    @endphp
    <script>
    (function () {
        var TOKEN = @js($token);
        var boot = @js($boot);
        // Bilingual status labels — page has no locale (Roman Urdu + English),
        // strings live in lang/{rur,en}/pos.php (Task #1131 lang-key cleanup).
        var LABELS = {
            preparing:  @js(__('pos.rt_pub_st_preparing', [], 'rur') . ' · ' . __('pos.rt_pub_st_preparing', [], 'en')),
            assigned:   @js(__('pos.rt_pub_st_assigned', [], 'rur') . ' · ' . __('pos.rt_pub_st_assigned', [], 'en')),
            dispatched: @js(__('pos.rt_pub_st_dispatched', [], 'rur') . ' · ' . __('pos.rt_pub_st_dispatched', [], 'en')),
            delivered:  @js(__('pos.rt_pub_st_delivered', [], 'rur') . ' · ' . __('pos.rt_pub_st_delivered', [], 'en')),
            returned:   @js(__('pos.rt_pub_st_returned', [], 'rur') . ' · ' . __('pos.rt_pub_st_returned', [], 'en'))
        };
        // Task #1401: same basemap switcher the shop map already runs — a small
        // abadi has NO lanes on the street tiles, so the customer only sees his
        // gali on the imagery.
        var LAYER_LABELS = {
            streets:   @js($bi('rt_layer_streets')),
            satellite: @js($bi('rt_layer_satellite'))
        };
        var GMAPS_LABEL = @js($bi('rt_open_in_gmaps'));
        var MARKER_LABELS = {
            rider: @js($bi('rt_pub_marker_rider')),
            dest:  @js($bi('rt_pub_marker_dest'))
        };
        var map = L.map('map', {
            maxBounds: [[22.8, 60.4], [37.5, 77.6]],
            maxBoundsViscosity: 1.0,
            minZoom: 5,
            // Over-zoom past the providers' native zoom (tiles get scaled) so a
            // 3-metre gali stays readable — same depth as the shop map.
            maxZoom: 21
        });
        // Carto Voyager — English/Latin place labels (owner rule: OSM's own
        // tiles label Pakistani cities in Urdu script).
        var streetsLayer = L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
            maxNativeZoom: 19,
            maxZoom: 21,
            subdomains: 'abcd',
            attribution: '&copy; OpenStreetMap &middot; &copy; CARTO'
        });
        // Esri World Imagery = free, no API key, no paid tiles. English labels
        // ride on top via the Carto labels-only overlay. Neither request fires
        // until this layer is actually picked, so a streets-only visit stays
        // exactly as fast as before.
        var satelliteLayer = L.layerGroup([
            L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
                maxNativeZoom: 18,
                maxZoom: 21,
                attribution: 'Imagery &copy; Esri'
            }),
            L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager_only_labels/{z}/{x}/{y}{r}.png', {
                maxNativeZoom: 19,
                maxZoom: 21,
                subdomains: 'abcd',
                attribution: '&copy; OpenStreetMap &middot; &copy; CARTO'
            })
        ]);
        streetsLayer.rtKey = 'streets';
        satelliteLayer.rtKey = 'sat';
        // Chosen layer is remembered in the customer's own browser.
        var savedLayer = 'streets';
        try { savedLayer = localStorage.getItem('rt_pub_basemap') || 'streets'; } catch (e) {}
        (savedLayer === 'sat' ? satelliteLayer : streetsLayer).addTo(map);
        var layerOptions = {};
        layerOptions[LAYER_LABELS.streets] = streetsLayer;
        layerOptions[LAYER_LABELS.satellite] = satelliteLayer;
        L.control.layers(layerOptions, null, { position: 'topright', collapsed: false }).addTo(map);
        map.on('baselayerchange', function (e) {
            var key = (e.layer && e.layer.rtKey) === 'sat' ? 'sat' : 'streets';
            try { localStorage.setItem('rt_pub_basemap', key); } catch (err) {}
        });

        function esc(s) {
            return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }
        // Free Google Maps deep link only — no API key, no Google tiles.
        function gmapsPopup(title, lat, lng) {
            var la = Number(lat), ln = Number(lng);
            var head = '<div style="font-size:12px;font-weight:700;margin-bottom:4px">' + esc(title) + '</div>';
            if (!isFinite(la) || !isFinite(ln)) return head;
            var url = 'https://www.google.com/maps/search/?api=1&query='
                + encodeURIComponent(la.toFixed(6) + ',' + ln.toFixed(6));
            return head + '<a href="' + url + '" target="_blank" rel="noopener"'
                + ' style="font-size:12px;font-weight:600;color:#0A4D5C;text-decoration:underline">'
                + esc(GMAPS_LABEL) + '</a>';
        }
        function setPopup(marker, title, lat, lng) {
            var html = gmapsPopup(title, lat, lng);
            // setPopupContent keeps an already-open popup open (and its link
            // pointing at the LATEST fix, not where the rider used to be).
            if (marker.getPopup()) marker.setPopupContent(html);
            else marker.bindPopup(html);
        }

        var riderIcon = L.divIcon({ className: '', html: '<div style="font-size:26px;line-height:1;filter:drop-shadow(0 1px 2px rgba(0,0,0,.4))">🛵</div>', iconSize: [26, 26], iconAnchor: [13, 13] });
        var homeIcon  = L.divIcon({ className: '', html: '<div style="font-size:26px;line-height:1;filter:drop-shadow(0 1px 2px rgba(0,0,0,.4))">📍</div>', iconSize: [26, 26], iconAnchor: [13, 24] });
        var riderMarker = null, homeMarker = null, line = null, firstFit = true, stopped = false;
        var PUBLIC_STALE_AFTER_SECONDS = 5 * 60;

        function lastSeenCaption(secondsAgo) {
            var minutes = Math.max(1, Math.floor(secondsAgo / 60));
            return 'Aakhri signal ' + minutes + ' min pehle · Last seen ' + minutes + ' min ago';
        }

        function render(d) {
            var chip = document.getElementById('st-chip');
            var eta = document.getElementById('st-eta');
            var stale = document.getElementById('stale-note');
            var lastSeen = document.getElementById('last-seen-note');
            var st = d.status || 'preparing';
            chip.textContent = LABELS[st] || st;
            chip.className = 'chip ' + st;
            if (d.done) {
                // Delivered/returned mid-session → flip to the final screen.
                stopped = true;
                setTimeout(function () { window.location.reload(); }, 1200);
                return;
            }
            if (d.customer && !homeMarker) {
                homeMarker = L.marker([d.customer.lat, d.customer.lng], { icon: homeIcon }).addTo(map);
                setPopup(homeMarker, MARKER_LABELS.dest, d.customer.lat, d.customer.lng);
            }
            if (d.rider) {
                stale.style.display = 'none';
                var secondsAgo = Number(d.rider.seconds_ago);
                var riderIsStale = Number.isFinite(secondsAgo) && secondsAgo >= PUBLIC_STALE_AFTER_SECONDS;
                lastSeen.style.display = riderIsStale ? 'block' : 'none';
                lastSeen.textContent = riderIsStale ? lastSeenCaption(secondsAgo) : '';
                if (!riderMarker) riderMarker = L.marker([d.rider.lat, d.rider.lng], { icon: riderIcon }).addTo(map);
                else riderMarker.setLatLng([d.rider.lat, d.rider.lng]);
                setPopup(riderMarker, MARKER_LABELS.rider, d.rider.lat, d.rider.lng);
                riderMarker.setOpacity(riderIsStale ? 0.48 : 1);
                if (d.customer) {
                    var pts = [[d.rider.lat, d.rider.lng], [d.customer.lat, d.customer.lng]];
                    if (!line) line = L.polyline(pts, { color: '#0A4D5C', weight: 3, dashArray: '6 8', opacity: riderIsStale ? .35 : .7 }).addTo(map);
                    else {
                        line.setLatLngs(pts);
                        line.setStyle({ opacity: riderIsStale ? .35 : .7 });
                    }
                    if (firstFit) { map.fitBounds(pts, { padding: [40, 40] }); firstFit = false; }
                } else if (firstFit) {
                    map.setView([d.rider.lat, d.rider.lng], 15); firstFit = false;
                }
            } else {
                // No fresh rider fix yet — show the customer pin (or PK center).
                stale.style.display = (st === 'dispatched' || st === 'assigned') ? 'block' : 'none';
                lastSeen.style.display = 'none';
                lastSeen.textContent = '';
                if (riderMarker) riderMarker.setOpacity(1);
                if (line) line.setStyle({ opacity: .7 });
                if (firstFit) {
                    if (d.customer) map.setView([d.customer.lat, d.customer.lng], 15);
                    else map.setView([30.3753, 69.3451], 5);
                    firstFit = false;
                }
            }
            eta.textContent = (d.km != null && d.eta_min != null)
                ? (d.km + ' km · ~' + d.eta_min + ' min')
                : '';
        }

        async function poll() {
            if (stopped) return;
            try {
                // RELATIVE path — never route()/absolute (https-on-http trap).
                var r = await fetch('/track/' + TOKEN + '/data', { headers: { 'Accept': 'application/json' } });
                if (r.status === 410) {
                    stopped = true;
                    window.location.reload();
                    return;
                }
                if (!r.ok) return;
                var d = await r.json();
                if (d && d.ok) render(d);
            } catch (e) { /* offline blip — next poll retries */ }
        }

        render(boot);
        setInterval(poll, 10000);
    })();
    </script>
@endif
</body>
</html>
