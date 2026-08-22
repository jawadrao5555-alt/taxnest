#!/usr/bin/env python3
"""Rebuild public/vendor/maps/nestpos-en.json — the delivery maps' English label style.

Why: the owner's rule is that map place names must read in English (Latin).  Raster
basemaps (Carto Voyager, Esri) print whatever the OSM `name` field holds, so a large
share of Pakistani roads and localities come out in Urdu script.  OpenFreeMap serves
vector tiles that carry `name:en` / `name:latin` next to `name`, so we take their
Liberty style and force EVERY name label to prefer the English/Latin field.

Run:  python3 tools/maps/build-en-style.py
(Only needed when the upstream style changes — the generated file is committed.)
"""

import copy
import json
import os
import urllib.request

UPSTREAM = "https://tiles.openfreemap.org/styles/liberty"
OUT = os.path.join(os.path.dirname(__file__), "..", "..", "public", "vendor", "maps", "nestpos-en.json")

# name:en (hand-tagged English) -> name:latin (transliteration) -> name (as tagged).
EN_LABEL = ["coalesce", ["get", "name:en"], ["get", "name:latin"], ["get", "name"]]


def fetch(url):
    req = urllib.request.Request(url, headers={"User-Agent": "NestPOS-style-build/1.0"})
    return json.loads(urllib.request.urlopen(req, timeout=60).read().decode("utf-8"))


def main():
    style = fetch(UPSTREAM)

    patched = 0
    for layer in style.get("layers", []):
        layout = layer.get("layout") or {}
        text_field = layout.get("text-field")
        if text_field is None:
            continue
        # Road shields read `ref` (M-2, N-5) — those are already Latin, leave them alone.
        if '"name' not in json.dumps(text_field, ensure_ascii=False):
            continue
        layout["text-field"] = copy.deepcopy(EN_LABEL)
        patched += 1

    style["metadata"] = {
        "nestpos:origin": UPSTREAM,
        "nestpos:based-on": "OpenFreeMap Liberty (OSM Liberty, BSD-3) on the OpenMapTiles schema",
        "nestpos:modification": (
            "every name label forced to name:en -> name:latin -> name, so Pakistani roads "
            "and localities read in English (Latin) instead of Urdu script"
        ),
        "nestpos:regenerate": "python3 tools/maps/build-en-style.py",
    }

    with open(OUT, "w", encoding="utf-8") as fh:
        json.dump(style, fh, ensure_ascii=False, separators=(",", ":"))
    print("patched %d label layers -> %s (%d bytes)" % (patched, os.path.normpath(OUT), os.path.getsize(OUT)))


if __name__ == "__main__":
    main()
