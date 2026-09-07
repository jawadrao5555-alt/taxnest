#!/usr/bin/env python3
"""Parse the committed TaxNest Elaan spec (restricted YAML).

Used by scripts/elaan-insert.sh --from-file so GitHub Actions can insert an
AppUpdate on live without Cursor writing to production, and without PyYAML
on the runner (stdlib only).

Schema:
  title: string (required)
  audience: pos | fbr_pos | all  (default pos)
  body: string (optional; used as a point if points is empty)
  points: list of strings (required unless body is set)
  category: string (optional singular)
  categories: list of strings (optional)

Refuses the reserved Daily L001 title so CI cannot re-create or re-date it.
"""
from __future__ import annotations

import json
import re
import shlex
import sys

RESERVED_TITLES = frozenset(
    {
        "Daily L001 ke liye roz Reset dabana zaroori nahi",
    }
)
AUDIENCES = frozenset({"pos", "fbr_pos", "all"})
KEY_RE = re.compile(r"^([A-Za-z_][A-Za-z0-9_]*)\s*:\s*(.*?)\s*$")


def _unquote(raw: str) -> str:
    s = raw.strip()
    if len(s) >= 2 and s[0] == s[-1] and s[0] in ("'", '"'):
        return s[1:-1]
    return s


def parse_restricted_yaml(text: str) -> dict:
    data: dict = {}
    current_list: str | None = None
    for lineno, raw_line in enumerate(text.splitlines(), 1):
        if raw_line.strip() == "" or raw_line.lstrip().startswith("#"):
            continue
        if raw_line.startswith(" ") or raw_line.startswith("\t"):
            if current_list is None:
                raise ValueError(f"line {lineno}: indented line with no list key")
            stripped = raw_line.strip()
            if not stripped.startswith("- "):
                raise ValueError(f"line {lineno}: list items must start with '- '")
            item = _unquote(stripped[2:])
            if item == "":
                raise ValueError(f"line {lineno}: empty list item")
            data.setdefault(current_list, []).append(item)
            continue
        m = KEY_RE.match(raw_line)
        if not m:
            raise ValueError(f"line {lineno}: expected 'key: value'")
        key, rest = m.group(1), m.group(2)
        if key not in (
            "title",
            "audience",
            "body",
            "points",
            "category",
            "categories",
            "type",
        ):
            raise ValueError(f"line {lineno}: unknown key '{key}'")
        current_list = None
        if rest == "":
            if key not in ("points", "categories"):
                raise ValueError(f"line {lineno}: '{key}' cannot be an empty nested key")
            data[key] = []
            current_list = key
            continue
        data[key] = _unquote(rest)

    title = str(data.get("title") or "").strip()
    if not title:
        raise ValueError("title is required")
    if title in RESERVED_TITLES:
        raise ValueError(
            "refusing reserved Elaan title (will not re-create or re-date "
            "the Daily L001 announcement) — pick a new unique title"
        )

    audience = str(data.get("audience") or "pos").strip()
    if audience not in AUDIENCES:
        raise ValueError("audience must be pos, fbr_pos, or all")

    points: list[str] = []
    if isinstance(data.get("points"), list):
        points.extend(str(p).strip() for p in data["points"] if str(p).strip())
    body = str(data.get("body") or "").strip()
    if body and not points:
        points.append(body)
    elif body:
        points.insert(0, body)
    if not points:
        raise ValueError("at least one point (or body) is required")

    categories: list[str] = []
    if isinstance(data.get("categories"), list):
        categories.extend(str(c).strip() for c in data["categories"] if str(c).strip())
    cat = str(data.get("category") or "").strip()
    if cat:
        categories.append(cat)
    # unique, preserve order
    seen = set()
    uniq = []
    for c in categories:
        if c not in seen:
            seen.add(c)
            uniq.append(c)

    spec_type = str(data.get("type") or "").strip()
    if spec_type and spec_type not in ("feature", "improvement"):
        raise ValueError("type must be feature or improvement")

    return {
        "title": title,
        "audience": audience,
        "points": points,
        "categories": uniq,
        "type": spec_type or "improvement",
    }


def emit_bash(spec: dict) -> str:
    lines = [
        f"TITLE={shlex.quote(spec['title'])}",
        f"AUDIENCE={shlex.quote(spec['audience'])}",
        f"ELAAN_TYPE={shlex.quote(spec['type'])}",
    ]
    for p in spec["points"]:
        lines.append(f"POINTS+=({shlex.quote(p)})")
    for c in spec["categories"]:
        lines.append(f"CATEGORIES+=({shlex.quote(c)})")
    return "\n".join(lines) + "\n"


def main(argv: list[str]) -> int:
    bash = False
    args = argv[1:]
    if args and args[0] == "--bash":
        bash = True
        args = args[1:]
    if len(args) != 1:
        print("Usage: elaan-spec-parse.py [--bash] <deploy/elaan.yml>", file=sys.stderr)
        return 2
    path = args[0]
    try:
        with open(path, encoding="utf-8") as f:
            spec = parse_restricted_yaml(f.read())
    except (OSError, ValueError) as e:
        print(f"ELAAN SPEC INVALID: {e}", file=sys.stderr)
        return 1
    if bash:
        sys.stdout.write(emit_bash(spec))
    else:
        json.dump(spec, sys.stdout, ensure_ascii=False)
        sys.stdout.write("\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
