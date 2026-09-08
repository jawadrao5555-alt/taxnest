#!/usr/bin/env python3
"""Qualify Elaan titles with the exact deploy SHA.

Deploy Production #15 inserted deploy/elaan.yml by exact human title. That
title already existed as AppUpdate #265 (from SHA aecc864d). Insert was a
successful idempotent no-op (no duplicate, not re-dated). The NEW-SHA
freshness gate then failed because #265 was not created after the last
deploy marker.

CI therefore publishes:
    {base_title} [deploy {40-char lowercase sha}]
so a new TARGET_SHA always gets a distinct title (new row, time-fresh)
while a same-SHA retry hits the same qualified title (ELAAN_EXISTS, no
re-date, no duplicate).

app_updates.title is VARCHAR(150). The suffix is 50 chars; the base is
truncated if needed. Never re-dates. Never uses skip_elaan.
"""
from __future__ import annotations

import argparse
import re
import sys

SHA_RE = re.compile(r"^[0-9a-f]{40}$")
SUFFIX_RE = re.compile(r"\s*\[deploy [0-9a-f]{40}\]\s*$", re.IGNORECASE)
RESERVED = "Daily L001 ke liye roz Reset dabana zaroori nahi"
TITLE_MAX = 150
SUFFIX_FMT = " [deploy {sha}]"


def normalize_sha(sha: str) -> str:
    s = (sha or "").strip().lower()
    if not SHA_RE.fullmatch(s):
        raise ValueError("deploy SHA must be a full 40-char lowercase hex commit")
    return s


def strip_deploy_suffix(title: str) -> str:
    return SUFFIX_RE.sub("", title or "").strip()


def qualify_title(title: str, sha: str) -> str:
    sha = normalize_sha(sha)
    base = strip_deploy_suffix(title)
    if not base:
        raise ValueError("Elaan title is empty")
    if base == RESERVED or title.strip() == RESERVED:
        raise ValueError("reserved Daily L001 title — will not qualify, insert, or re-date")
    suffix = SUFFIX_FMT.format(sha=sha)
    max_base = TITLE_MAX - len(suffix)
    if max_base < 8:
        raise ValueError("deploy SHA suffix cannot fit in app_updates.title")
    if len(base) > max_base:
        base = base[:max_base].rstrip()
    published = f"{base}{suffix}"
    if len(published) > TITLE_MAX:
        raise ValueError("qualified Elaan title exceeds app_updates.title(150)")
    return published


def main(argv: list[str]) -> int:
    p = argparse.ArgumentParser(description="Qualify or strip Elaan deploy-SHA titles")
    sub = p.add_subparsers(dest="cmd", required=True)
    q = sub.add_parser("qualify", help="append [deploy <sha>] (idempotent)")
    q.add_argument("--title", required=True)
    q.add_argument("--sha", required=True)
    s = sub.add_parser("strip", help="remove a trailing [deploy sha] suffix")
    s.add_argument("--title", required=True)
    args = p.parse_args(argv[1:])
    try:
        if args.cmd == "qualify":
            sys.stdout.write(qualify_title(args.title, args.sha) + "\n")
        else:
            sys.stdout.write(strip_deploy_suffix(args.title) + "\n")
    except ValueError as e:
        print(f"ELAAN TITLE ERROR: {e}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
