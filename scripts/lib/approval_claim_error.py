"""Render only the allow-listed deployment claim diagnostic fields."""
import json
import sys


def describe(status, payload):
    status = status if status.isdigit() and len(status) == 3 else "unknown"
    result = "Approval claim refused: HTTP " + status
    if not isinstance(payload, dict):
        return result
    if payload.get("code") == "github_validation_unavailable":
        stage = payload.get("stage")
        stage = stage if stage in ("pull_request", "main_tip", "check_runs") else "unknown"
        upstream = payload.get("upstream_status")
        upstream = str(upstream) if type(upstream) is int and 100 <= upstream <= 599 else "connection failure"
        result += "; GitHub " + stage + " = " + upstream
        delay = payload.get("retry_after")
        if type(delay) is int and 30 <= delay <= 3600:
            result += "; retry after " + str(delay) + " seconds"
    elif payload.get("code") == "deployment_ineligible":
        result += "; exact PR/SHA/CI eligibility rejected"
    return result


if __name__ == "__main__":
    try:
        with open(sys.argv[2], encoding="utf-8") as source:
            payload = json.load(source)
    except (ValueError, OSError):
        payload = None
    print(describe(sys.argv[1], payload))
