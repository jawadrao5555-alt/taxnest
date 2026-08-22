/*!
 * NestPOS delivery basemaps — shared by the shop's rider live map and the
 * customer's public tracking link, so the two pages can never drift apart.
 *
 * WHY THIS EXISTS (owner rule, Aug 2026): map place names must read in English
 * (Latin). Raster basemaps print whatever the OSM `name` tag holds, so Carto
 * Voyager still labels a large share of Pakistani roads and localities in Urdu
 * script ("کالج روڈ", "حسین آباد"). OpenFreeMap serves free, key-less VECTOR
 * tiles that carry name:en / name:latin alongside name, so we render them with
 * MapLibre and force every label to prefer the English field
 * (see public/vendor/maps/nestpos-en.json, built by tools/maps/build-en-style.py).
 *
 * The satellite layer keeps Esri World Imagery and puts a labels-only cut of the
 * SAME style on top — switching layers never switches label language.
 *
 * Load order on the page:  leaflet.js -> maplibre-gl-csp.js -> leaflet-maplibre-gl.js
 *                          -> this file.
 * It MUST be MapLibre's CSP build: the app's Content-Security-Policy allows
 * scripts from 'self' only, and the normal build starts its worker from a
 * blob: URL, which the browser refuses ("Refused to create a worker from
 * blob:"). The CSP build loads maplibre-gl-csp-worker.js as a same-origin
 * file instead — see setWorkerUrl() below.
 * If WebGL is missing (old Android WebView) or the vector tiles fail, both
 * layers fall back to exactly the raster tiles these maps used before.
 */
