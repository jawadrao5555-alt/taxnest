#!/usr/bin/env python3
"""Rebuild the delivery maps' English-first, high-contrast street style.

Why: the owner's rule is that map place names must read in English (Latin).  Raster
basemaps (Carto Voyager, Esri) print whatever the OSM `name` field holds, so a large
share of Pakistani roads and localities come out in Urdu script.  OpenFreeMap serves
vector tiles that carry `name:en` / `name:latin` next to `name`, so we take their
Liberty style, prefer English/Latin labels, and strengthen delivery-level roads.

Run:  python3 tools/maps/build-en-style.py
(Only needed when the upstream style changes — the generated file is committed.)
"""

import copy
import hashlib
import json
import os
import urllib.request

UPSTREAM = "https://tiles.openfreemap.org/styles/liberty"
OUT = os.path.join(os.path.dirname(__file__), "..", "..", "public", "vendor", "maps", "nestpos-en.json")

# name:en (hand-tagged English) -> name:latin (transliteration) -> name (as tagged).
EN_LABEL = ["coalesce", ["get", "name:en"], ["get", "name:latin"], ["get", "name"]]

# Liberty already exposes these OpenMapTiles road classes. Its defaults are
# intentionally quiet, though, so service roads and paths can disappear beneath
# a rider trail on a small phone. These colors preserve the upstream geometry
# while giving each delivery-relevant class a distinct casing and fill.
DELIVERY_ROADS = {
    "road_trunk_primary_casing": {"line-color": "#607887"},
    "road_trunk_primary": {"line-color": "#f6d477"},
    "road_secondary_tertiary_casing": {"line-color": "#7893a2"},
    "road_secondary_tertiary": {"line-color": "#fff0b8"},
    "road_minor_casing": {"line-color": "#a5b6c0"},
    "road_minor": {"line-color": "#ffffff"},
    "road_service_track_casing": {"line-color": "#91a9b6"},
    "road_service_track": {"line-color": "#e8f3f5"},
    "road_path_pedestrian": {"line-color": "#d2eaf0"},
    "bridge_street_casing": {"line-color": "#668392"},
    "bridge_street": {"line-color": "#fffdf2"},
    "bridge_path_pedestrian_casing": {"line-color": "#7194a3"},
    "bridge_path_pedestrian": {"line-color": "#d2eaf0"},
}

ROAD_LABEL_LAYERS = {
    "highway-name-path",
    "highway-name-minor",
    "highway-name-major",
}

POI_LABEL_LAYERS = {"poi_r1", "poi_r7", "poi_r20", "poi_transit"}


def fetch(url):
    req = urllib.request.Request(url, headers={"User-Agent": "NestPOS-style-build/1.0"})
    return json.loads(urllib.request.urlopen(req, timeout=60).read().decode("utf-8"))


def main():
    style = fetch(UPSTREAM)
    upstream_sha256 = hashlib.sha256(
        json.dumps(style, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")
    ).hexdigest()
    available_layers = {layer.get("id") for layer in style.get("layers", [])}
    required_layers = set(DELIVERY_ROADS) | ROAD_LABEL_LAYERS | POI_LABEL_LAYERS
    missing_layers = sorted(required_layers - available_layers)
    if missing_layers:
        raise RuntimeError(
            "OpenFreeMap Liberty changed; refusing to emit a partially patched style. "
            "Missing layers: " + ", ".join(missing_layers)
        )

    patched = 0
    for layer in style.get("layers", []):
        layer_id = layer.get("id", "")
        layout = layer.get("layout") or {}
        text_field = layout.get("text-field")
        if text_field is not None:
            # Road shields read `ref` (M-2, N-5) — already Latin.
            if '"name' in json.dumps(text_field, ensure_ascii=False):
                layout["text-field"] = copy.deepcopy(EN_LABEL)
                patched += 1

        if layer_id in DELIVERY_ROADS:
            layer.setdefault("paint", {}).update(DELIVERY_ROADS[layer_id])

        if layer_id in ROAD_LABEL_LAYERS:
            layer.setdefault("paint", {}).update({
                "text-color": "#173f54",
                "text-halo-color": "rgba(255,255,255,0.96)",
                "text-halo-width": 1.35,
                "text-halo-blur": 0.35,
            })
            # Show named paths one zoom earlier; the tile source still decides
            # whether a name exists, so this invents no labels or road data.
            if layer_id == "highway-name-path":
                layer["minzoom"] = 14

        # POIs are useful orientation cues, but Liberty's existing minzoom and
        # collision rules remain unchanged so they do not smother small lanes.
        if layer_id in POI_LABEL_LAYERS:
            layer.setdefault("paint", {}).update({
                "text-color": "#254b60",
                "text-halo-color": "rgba(255,255,255,0.94)",
                "text-halo-width": 1.1,
                "text-halo-blur": 0.3,
            })

    style["metadata"] = {
        "nestpos:origin": UPSTREAM,
        "nestpos:upstream-sha256": upstream_sha256,
        "nestpos:based-on": "OpenFreeMap Liberty (OSM Liberty, BSD-3) on the OpenMapTiles schema",
        "nestpos:modification": (
            "delivery-focused road hierarchy for primary, residential/minor, service/track "
            "and path/pedestrian classes; stronger road and POI label halos; every name label "
            "forced to name:en -> name:latin -> name"
        ),
        "nestpos:regenerate": "python3 tools/maps/build-en-style.py",
    }

    with open(OUT, "w", encoding="utf-8") as fh:
        json.dump(style, fh, ensure_ascii=False, separators=(",", ":"))
    print("patched %d label layers -> %s (%d bytes)" % (patched, os.path.normpath(OUT), os.path.getsize(OUT)))


if __name__ == "__main__":
    main()
