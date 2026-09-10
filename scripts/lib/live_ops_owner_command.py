#!/usr/bin/env python3
"""Allow-listed owner-command parser for GitHub Actions (pre-SSH guard).

Mirrors App\\Services\\LiveOps\\LiveOpsOwnerCommandParser fail-closed rules.
Never interpolates the owner text into a shell command.
"""
from __future__ import annotations

import json
import re
import sys

ALLOWED_OPS = {
    "DAILY_OPS",
    "SERVER_HEALTH",
    "COMPANY_HEALTH",
    "BILLING_SUMMARY",
    "BILLING_BY_COMPANY",
    "PRA_HEALTH",
    "AGENT_HEALTH",
    "PRINTER_HEALTH",
    "ERROR_SUMMARY",
    "COMPANY_DIAGNOSTIC",
    "PROBLEMATIC_COMPANIES",
}

COMPANY_OPS = {"COMPANY_HEALTH", "PRINTER_HEALTH", "COMPANY_DIAGNOSTIC"}
PREFIX = "[TAXNEST-OPS]"
BLOCKED = (
    "deploy-production",
    "skip_elaan",
    "allow_settings",
    "production_ssh",
    "private_key",
    "rm -rf",
    "drop table",
    "truncate ",
    "workflow_dispatch",
    "gh workflow",
    "curl |",
    "bash -c",
    "/bin/sh",
    "ssh ",
    "scp ",
    "live-ops-remediate.yml",
    "owner-merge-and-deploy.yml",
)
UNSAFE_CHARS = re.compile(r"[;&|`$<>]|\$\(")


def fail(original: str, error: str) -> dict:
    return {
        "ok": False,
        "intent": None,
        "operation": None,
        "company_name": None,
        "date_from": None,
        "date_to": None,
        "focus": None,
        "error": error,
        "owner_text": original,
    }


def ok(original, intent, operation, company_name=None, date_from=None, date_to=None, focus=None) -> dict:
    if operation not in ALLOWED_OPS:
        return fail(original, "Disallowed operation.")
    return {
        "ok": True,
        "intent": intent,
        "operation": operation,
        "company_name": company_name or None,
        "date_from": date_from,
        "date_to": date_to,
        "focus": focus,
        "error": None,
        "owner_text": original,
    }


def strip_prefix(text: str) -> str:
    if text.upper().startswith(PREFIX.upper()):
        return text[len(PREFIX) :].strip()
    return text


def is_unsafe(text: str) -> bool:
    lower = text.lower()
    if any(n in lower for n in BLOCKED):
        return True
    if UNSAFE_CHARS.search(text):
        return True
    return False


def extract_dates(lower: str):
    m = re.search(r"\b(\d{4}-\d{2}-\d{2})\s+(?:to|se|-)\s+(\d{4}-\d{2}-\d{2})\b", lower)
    if m:
        return m.group(1), m.group(2)
    m = re.search(r"\b(\d{4}-\d{2}-\d{2})\b", lower)
    if m:
        return m.group(1), m.group(1)
    return None, None


def clean_company(token: str) -> str:
    token = re.sub(r"^(please|pls|kindly)\s+", "", token.strip(), flags=re.I)
    token = re.sub(r"\b(ka|ki|ke|the|printing|print|issue|problem|ko)\b", " ", token, flags=re.I)
    return re.sub(r"\s+", " ", token).strip()


def parse(text: str) -> dict:
    original = text.strip()
    collapsed = re.sub(r"\s+", " ", strip_prefix(original)).strip()
    if not collapsed or len(collapsed) > 500:
        return fail(original, "Command is empty or too long.")
    if is_unsafe(collapsed):
        return fail(original, "Command rejected: unauthorized or unsafe operation.")
    lower = collapsed.lower()
    date_from, date_to = extract_dates(lower)

    has_fleet = any(s in lower for s in ("sab companies", "all companies", "saari companies", "every company"))
    has_solve = "solve" in lower or "jahan issue" in lower or "jahaan issue" in lower or re.search(r"\bfix\b", lower)
    if has_fleet and has_solve:
        return ok(original, "FLEET_CHECK_AND_SOLVE", "DAILY_OPS", None, date_from, date_to, "fleet")
    if has_fleet:
        return ok(original, "DAILY_REPORT", "DAILY_OPS", None, date_from, date_to, "fleet")

    if any(
        s in lower
        for s in (
            "aaj ki report",
            "aaj ki reporting",
            "today's report",
            "todays report",
            "today report",
            "daily report",
            "daily ops",
            "daily-ops",
        )
    ) or lower in ("report do", "aaj report do"):
        return ok(original, "DAILY_REPORT", "DAILY_OPS", None, date_from, date_to)

    as_op = collapsed.upper().replace(" ", "_").replace("-", "_")
    if as_op in ALLOWED_OPS:
        if as_op in COMPANY_OPS:
            return fail(original, as_op + " needs a company name (example: Pizza Master check karo).")
        return ok(original, "DIAGNOSTIC", as_op, None, date_from, date_to)

    m = re.search(r"^(.+?)\s+ka\s+(?:printing\s+)?(?:issue|problem).*\b(solve|fix)", collapsed, re.I)
    if m:
        return ok(original, "COMPANY_SOLVE", "COMPANY_DIAGNOSTIC", clean_company(m.group(1)), date_from, date_to, "printing" if "print" in lower else None)
    m = re.search(r"^(.+?)\s+check\s+karo\b", collapsed, re.I)
    if m:
        return ok(original, "COMPANY_CHECK", "COMPANY_DIAGNOSTIC", clean_company(m.group(1)), date_from, date_to)

    return fail(
        original,
        'Unrecognized command. Try: "Aaj ki report do", "Pizza Master check karo", "ZFC ka printing issue solve karo", or "Sab companies check karo aur jahan issue ho solve karo".',
    )


def main(argv: list[str]) -> int:
    if len(argv) < 2 or argv[1] in ("-h", "--help"):
        print("usage: live_ops_owner_command.py <text>", file=sys.stderr)
        return 2
    result = parse(argv[1])
    print(json.dumps(result, ensure_ascii=False))
    return 0 if result["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
