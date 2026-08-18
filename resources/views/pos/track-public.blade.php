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
        .stale { display: none; padding: 6px 16px; font-size: 11px; font-weight: 600; color: #92400e; background: #fef3c7; flex-shrink: 0; }
    </style>
</head>
<body>
@if($state === 'gone')
    <div class="hdr"><span class="shop">Live Tracking</span><span class="brand">TaxNest POS</span></div>
    <div class="center-card">
        <div class="card">
            <div class="big">🔗</div>
            <h1>Link expire ho gaya</h1>
            <p>Yeh tracking link ab kaam nahi kar raha.<br>This tracking link is no longer active.</p>
        </div>
    </div>
@elseif($state === 'done')
    <div class="hdr"><span class="shop">{{ $shopName }}</span><span class="brand">TaxNest POS</span></div>
    <div class="center-card">
        <div class="card">
            @if(($boot['status'] ?? '') === 'returned')
            <div class="big">↩️</div>
            <h1>Order wapas ho gaya</h1>
            <p>Yeh order wapas kar diya gaya hai.<br>This order was returned.</p>
            @else
            <div class="big">✅</div>
            <h1>Order deliver ho gaya!</h1>
            <p>Aap ka order pahunch chuka hai — shukriya!<br>Your order has been delivered — thank you!</p>
            @endif
        </div>
    </div>
@else
    <div class="hdr"><span class="shop">{{ $shopName }}</span><span class="brand">TaxNest POS</span></div>
    <div class="status-bar">
        <span id="st-chip" class="chip preparing">…</span>
        <span id="st-eta" class="eta"></span>
    </div>
    <div id="stale-note" class="stale">Rider ka signal thori der se nahi aaya — thora intezar karein. (Rider signal delayed.)</div>
    <div id="map"></div>
    <div class="note">Aap ka rider live map par — page khud refresh hota hai. · Your rider, live — updates automatically.</div>
    <script>
    (function () {
        var TOKEN = @js($token);
        var boot = @js($boot);
        // Bilingual status labels — page has no locale (Roman Urdu + English).
        var LABELS = {
            preparing:  'Tayyar ho raha hai · Preparing',
            assigned:   'Rider mil gaya · Rider assigned',
            dispatched: 'Rider rawana hai · On the way',
            delivered:  'Deliver ho gaya · Delivered',
            returned:   'Wapas ho gaya · Returned'
        };
        var map = L.map('map', {
            maxBounds: [[22.8, 60.4], [37.5, 77.6]],
            maxBoundsViscosity: 1.0,
            minZoom: 5
        });
        L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap &middot; &copy; CARTO'
        }).addTo(map);

        var riderIcon = L.divIcon({ className: '', html: '<div style="font-size:26px;line-height:1;filter:drop-shadow(0 1px 2px rgba(0,0,0,.4))">🛵</div>', iconSize: [26, 26], iconAnchor: [13, 13] });
        var homeIcon  = L.divIcon({ className: '', html: '<div style="font-size:26px;line-height:1;filter:drop-shadow(0 1px 2px rgba(0,0,0,.4))">📍</div>', iconSize: [26, 26], iconAnchor: [13, 24] });
        var riderMarker = null, homeMarker = null, line = null, firstFit = true, stopped = false;

        function render(d) {
            var chip = document.getElementById('st-chip');
            var eta = document.getElementById('st-eta');
            var stale = document.getElementById('stale-note');
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
            }
            if (d.rider) {
                stale.style.display = 'none';
                if (!riderMarker) riderMarker = L.marker([d.rider.lat, d.rider.lng], { icon: riderIcon }).addTo(map);
                else riderMarker.setLatLng([d.rider.lat, d.rider.lng]);
                if (d.customer) {
                    var pts = [[d.rider.lat, d.rider.lng], [d.customer.lat, d.customer.lng]];
                    if (!line) line = L.polyline(pts, { color: '#0A4D5C', weight: 3, dashArray: '6 8', opacity: .7 }).addTo(map);
                    else line.setLatLngs(pts);
                    if (firstFit) { map.fitBounds(pts, { padding: [40, 40] }); firstFit = false; }
                } else if (firstFit) {
                    map.setView([d.rider.lat, d.rider.lng], 15); firstFit = false;
                }
            } else {
                // No fresh rider fix yet — show the customer pin (or PK center).
                stale.style.display = (st === 'dispatched' || st === 'assigned') ? 'block' : 'none';
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
