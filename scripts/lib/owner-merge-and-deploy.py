#!/usr/bin/env python3
"""Decide whether an owner-triggered merge+deploy may proceed.

Pure function + CLI. Does NOT call GitHub, SSH, or read secrets.
Usage:
  python3 scripts/lib/owner-merge-and-deploy.py --input-json payload.json
  python3 scripts/lib/owner-merge-and-deploy.py --self-test
Exit 0 with JSON on stdout for allow/noop; exit 2 with JSON for reject.
"""
from __future__ import annotations

import json
import re
import sys

CONFIRM_PHRASE = "Approved — Merge & Deploy"
VALIDATE_CHECK_NAME = "validate"
SHA_RE = re.compile(r"^[0-9a-f]{40}$")


def _norm_sha(value: str | None) -> str:
    return (value or "").strip().lower()


def _is_sha(value: str) -> bool:
    return bool(SHA_RE.fullmatch(value))


def _is_validate_check(name: str) -> bool:
    n = (name or "").strip().lower()
    return n == VALIDATE_CHECK_NAME or n.endswith("/ " + VALIDATE_CHECK_NAME) or n == "pr checks / validate"


def decide(payload: dict) -> dict:
    """Return {action, reason, dispatch_sha, merge_head_sha}.

    action:
      reject            — do not merge or deploy
      merge_and_dispatch — squash merge then dispatch deploy
      dispatch_only     — already merged as current tip; dispatch deploy
      noop              — already merged and that tip already deployed successfully
    """
    confirm = (payload.get("confirm") or "").strip()
    if confirm != CONFIRM_PHRASE:
        return {
            "action": "reject",
            "reason": "confirmation phrase mismatch — required exactly: Approved — Merge & Deploy",
            "dispatch_sha": None,
        }

    expected = _norm_sha(payload.get("expected_head_sha"))
    if not _is_sha(expected):
        return {
            "action": "reject",
            "reason": "expected_head_sha must be a full 40-char commit SHA",
            "dispatch_sha": None,
        }

    owner = payload.get("owner") or ""
    repo = payload.get("repo") or ""
    full_name = f"{owner}/{repo}"
    pr = payload.get("pr") or {}
    origin_main = _norm_sha(payload.get("origin_main_sha"))
    successful_deploys = {
        _norm_sha(s) for s in (payload.get("successful_deploy_shas") or []) if _is_sha(_norm_sha(s))
    }

    if pr.get("base", {}).get("ref") != "main":
        return {
            "action": "reject",
            "reason": "PR must target main",
            "dispatch_sha": None,
        }
    if pr.get("draft") is True:
        return {
            "action": "reject",
            "reason": "draft PR rejected — mark Ready before owner approval",
            "dispatch_sha": None,
        }
    head_ref = (pr.get("head") or {}).get("ref") or ""
    if not head_ref.startswith("cursor/"):
        return {
            "action": "reject",
            "reason": "non-cursor/ branch rejected",
            "dispatch_sha": None,
        }
    head_repo = ((pr.get("head") or {}).get("repo") or {}).get("full_name") or ""
    if not head_repo or head_repo != full_name:
        return {
            "action": "reject",
            "reason": "fork / mismatched head repo rejected",
            "dispatch_sha": None,
        }

    head_sha = _norm_sha((pr.get("head") or {}).get("sha"))
    merge_commit = _norm_sha(pr.get("merge_commit_sha") or ((pr.get("mergeCommit") or {}).get("oid")))
    merged = bool(pr.get("merged"))

    if merged:
        if not _is_sha(origin_main):
            return {
                "action": "reject",
                "reason": "origin/main SHA missing; cannot prove tip",
                "dispatch_sha": None,
            }
        if not _is_sha(merge_commit):
            return {
                "action": "reject",
                "reason": "merged PR has no merge commit SHA",
                "dispatch_sha": None,
            }
        if merge_commit != origin_main:
            return {
                "action": "reject",
                "reason": "already-merged PR is not current origin/main tip — refusing stale SHA deploy",
                "dispatch_sha": None,
            }
        if merge_commit in successful_deploys:
            return {
                "action": "noop",
                "reason": "idempotent: squash SHA already current origin/main tip and already deployed successfully",
                "dispatch_sha": merge_commit,
            }
        return {
            "action": "dispatch_only",
            "reason": "idempotent: already squash-merged as current origin/main tip — dispatch Deploy Production only",
            "dispatch_sha": merge_commit,
        }

    if head_sha != expected:
        return {
            "action": "reject",
            "reason": f"PR head SHA changed (pr={head_sha} expected={expected})",
            "dispatch_sha": None,
        }
    if pr.get("mergeable") is False or pr.get("mergeable_state") == "dirty":
        return {
            "action": "reject",
            "reason": "PR is not mergeable (conflicts)",
            "dispatch_sha": None,
        }
    state = (pr.get("mergeable_state") or "").lower()
    if state in ("blocked", "behind", "unstable", "draft"):
        return {
            "action": "reject",
            "reason": f"PR mergeable_state={state} — wait until clean",
            "dispatch_sha": None,
        }
    if state and state not in ("clean", "has_hooks", "unknown", ""):
        # unknown is GitHub lag; allow only when mergeable is explicitly true
        if pr.get("mergeable") is not True:
            return {
                "action": "reject",
                "reason": f"PR mergeable_state={state} is not clean",
                "dispatch_sha": None,
            }

    checks = payload.get("check_runs") or []
    validate_ok = False
    for run in checks:
        name = (run.get("name") or "").strip()
        conclusion = (run.get("conclusion") or "").lower()
        status = (run.get("status") or "").lower()
        if status not in ("completed", "") and conclusion not in ("success", "skipped", "neutral"):
            if status in ("in_progress", "queued", "pending"):
                return {
                    "action": "reject",
                    "reason": f"check '{name}' is not complete",
                    "dispatch_sha": None,
                }
        if conclusion in ("failure", "timed_out", "cancelled", "startup_failure", "stale", "action_required"):
            return {
                "action": "reject",
                "reason": f"failed checks rejected ({name}={conclusion})",
                "dispatch_sha": None,
            }
        if _is_validate_check(name) and conclusion == "success":
            validate_ok = True
    if not validate_ok:
        return {
            "action": "reject",
            "reason": "required PR checks / validate did not succeed on this SHA",
            "dispatch_sha": None,
        }

    if not _is_sha(origin_main):
        return {
            "action": "reject",
            "reason": "origin/main SHA missing",
            "dispatch_sha": None,
        }

    return {
        "action": "merge_and_dispatch",
        "reason": "owner-approved: squash merge pinned to expected head SHA, then dispatch exact squash SHA",
        "dispatch_sha": None,
        "merge_head_sha": expected,
    }


