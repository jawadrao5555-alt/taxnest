#!/usr/bin/env python3
"""Classify a PR file list for TaxNest ordinary-safe auto-deploy. Deny wins."""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ALLOW = [
    "resources/views/",
    "resources/css/",
    "public/js/",
    "public/css/",
    "pra-agent/src/",
    "pra-agent/test/",
    "agent-realtime-gateway/src/",
    "app/Services/LiveOps/",
    "app/Http/Controllers/Api/LiveOpsRunnerController.php",
    "app/Console/Commands/LiveOps",
    "config/live_ops.php",
    "tests/Feature/LiveOps/",
    "tests/Feature/PosPrint",
    "scripts/cloud-live-ops",
    "scripts/ci-live-ops",
    "scripts/lib/live_ops_",
    "scripts/tests/live-ops-",
    "docs/ops/live-ops",
]
DENY = [
    "database/migrations/",
    "app/Services/PosTaxMath.php",
    "app/Services/Tax",
    "app/Http/Middleware/AgentAuth.php",
    "app/Http/Middleware/Authenticate.php",
    "app/Http/Middleware/Company",
    ".env",
    ".github/workflows/deploy-production.yml",
    ".github/workflows/owner-merge-and-deploy.yml",
    "scripts/deploy-live.sh",
    "scripts/owner-merge-and-deploy.sh",
    "scripts/lib/owner-merge-and-deploy.py",
]


def matches(path: str, prefix: str) -> bool:
    path = path.lstrip("/")
    prefix = prefix.lstrip("/")
    if prefix.endswith("/"):
        return path == prefix[:-1] or path.startswith(prefix)
    return path == prefix or path.startswith(prefix)


def classify(paths: list[str]) -> dict:
    blocked, allowed = [], []
    for raw in paths:
        path = raw.replace("\\", "/").lstrip("/")
        if not path or path == "docs" or path.startswith("docs/"):
            continue
        if any(matches(path, d) for d in DENY):
            blocked.append(path)
            continue
        if any(matches(path, a) for a in ALLOW):
            allowed.append(path)
            continue
        blocked.append(path)
    if blocked:
        return {
            "class": "HIGH_RISK_BLOCKED",
            "reason": "High-risk or unclassified path(s): " + ", ".join(blocked[:8]),
            "blocked_paths": blocked,
            "allowed_paths": allowed,
        }
    if not allowed:
        return {
            "class": "HIGH_RISK_BLOCKED",
            "reason": "No classifiable application files in the change set.",
            "blocked_paths": [],
            "allowed_paths": [],
        }
    return {
        "class": "AUTO_DEPLOY",
        "reason": "All changed files are ordinary safe application paths.",
        "blocked_paths": [],
        "allowed_paths": allowed,
    }


def main(argv: list[str]) -> int:
    if len(argv) >= 2 and argv[1] not in ("-h", "--help"):
        paths = argv[1:]
    else:
        paths = [ln.strip() for ln in sys.stdin if ln.strip()]
    result = classify(paths)
    print(json.dumps(result, ensure_ascii=False))
    return 0 if result["class"] == "AUTO_DEPLOY" else 1


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
