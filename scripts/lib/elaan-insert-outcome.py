#!/usr/bin/env python3
"""Classify Elaan insert PHP/SSH output on the CI runner (never talks to live DB).

Deploy Production #4 failed because the live wrapper treated any non-zero
ssh/php status as "PHP bootstrap failed on live" even when the streamed PHP
had already decided the exact title exists and refused to duplicate or re-date.

Outcomes:
  exists   — exact title already in app_updates; successful idempotent no-op
  inserted — a new row was created
  fail     — reserved L001, missing markers, or a genuine bootstrap/SQL error
"""
from __future__ import annotations

import argparse
import sys


def classify(rc: int, text: str) -> str:
    t = text or ""
    # Reserved Daily L001 is never a no-op success, even if other markers appear.
    if "reserved Daily L001" in t:
        return "fail"
    if "ELAAN_EXISTS" in t:
        return "exists"
    # Pre-PR-#7 streamed PHP: fwrite(STDERR) + exit(1). The title already exists
    # and the script did not insert or update — treat as success so CI can
    # continue to the freshness gate and exact-SHA apply.
    if "title already exists" in t and "will not duplicate" in t:
        return "exists"
    if "ELAAN_INSERTED" in t:
        return "inserted"
    _ = rc  # rc alone is not sufficient: existing-title used to exit 1
    return "fail"


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Classify Elaan insert run output")
    parser.add_argument("--rc", type=int, required=True)
    parser.add_argument("--text", default=None)
    parser.add_argument("--text-from-stdin", action="store_true")
    args = parser.parse_args(argv)
    if args.text_from_stdin:
        text = sys.stdin.read()
    elif args.text is not None:
        text = args.text
    else:
        text = ""
    print(classify(args.rc, text))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
