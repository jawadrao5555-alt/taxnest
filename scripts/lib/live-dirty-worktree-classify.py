#!/usr/bin/env python3
"""Classify live worktree dirtiness for TaxNest production deploys.

Does not mutate git. Used by scripts/lib/live-dirty-worktree.sh on the
Actions runner / deploy host (not on the VPS).

Exit codes:
  0  CLEAN or EXPECTED_SW_STAMP — deploy may continue
  2  unexpected tracked dirt — fail closed
  3  only public/sw.js is dirty and the caller must supply git diff HEAD
  1  usage / IO error — fail closed

PR #28 (aecc864d) stamps public/sw.js CACHE_VERSION on live after exact-SHA
checkout (working tree only, never committed). The next deploy's preflight
used to treat that leftover stamp as a generic dirty tree and abort.
This classifier allows that stamp and nothing else.
"""
from __future__ import annotations

import argparse
import re
import sys

EXPECTED_PATH = "public/sw.js"

# live-remote-apply.sh:
#   SW_STAMP="taxnest-$(date -u +%Y%m%d)-$(printf '%s' "$CURRENT" | cut -c1-8)"
#   sed replaces the whole CACHE_VERSION line, including the comment.
STAMP_PLUS_RE = re.compile(
    r"^const CACHE_VERSION = 'taxnest-[0-9]{8}-[0-9a-fA-F]{8}'; "
    r"// stamped on live by live-remote-apply\.sh from the deployed SHA$"
)
CACHE_LINE_RE = re.compile(r"^const CACHE_VERSION = '[^']*';.*$")


def tracked_paths(porcelain: str) -> list[str]:
    """Return unique tracked dirty paths from `git status --porcelain` (v1).

    Untracked (??) lines are ignored. Renames, copies, and unmerged entries
    are returned as the raw remainder so they cannot look like a lone sw.js.
    """
    paths: list[str] = []
    seen: set[str] = set()
    for raw in porcelain.splitlines():
        line = raw.rstrip("\r")
        if not line.strip():
            continue
        if line.startswith("??"):
            continue
        if len(line) < 4:
            token = line.strip() or "UNKNOWN"
        elif " -> " in line:
            token = line[3:]
        else:
            token = line[3:]
        if token not in seen:
            seen.add(token)
            paths.append(token)
    return paths


def _diff_content_lines(diff_text: str) -> tuple[list[str], list[str]]:
    minus: list[str] = []
    plus: list[str] = []
    for raw in diff_text.splitlines():
        line = raw.rstrip("\r")
        if line.startswith("+++") or line.startswith("---"):
            continue
        if line.startswith("diff ") or line.startswith("index ") or line.startswith("@@"):
            continue
        if line.startswith("\\"):
            # "\ No newline at end of file" — not a stamp-only CACHE_VERSION edit.
            minus.append(line)
            continue
        if line.startswith("+"):
            plus.append(line[1:].rstrip())
        elif line.startswith("-"):
            minus.append(line[1:].rstrip())
    return minus, plus


def is_expected_stamp_diff(diff_text: str) -> bool:
    """True only when the unified diff changes solely the CACHE_VERSION stamp line."""
    if not (diff_text or "").strip():
        return False
    minus, plus = _diff_content_lines(diff_text)
    if len(minus) != 1 or len(plus) != 1:
        return False
    if not CACHE_LINE_RE.fullmatch(minus[0]):
        return False
    if not STAMP_PLUS_RE.fullmatch(plus[0]):
        return False
    return True


def classify(porcelain: str, sw_diff: str | None) -> tuple[int, str]:
    paths = tracked_paths(porcelain)
    if not paths:
        return 0, "CLEAN"
    if paths != [EXPECTED_PATH]:
        return 2, "UNEXPECTED_TRACKED"
    if sw_diff is None or not str(sw_diff).strip():
        return 3, "NEED_SW_DIFF"
    if is_expected_stamp_diff(sw_diff):
        return 0, "EXPECTED_SW_STAMP"
    return 2, "UNEXPECTED_SW_JS"


def main(argv: list[str]) -> int:
    p = argparse.ArgumentParser(description="Classify live dirty worktree (no git mutation)")
    p.add_argument("--porcelain-file", required=True, help="git status --porcelain output; - = stdin")
    p.add_argument("--sw-diff-file", default=None, help="git diff HEAD -- public/sw.js; - = stdin")
    args = p.parse_args(argv[1:])

    if args.porcelain_file == "-" and args.sw_diff_file == "-":
        print("ERROR: porcelain and sw-diff cannot both be stdin", file=sys.stderr)
        return 1

    try:
        if args.porcelain_file == "-":
            porcelain = sys.stdin.read()
        else:
            with open(args.porcelain_file, encoding="utf-8", errors="replace") as fh:
                porcelain = fh.read()
        sw_diff: str | None
        if args.sw_diff_file is None:
            sw_diff = None
        elif args.sw_diff_file == "-":
            sw_diff = sys.stdin.read()
        else:
            with open(args.sw_diff_file, encoding="utf-8", errors="replace") as fh:
                sw_diff = fh.read()
    except OSError as e:
        print(f"ERROR: {e}", file=sys.stderr)
        return 1

    code, verdict = classify(porcelain, sw_diff)
    sys.stdout.write(verdict + "\n")
    if code == 2 and verdict == "UNEXPECTED_TRACKED":
        paths = tracked_paths(porcelain)
        print("tracked dirty: " + ", ".join(paths), file=sys.stderr)
    elif code == 2 and verdict == "UNEXPECTED_SW_JS":
        print(
            "public/sw.js is dirty but the diff is not solely the live-remote-apply "
            "CACHE_VERSION stamp. Owner/ops must inspect; not discarded.",
            file=sys.stderr,
        )
    return code


if __name__ == "__main__":
    sys.exit(main(sys.argv))
