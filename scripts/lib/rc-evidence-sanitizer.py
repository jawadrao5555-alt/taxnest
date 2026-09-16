#!/usr/bin/env python3
"""Stage and atomically publish the allowlisted CI evidence contract."""

from __future__ import annotations

import argparse
import datetime as dt
import os
import pathlib
import re
import shutil
import stat
import tempfile
from typing import Iterable

from rc_redactor import atomic_write, sanitize_bytes


MAX_FILE_BYTES = 1024 * 1024
MAX_TOTAL_BYTES = 8 * 1024 * 1024
FORBIDDEN_PARTS = {
    "source",
    "clone",
    "node_modules",
    "vendor",
    "cache",
    "composer-cache",
    "npm-cache",
    "home",
    "guard",
    "screenshots",
    "screenshot",
    "raw",
    "rawfiles",
    "binary",
    "storage",
    ".env",
    "env",
}
REPORT_NAMES = {
    "diagnostic.json",
    "fbr-kot-timestamp.junit.xml",
    "logs.txt",
    "owner-approval-schema.junit.xml",
    "summary.json",
    "recertification.md",
    "recertification.junit.xml",
}
NATIVE_SUMMARY_NAMES = {
    "upgrade-before.json",
    "upgrade-after.json",
    "upgrade-after-mismatch.json",
}
NATIVE_EVIDENCE_DIR = re.compile(r"^native_[A-Za-z0-9_]+$")
SUPPLEMENTAL_EVIDENCE_DIR = re.compile(r"^supp(?:_|-)[A-Za-z0-9_-]+$")
TEXT_SUFFIXES = {".json", ".md", ".txt", ".xml", ".junit"}
SAFE_VALUE = re.compile(r"[^A-Za-z0-9_.:-]")


def arguments() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Atomically publish bounded, sanitized CI evidence."
    )
    parser.add_argument("target", help="final evidence directory")
    parser.add_argument(
        "--workspace",
        default=os.environ.get("GITHUB_WORKSPACE", "."),
        help="workspace containing disposable evidence",
    )
    return parser.parse_args()


def regular_file(path: pathlib.Path) -> bool:
    try:
        mode = path.lstat().st_mode
    except OSError:
        return False
    return stat.S_ISREG(mode) and not path.is_symlink()


def forbidden(relative: pathlib.Path) -> bool:
    return any(part in FORBIDDEN_PARTS or part.startswith(".env") for part in relative.parts)


def walk_text(root: pathlib.Path) -> Iterable[pathlib.Path]:
    """Walk only to locate reports; never copy a tree or follow symlinks."""
    if not root.is_dir() or root.is_symlink():
        return
    for current, directories, files in os.walk(root, followlinks=False):
        current_path = pathlib.Path(current)
        directories[:] = [
            name
            for name in directories
            if not (current_path / name).is_symlink()
            and not forbidden((current_path / name).relative_to(root))
        ]
        for name in files:
            path = current_path / name
            relative = path.relative_to(root)
            if not regular_file(path) or forbidden(relative):
                continue
            yield path


def allowlisted_sources(workspace: pathlib.Path, target: pathlib.Path) -> list[tuple[pathlib.Path, pathlib.Path]]:
    """Return source/destination pairs; destination names never come from raw paths."""
    pairs: list[tuple[pathlib.Path, pathlib.Path]] = []
    recertification = workspace / ".local" / "recertification"
    for source in walk_text(recertification):
        relative = source.relative_to(recertification)
        native_summary = (
            len(relative.parts) == 2
            and NATIVE_EVIDENCE_DIR.fullmatch(relative.parts[0])
            and (
                source.name in NATIVE_SUMMARY_NAMES
                or source.name.startswith("native")
                and source.suffix.lower() == ".json"
            )
        )
        supplemental_junit = (
            len(relative.parts) == 2
            and SUPPLEMENTAL_EVIDENCE_DIR.fullmatch(relative.parts[0])
            and source.name
            in {"fbr-kot-timestamp.junit.xml", "owner-approval-schema.junit.xml"}
        )
        if source.name not in REPORT_NAMES and not native_summary:
            continue
        if source.name in {"diagnostic.json", "logs.txt"} and source.parent.name != "failure-diagnostics":
            continue
        if source.name in {
            "fbr-kot-timestamp.junit.xml",
            "owner-approval-schema.junit.xml",
        } and not supplemental_junit:
            continue
        if source.name in {"summary.json", "recertification.md", "recertification.junit.xml"}:
            # These are direct runtime reports. Do not pick up arbitrary
            # similarly named files deep inside a dependency/cache tree.
            if len(relative.parts) != 2:
                continue
        pairs.append((source, pathlib.Path("logs") / "recertification" / relative))

    browser = workspace / ".local" / "browser-evidence"
    if browser.is_dir() and not browser.is_symlink():
        for source in sorted(browser.iterdir()):
            if not regular_file(source) or source.suffix.lower() not in TEXT_SUFFIXES:
                continue
            if source.name.startswith(".env") or ".env." in source.name:
                continue
            # Screenshots and other binary evidence are intentionally not part
            # of this contract, even when a file has a misleading text suffix.
            pairs.append((source, pathlib.Path("logs") / "browser-evidence" / source.name))

    # The workflow creates this one text log before checkout. Preserve it, but
    # never recursively copy pre-existing target contents.
    if target.is_dir() and not target.is_symlink():
        logs_dir = target / "logs"
        if not logs_dir.is_symlink():
            prebootstrap = logs_dir / "prebootstrap.log"
            if regular_file(prebootstrap):
                pairs.append((prebootstrap, pathlib.Path("logs") / "prebootstrap.log"))
    return pairs


