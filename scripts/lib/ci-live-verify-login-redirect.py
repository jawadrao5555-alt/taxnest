#!/usr/bin/env python3
"""Classify NestPOS login POST redirect Location for CI live verify.

Secret-free: callers must pass only the Location URL/path, never cookies,
tokens, or passwords. Used by scripts/ci-live-verify.sh so a 302 back to
/pos/login is reported as login failure, not confused with a later dashboard
marker problem.
"""
from __future__ import annotations

import json
import sys
from urllib.parse import urlparse


def _path_of(location: str, live_url: str) -> str:
    loc = (location or "").strip()
    if not loc:
        return ""
    if loc.startswith("/"):
        # Relative Location — drop query/fragment for classification.
        return loc.split("?", 1)[0].split("#", 1)[0]
    parsed = urlparse(loc)
    live = urlparse(live_url if "://" in live_url else f"https://{live_url}")
    # Absolute URL: only accept same host as LIVE_URL (or empty netloc edge).
    if parsed.netloc and live.netloc and parsed.netloc.lower() != live.netloc.lower():
        return f"//foreign/{parsed.netloc}{parsed.path or '/'}"
    path = parsed.path or "/"
    return path.split("?", 1)[0].split("#", 1)[0]


def classify(location: str, live_url: str = "https://taxnest.pk") -> dict:
    path = _path_of(location, live_url)
    if not path:
        return {
            "decision": "FAIL",
            "reason": "missing_location",
            "path": "",
            "message": "NestPOS login failed: login POST returned 302 without a Location header.",
        }

    # Normalize trailing slash except root.
    norm = path.rstrip("/") or "/"
    if norm == "/pos/login" or norm.startswith("/pos/login/"):
        return {
            "decision": "FAIL",
            "reason": "back_to_login",
            "path": path,
            "message": "NestPOS login failed: login POST redirected back to /pos/login.",
        }

    if norm.startswith("/admin"):
        return {
            "decision": "FAIL",
            "reason": "wrong_portal_admin",
            "path": path,
            "message": f"NestPOS login failed: login POST redirected to admin portal ({path}), not NestPOS.",
        }

    if norm.startswith("/fbr-pos"):
        return {
            "decision": "FAIL",
            "reason": "wrong_portal_fbr",
            "path": path,
            "message": f"NestPOS login failed: login POST redirected to FBR POS ({path}), not NestPOS.",
        }

    if path.startswith("//foreign/"):
        return {
            "decision": "FAIL",
            "reason": "foreign_host",
            "path": path,
            "message": "NestPOS login failed: login POST redirected to a foreign host.",
        }

    # Successful NestPOS auth lands on a /pos/* portal (invoice/create, dashboard,
    # confined portals, etc.). /pos/login already rejected above.
    if norm == "/pos" or norm.startswith("/pos/"):
        return {
            "decision": "OK",
            "reason": "pos_authenticated_route",
            "path": path,
            "message": f"NestPOS login redirect Location is authenticated route {path}.",
        }

    return {
        "decision": "FAIL",
        "reason": "unexpected_location",
        "path": path,
        "message": f"NestPOS login failed: login POST redirected to unexpected Location path {path}.",
    }


def main(argv: list[str]) -> int:
    if len(argv) < 2 or argv[1] in ("-h", "--help"):
        print(
            "Usage: ci-live-verify-login-redirect.py '<location>' [live_url]\n"
            "Prints JSON {decision,reason,path,message}. Exit 0 only when OK.",
            file=sys.stderr,
        )
        return 2
    location = argv[1]
    live_url = argv[2] if len(argv) > 2 else "https://taxnest.pk"
    result = classify(location, live_url)
    json.dump(result, sys.stdout, ensure_ascii=False)
    sys.stdout.write("\n")
    return 0 if result["decision"] == "OK" else 1


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