(function (window, document) {
    'use strict';

    var L = window.L;
    if (!L) { return; }

    // Resolve sibling assets from this script's own URL so the helper works
    // under any asset() prefix without the blades having to pass paths in.
    var self = document.currentScript;
    var BASE = self && self.src
        ? self.src.replace(/[?#].*$/, '').replace(/[^/]*$/, '')
        : '/vendor/maps/';
    // Increment this cache version whenever the generated delivery style or this
    // renderer changes. Vector tiles themselves remain upstream-hosted, so OSM /
    // OpenFreeMap edits keep arriving normally without us baking tile data.
    var STYLE_URL = BASE + 'nestpos-en.json?v=2';

    // MapLibre's CSP build refuses to start until it is told where its worker
    // lives (a real same-origin file, not a blob: URL the CSP would block).
    if (window.maplibregl && typeof window.maplibregl.setWorkerUrl === 'function') {
        var tag = document.querySelector('script[src*="maplibre-gl-csp.js"]');
        var glBase = tag && tag.src
            ? tag.src.replace(/[?#].*$/, '').replace(/[^/]*$/, '')
            : BASE.replace(/maps\/$/, 'maplibre/');
        window.maplibregl.setWorkerUrl(glBase + 'maplibre-gl-csp-worker.js?v=1');
    }

    var ATTR_VECTOR = '&copy; OpenStreetMap &middot; &copy; OpenFreeMap &middot; &copy; OpenMapTiles';
    var ATTR_CARTO = '&copy; OpenStreetMap &middot; &copy; CARTO';
    var ATTR_ESRI = 'Imagery &copy; Esri';

    // The only two rules MapLibre actually needs for a canvas inside Leaflet's
    // tile pane. Inlining them keeps this feature at zero extra CSS requests
    // (the full maplibre-gl.css is 9KB of controls/popups we never render).
    var cssDone = false;
    function ensureCss() {
        if (cssDone) { return; }
        cssDone = true;
        var s = document.createElement('style');
        s.textContent = '.maplibregl-map{position:relative;overflow:hidden}'
            + '.maplibregl-canvas{position:absolute;left:0;top:0}';
        document.head.appendChild(s);
    }

    var webgl = null;
    function webglOk() {
        if (webgl !== null) { return webgl; }
        webgl = false;
        try {
            var c = document.createElement('canvas');
            webgl = !!(window.WebGLRenderingContext
                && (c.getContext('webgl') || c.getContext('experimental-webgl')));
        } catch (e) { webgl = false; }
        return webgl;
    }

    function vectorOk() {
        return !!(window.maplibregl && L.maplibreGL && webglOk());
    }

    /* ---- raster fallbacks: the exact layers these maps used before ---- */

    function cartoRaster(kind, maxZoom) {
        return L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/' + kind + '/{z}/{x}/{y}{r}.png', {
            maxNativeZoom: 19,
            maxZoom: maxZoom,
            subdomains: 'abcd',
            attribution: ATTR_CARTO
        });
    }

    function esriImagery(maxZoom) {
        return L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            maxNativeZoom: 18,
            maxZoom: maxZoom,
            attribution: ATTR_ESRI
        });
    }

    /* ---- vector style ---- */

    var stylePromise = null;
    function loadStyle() {
        if (!stylePromise) {
            stylePromise = fetch(STYLE_URL, { credentials: 'omit' }).then(function (r) {
                if (!r.ok) { throw new Error('style HTTP ' + r.status); }
                return r.json();
            });
        }
        return stylePromise;
    }

    // Labels-only cut of the same style: keep the symbol layers, drop the fills,
    // lines and the low-zoom relief raster, then drop every source nothing reads
    // any more. Result is transparent, so Esri's imagery shows through.
    function labelsOnlyStyle(style) {
        var out = JSON.parse(JSON.stringify(style));
        out.layers = (out.layers || []).filter(function (l) { return l.type === 'symbol'; });
        var used = {};
        out.layers.forEach(function (l) { if (l.source) { used[l.source] = true; } });
        Object.keys(out.sources || {}).forEach(function (id) {
            if (!used[id]) { delete out.sources[id]; }
        });
        // White text on a dark halo — the street style's dark-grey labels are
        // unreadable over imagery.
        out.layers.forEach(function (l) {
            l.paint = l.paint || {};
            l.paint['text-color'] = '#ffffff';
            l.paint['text-halo-color'] = 'rgba(0,0,0,0.85)';
            l.paint['text-halo-width'] = 1.6;
            l.paint['text-halo-blur'] = 0.4;
        });
        return out;
    }

    function glLayer(style) {
        ensureCss();
        var layer = L.maplibreGL({
            style: style,
            // Leaflet owns the camera and the markers; MapLibre only paints.
            interactive: false,
            // The plugin reads its Leaflet attribution from here.
            attributionControl: { customAttribution: ATTR_VECTOR }
        });
        // Every Leaflet tile layer sits at z-index 1 inside the tile pane and the
        // GL canvas gets none — without this the satellite imagery paints straight
        // over the label layer and the map comes out label-less.
        layer.on('add', function () {
            var box = layer.getContainer();
            if (box) { box.style.zIndex = 2; }
        });
        return layer;
    }

    // Swap the vector layer out for raster if WebGL dies or OpenFreeMap is
    // unreachable — a delivery map with no streets on it is useless. Both
    // basemaps are LayerGroups precisely so this swap needs no help from the
    // page: the layer control keeps pointing at the same group object.
    function armFallback(group, gl, makeRaster) {
        var swapped = false;
        var errors = 0;
        var timer = null;

        function clear() {
            if (timer) { window.clearTimeout(timer); timer = null; }
        }

        function fallback(why) {
            if (swapped) { return; }
            swapped = true;
            clear();
            if (window.console && console.warn) {
                console.warn('[NestPosBasemaps] vector basemap unavailable (' + why + ') — using raster tiles');
            }
            try { group.removeLayer(gl); } catch (e) {}
            makeRaster().forEach(function (layer) { group.addLayer(layer); });
        }

        gl.on('add', function () {
            if (swapped) { return; }
            var glMap = gl.getMaplibreMap();
            if (!glMap) { fallback('no gl context'); return; }
            glMap.on('error', function (e) {
                errors++;
                var msg = (e && e.error && e.error.message) ? e.error.message : '';
                // A broken style/glyphs/sprite is fatal at once; scattered tile
                // errors only count once they add up (one dead tile is normal).
                if (/style|glyph|sprite/i.test(msg) || errors >= 6) { fallback(msg || 'tile errors'); }
            });
            timer = window.setTimeout(function () {
                timer = null;
                if (!glMap.isStyleLoaded || !glMap.isStyleLoaded()) { fallback('style did not load in time'); }
            }, 12000);
        });
        gl.on('remove', clear);
        // Handed back so the caller can also fall back when MapLibre throws
        // outright (a blocked worker, a browser that fails mid-init) instead of
        // letting that exception escape into the page's own map code.
        return fallback;
    }

    /* ---- public API ---- */

    function streets(opts) {
        var maxZoom = (opts && opts.maxZoom) || 21;
        if (!vectorOk()) { return L.layerGroup([cartoRaster('voyager', maxZoom)]); }
        var gl = glLayer(STYLE_URL);
        var group = L.layerGroup();
        var fallback = armFallback(group, gl, function () { return [cartoRaster('voyager', maxZoom)]; });
        try {
            group.addLayer(gl);
        } catch (e) {
            fallback('gl init failed: ' + e);
        }
        return group;
    }

    function satellite(opts) {
        var maxZoom = (opts && opts.maxZoom) || 21;
        var imagery = esriImagery(maxZoom);
        if (!vectorOk()) {
            return L.layerGroup([imagery, cartoRaster('voyager_only_labels', maxZoom)]);
        }
        // Nothing here loads until the shop/customer actually picks satellite,
        // so a streets-only visit stays exactly as fast as before.
        var group = L.layerGroup([imagery]);
        var started = false;
        group.on('add', function () {
            if (started) { return; }
            started = true;
            loadStyle().then(function (style) {
                var gl = glLayer(labelsOnlyStyle(style));
                var fallback = armFallback(group, gl, function () { return [cartoRaster('voyager_only_labels', maxZoom)]; });
                try {
                    group.addLayer(gl);
                } catch (e) {
                    fallback('gl init failed: ' + e);
                }
            }).catch(function (e) {
                if (window.console && console.warn) {
                    console.warn('[NestPosBasemaps] label style failed (' + e + ') — using raster labels');
                }
                group.addLayer(cartoRaster('voyager_only_labels', maxZoom));
            });
        });
        return group;
    }

    /*
     * Draw a delivery line with a broad neutral underlay and a narrow coloured
     * centre. The underlay is the important bit: it preserves contrast on both
     * pale Streets and dark / busy Satellite imagery, while its transparency
     * leaves MapLibre road labels readable. Callers receive a LayerGroup so
     * their existing cleanup logic continues to work unchanged.
     *
     * `late` is intentionally a separate visual vocabulary from `live`: a
     * dashed purple centre says "recorded on the phone, synced later"; it never
     * looks like the teal line that arrived in real time.
     */
    function deliveryTrail(points, opts) {
        opts = opts || {};
        var late = !!opts.late;
        var learned = !!opts.learned;
        var dashed = late || learned || !!opts.dashed;
        var opacity = typeof opts.opacity === 'number' ? opts.opacity : 1;
        var group = L.layerGroup();
        var outer = L.polyline(points, {
            color: late ? '#312e81' : (learned ? '#422006' : '#083344'),
            weight: learned ? 5 : (late ? 7 : 8),
            opacity: (learned ? 0.24 : (late ? 0.36 : 0.30)) * opacity,
            dashArray: learned ? '4 8' : (dashed ? '9 9' : null),
            lineCap: 'round',
            lineJoin: 'round'
        });
        var inner = L.polyline(points, {
            color: late ? '#7c3aed' : (opts.color || (learned ? '#d97706' : '#009b8a')),
            weight: learned ? 2.5 : (late ? 3.5 : 4),
            opacity: (learned ? 0.88 : (late ? 0.96 : 0.94)) * opacity,
            dashArray: learned ? '3 9' : (dashed ? '7 11' : null),
            lineCap: 'round',
            lineJoin: 'round'
        });
        group.addLayer(outer);
        group.addLayer(inner);
        // Trail clicks belong to the visible centre line. Leaflet events do not
        // bubble across sibling layers, so proxy them to the group API callers
        // already use for a single legacy polyline.
        if (typeof opts.onClick === 'function') {
            inner.on('click', opts.onClick);
            outer.on('click', opts.onClick);
        }
        return group;
    }

    window.NestPosBasemaps = {
        isVector: vectorOk,
        streets: streets,
        satellite: satellite,
        deliveryTrail: deliveryTrail,
        styleVersion: 2
    };
})(window, document);
