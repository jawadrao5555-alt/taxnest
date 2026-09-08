#!/usr/bin/env python3
"""Select Deploy Production run IDs that are safe to cancel.

Only status == "waiting" (GitHub Environment approval wait).
Never select in_progress, queued, pending, requested, or completed.
Never select the current run.
"""
from __future__ import annotations

import json
import sys


ALLOWED_CANCEL_STATUS = frozenset({"waiting"})


def select_cancel_ids(payload: dict, current_run_id: str) -> list[int]:
    current = str(current_run_id or "").strip()
    ids: list[int] = []
    for run in payload.get("workflow_runs") or []:
        run_id = run.get("id")
        status = str(run.get("status") or "")
        if run_id is None:
            continue
        if str(run_id) == current:
            continue
        if status not in ALLOWED_CANCEL_STATUS:
            continue
        ids.append(int(run_id))
    return ids


def main(argv: list[str]) -> int:
    if len(argv) != 3:
        print(
            "usage: select-stale-waiting-deploy-runs.py <runs.json> <current_run_id>",
            file=sys.stderr,
        )
        return 2
    path, current = argv[1], argv[2]
    with open(path, encoding="utf-8") as fh:
        payload = json.load(fh)
    for run_id in select_cancel_ids(payload, current):
        print(run_id)
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
