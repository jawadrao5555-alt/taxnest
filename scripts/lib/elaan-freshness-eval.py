#!/usr/bin/env python3
"""Decide Elaan freshness for NEW-SHA vs SAME-SHA deploy reruns.

Pure decision helper — no SSH, no DB, no secrets. Used by:
  - scripts/lib/elaan-freshness-check.sh (CI + manual deploy gate)
  - scripts/tests/elaan-same-sha-freshness-check.sh

Rules:
  - Time-fresh published pos/all rows (created_at > marker) always PASS
    for both new and same SHA.
  - SAME-SHA rerun (live HEAD == target SHA == marker commit) may PASS
    only when the committed deploy/elaan.yml title still exists as a
    published pos/all AppUpdate. Unrelated old announcements do NOT count.
  - NEW SHA with no time-fresh row FAIL (unchanged).
  - ELAAN_EXISTS alone is never treated as fresh.
"""
from __future__ import annotations

import json
import sys


def evaluate(
    *,
    live_head: str,
    target_sha: str,
    marker_commit: str,
    time_fresh_count: int,
    committed_title: str | None,
    title_match_count: int,
) -> dict:
    live_head = (live_head or "").strip().lower()
    target_sha = (target_sha or "").strip().lower()
    marker_commit = (marker_commit or "").strip().lower()
    title = (committed_title or "").strip()

    if time_fresh_count < 0 or title_match_count < 0:
        return {
            "decision": "FAIL",
            "reason": "invalid_counts",
            "same_sha": False,
        }

    if time_fresh_count >= 1:
        return {
            "decision": "PASS",
            "reason": "time_fresh",
            "same_sha": False,
        }

    same_sha = (
        bool(live_head)
        and bool(target_sha)
        and live_head == target_sha
        and marker_commit == target_sha
    )
    if not same_sha:
        return {
            "decision": "FAIL",
            "reason": "new_sha_missing_fresh",
            "same_sha": False,
        }

    if not title:
        return {
            "decision": "FAIL",
            "reason": "same_sha_no_committed_title",
            "same_sha": True,
        }

    if title_match_count >= 1:
        return {
            "decision": "PASS",
            "reason": "same_sha_original_title",
            "same_sha": True,
        }

    return {
        "decision": "FAIL",
        "reason": "same_sha_title_missing",
        "same_sha": True,
    }


def main(argv: list[str]) -> int:
    if len(argv) != 2 or argv[1] in ("-h", "--help"):
        print(
            "Usage: elaan-freshness-eval.py '<json>'\n"
            "JSON keys: live_head, target_sha, marker_commit, time_fresh_count,\n"
            "           committed_title (nullable), title_match_count",
            file=sys.stderr,
        )
        return 2
    try:
        data = json.loads(argv[1])
    except json.JSONDecodeError as e:
        print(f"invalid json: {e}", file=sys.stderr)
        return 2
    result = evaluate(
        live_head=str(data.get("live_head") or ""),
        target_sha=str(data.get("target_sha") or ""),
        marker_commit=str(data.get("marker_commit") or ""),
        time_fresh_count=int(data.get("time_fresh_count") or 0),
        committed_title=data.get("committed_title"),
        title_match_count=int(data.get("title_match_count") or 0),
    )
    json.dump(result, sys.stdout, ensure_ascii=False)
    sys.stdout.write("\n")
    return 0 if result["decision"] == "PASS" else 1


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
