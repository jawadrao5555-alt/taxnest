#!/usr/bin/env python3
"""Parse Approval Relay Dispatch JSON into TSV rows.

Empty body or {"claims":[]} means nothing to dispatch (poller success).
HTML / non-object payloads fail closed.
"""
from __future__ import annotations

import json
import sys


def rows_from_body(raw: str) -> list[tuple[str, str, str]]:
    text = (raw or "").strip()
    if text == "":
        return []
    try:
        data = json.loads(text)
    except json.JSONDecodeError as exc:
        raise ValueError(f"relay body is not JSON: {exc}") from exc
    if isinstance(data, list):
        claims = data
    elif isinstance(data, dict):
        claims = data.get("claims")
        if claims is None:
            claims = []
        if not isinstance(claims, list):
            raise ValueError("relay claims must be a list")
    else:
        raise ValueError("relay body must be a JSON object or list")

    out: list[tuple[str, str, str]] = []
    for item in claims:
        if not isinstance(item, dict):
            raise ValueError("each claim must be an object")
        request = str(item.get("approval_request_id") or "").strip()
        pull = str(item.get("pull_number") or "").strip()
        sha = str(item.get("expected_head_sha") or "").strip()
        if not request:
            continue
        out.append((request, pull, sha))
    return out


def main(argv: list[str]) -> int:
    path = argv[1] if len(argv) > 1 else "-"
    raw = sys.stdin.read() if path == "-" else open(path, encoding="utf-8").read()
    try:
        rows = rows_from_body(raw)
    except ValueError as exc:
        print(str(exc), file=sys.stderr)
        snippet = (raw or "").strip()[:180]
        if snippet:
            print("body_prefix:", snippet.replace("\n", " "), file=sys.stderr)
        return 1
    for request, pull, sha in rows:
        print(f"{request}\t{pull}\t{sha}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