def safe_relative(path: pathlib.Path) -> bool:
    return (
        path.parts
        and all(part not in {"", ".", ".."} for part in path.parts)
        and not any(part.startswith(".") for part in path.parts)
    )


def copy_redacted(
    source: pathlib.Path,
    destination: pathlib.Path,
    *,
    runtime: str | None,
    total: int,
) -> int:
    with source.open("rb") as handle:
        data = handle.read(MAX_FILE_BYTES + 1)
    text, _ = sanitize_bytes(data, max_bytes=MAX_FILE_BYTES, runtime=runtime)
    encoded = text.encode("utf-8")
    if total + len(encoded) > MAX_TOTAL_BYTES:
        raise ValueError("sanitized evidence exceeds aggregate size bound")
    atomic_write(destination, text)
    return total + len(encoded)


def clean_path(path: pathlib.Path) -> None:
    if path.is_symlink() or path.is_file():
        path.unlink()
    elif path.is_dir():
        shutil.rmtree(path)


def publish(target: pathlib.Path, workspace: pathlib.Path) -> None:
    if target.exists() and target.is_symlink():
        raise ValueError("evidence target must not be a symlink")
    target.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(target.parent, 0o700)
    stage = pathlib.Path(
        tempfile.mkdtemp(prefix=f".{target.name}.staging.", dir=target.parent)
    )
    os.chmod(stage, 0o700)
    backup: pathlib.Path | None = None
    try:
        total = 0
        fail_after_raw = os.environ.get("RC_SANITIZE_EVIDENCE_FAIL_AFTER", "")
        fail_after = int(fail_after_raw) if fail_after_raw.isdigit() else 0
        processed = 0
        for source, relative in allowlisted_sources(workspace, target):
            if not safe_relative(relative):
                raise ValueError(f"unsafe evidence destination: {relative}")
            destination = stage / relative
            destination.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            total = copy_redacted(
                source,
                destination,
                runtime=str(workspace.resolve()),
                total=total,
            )
            processed += 1
            if fail_after and processed >= fail_after:
                raise ValueError("test failpoint after staged evidence")

        generated = dt.datetime.now(dt.timezone.utc).isoformat().replace("+00:00", "Z")
        lane = SAFE_VALUE.sub("_", os.environ.get("GITHUB_JOB", "unknown"))[:120] or "unknown"
        run_id = SAFE_VALUE.sub("_", os.environ.get("GITHUB_RUN_ID", "unknown"))[:120] or "unknown"
        atomic_write(
            stage / "evidence-metadata.txt",
            f"schema_version=1\nlane={lane}\nrun_id={run_id}\n"
            f"generated_utc={generated}\nevidence_contract=allowlisted-text-v2\n"
            "binary_files=excluded\n",
        )
        # The marker is deliberately the final write. Uploaders must require
        # this file rather than treating a directory created before failure as
        # evidence.
        atomic_write(
            stage / "SANITIZED_SUCCESS",
            "sanitized=true\nschema_version=1\ncontract=allowlisted-text-v2\n",
        )

        if target.exists():
            backup = target.with_name(f".{target.name}.previous.{os.getpid()}")
            if backup.exists() or backup.is_symlink():
                raise ValueError("stale evidence publish backup exists")
            os.replace(target, backup)
        try:
            os.replace(stage, target)
        except BaseException:
            raise
        if backup is not None and backup.exists():
            # The published directory is complete once its marker has been
            # atomically moved into place.  A cleanup failure must not turn
            # that complete publication into a reported sanitizer failure.
            try:
                clean_path(backup)
            except OSError:
                pass
            backup = None
    except BaseException:
        # A failed sanitizer must never leave a directory that looks
        # uploadable.  The workflow's upload step also requires the marker.
        if stage.exists():
            clean_path(stage)
        # Remove both a pre-created target and any previous publish.  A failed
        # invocation must leave no marker-bearing directory that an
        # always-upload step could mistake for this run's evidence.
        if target.exists() and not target.is_symlink():
            clean_path(target)
        if backup is not None and backup.exists():
            clean_path(backup)
        raise


def main() -> int:
    args = arguments()
    target_argument = pathlib.Path(args.target).expanduser()
    if target_argument.is_symlink():
        raise SystemExit("evidence target must not be a symlink")
    target = target_argument.resolve()
    workspace_argument = pathlib.Path(args.workspace).expanduser()
    if workspace_argument.is_symlink():
        raise SystemExit("evidence workspace must be a real directory")
    workspace = workspace_argument.resolve()
    if not workspace.is_dir():
        raise SystemExit("evidence workspace must be a real directory")
    if target == pathlib.Path("/") or len(target.parts) < 3:
        raise SystemExit("evidence target is unsafe")
    try:
        target.relative_to(workspace)
    except ValueError:
        pass
    else:
        raise SystemExit("evidence target must be outside the source workspace")
    publish(target, workspace)
    print(f"SANITIZED_EVIDENCE_DIR={target}")
    print(f"SANITIZED_EVIDENCE_MARKER={target / 'SANITIZED_SUCCESS'}")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, ValueError) as exc:
        print(f"ci-sanitize-evidence: {exc}", file=os.sys.stderr)
        raise SystemExit(1) from exc