def _self_test() -> int:
    fails = 0

    def check(name: str, payload: dict, want: str) -> None:
        nonlocal fails
        got = decide(payload)["action"]
        if got != want:
            print(f"FAIL: {name}: got {got} want {want}", file=sys.stderr)
            fails += 1
        else:
            print(f"PASS: {name}")

    sha = "a" * 40
    sha2 = "b" * 40
    main = "c" * 40
    squash = main
    base_pr = {
        "draft": False,
        "merged": False,
        "mergeable": True,
        "mergeable_state": "clean",
        "base": {"ref": "main"},
        "head": {
            "ref": "cursor/example-0f83",
            "sha": sha,
            "repo": {"full_name": "o/r"},
        },
    }
    good = {
        "confirm": CONFIRM_PHRASE,
        "expected_head_sha": sha,
        "owner": "o",
        "repo": "r",
        "origin_main_sha": main,
        "check_runs": [{"name": "validate", "conclusion": "success", "status": "completed"}],
        "pr": dict(base_pr),
        "successful_deploy_shas": [],
    }

    check("happy merge_and_dispatch", good, "merge_and_dispatch")
    happy = decide(good)
    if happy.get("merge_head_sha") != sha:
        print("FAIL: happy path merge_head_sha not pinned", file=sys.stderr)
        fails += 1
    else:
        print("PASS: merge_head_sha pinned to expected head")

    bad_phrase = dict(good, confirm="Approved - Merge & Deploy")
    check("wrong phrase rejected", bad_phrase, "reject")

    draft = dict(good, pr={**base_pr, "draft": True})
    check("draft rejected", draft, "reject")

    non_cursor = dict(
        good,
        pr={**base_pr, "head": {**base_pr["head"], "ref": "feature/x"}},
    )
    check("non-cursor rejected", non_cursor, "reject")

    non_main = dict(good, pr={**base_pr, "base": {"ref": "develop"}})
    check("non-main rejected", non_main, "reject")

    fork = dict(
        good,
        pr={**base_pr, "head": {**base_pr["head"], "repo": {"full_name": "other/r"}}},
    )
    check("fork rejected", fork, "reject")

    moved = dict(
        good,
        pr={**base_pr, "head": {**base_pr["head"], "sha": sha2}},
    )
    check("changed SHA rejected", moved, "reject")

    failed = dict(
        good,
        check_runs=[
            {"name": "validate", "conclusion": "failure", "status": "completed"},
        ],
    )
    check("failed checks rejected", failed, "reject")

    validate_alias = dict(
        good,
        check_runs=[
            {"name": "PR checks / validate", "conclusion": "success", "status": "completed"},
        ],
    )
    check("validate check name alias accepted", validate_alias, "merge_and_dispatch")

    blocked = dict(good, pr={**base_pr, "mergeable_state": "blocked"})
    check("blocked mergeable_state rejected", blocked, "reject")

    missing_validate = dict(good, check_runs=[])
    check("missing validate rejected", missing_validate, "reject")

    dirty = dict(good, pr={**base_pr, "mergeable": False, "mergeable_state": "dirty"})
    check("conflicts rejected", dirty, "reject")

    already = dict(
        good,
        pr={
            **base_pr,
            "merged": True,
            "merge_commit_sha": squash,
            "head": {**base_pr["head"], "sha": sha},
        },
        origin_main_sha=squash,
    )
    check("duplicate merged tip dispatches only", already, "dispatch_only")

    already_done = dict(already, successful_deploy_shas=[squash])
    check("duplicate after successful deploy is noop", already_done, "noop")

    stale_merged = dict(already, origin_main_sha=sha2, pr={**already["pr"], "merge_commit_sha": squash})
    check("stale merged SHA rejected", stale_merged, "reject")

    return fails


def main(argv: list[str]) -> int:
    if "--self-test" in argv:
        fails = _self_test()
        if fails:
            print(f"{fails} SELF-TEST FAIL(S)", file=sys.stderr)
            return 1
        print("owner-merge-and-deploy.py self-test: ALL PASS")
        return 0

    raw = None
    if "--input-json" in argv:
        i = argv.index("--input-json")
        path = argv[i + 1] if i + 1 < len(argv) else "-"
        if path == "-":
            raw = sys.stdin.read()
        else:
            raw = open(path, encoding="utf-8").read()
    else:
        raw = sys.stdin.read()
    payload = json.loads(raw)
    result = decide(payload)
    print(json.dumps(result, indent=2))
    return 0 if result["action"] != "reject" else 2


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
