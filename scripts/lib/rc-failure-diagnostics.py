#!/usr/bin/env python3
"""Write bounded, sanitized diagnostics for a failed RC lane.

The RC scripts intentionally keep their command output in a disposable runtime.
This helper is the boundary between that raw evidence and anything that may be
printed by CI or uploaded as an artifact.  It only reads regular files below
the supplied runtime, excludes the output directory itself, redacts common
credential forms, and bounds both individual logs and the aggregate artifact.
"""

from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import os
import pathlib
import re
import stat
import sys
import tempfile
from typing import Iterable

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from rc_redactor import redact_text


MAX_LOG_BYTES = 256 * 1024
MAX_TOTAL_BYTES = 1024 * 1024
MAX_LOG_LINES = 5000

def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Create sanitized, bounded diagnostics for a failed RC lane."
    )
    parser.add_argument("--runtime", required=True, help="disposable runtime directory")
    parser.add_argument("--phase", required=True, help="short failure phase label")
    parser.add_argument("--exit", required=True, type=int, dest="exit_code")
    return parser.parse_args()


def safe_phase(value: str) -> str:
    value = re.sub(r"[^A-Za-z0-9_.:/ -]", "_", value.strip())
    return value[:120] or "unknown"


def redact(text: str, runtime: str, *, truncated: bool = False) -> str:
    return redact_text(text, runtime=runtime, truncated=truncated)


def bounded_text(path: pathlib.Path, runtime: str) -> tuple[str, bool, int]:
    """Return sanitized text, truncation state, and original byte count."""
    with path.open("rb") as handle:
        data = handle.read(MAX_LOG_BYTES + 1)
    truncated = len(data) > MAX_LOG_BYTES
    if truncated:
        data = data[:MAX_LOG_BYTES]
    text = data.decode("utf-8", errors="replace")
    lines = text.splitlines()
    if len(lines) > MAX_LOG_LINES:
        lines = lines[:MAX_LOG_LINES]
        truncated = True
    text = "\n".join(redact(line, runtime, truncated=truncated) for line in lines)
    if text:
        text += "\n"
    if truncated:
        text += "[redacted diagnostic truncated]\n"
    return text, truncated, len(data)


def regular_files(runtime: pathlib.Path, output: pathlib.Path) -> Iterable[pathlib.Path]:
    """Yield only regular, non-symlink log files under runtime."""
    output_resolved = output.resolve()
    for path in sorted(runtime.rglob("*")):
        try:
            relative = path.resolve().relative_to(output_resolved)
        except ValueError:
            relative = None
        if relative is not None:
            continue
        try:
            mode = path.lstat().st_mode
        except OSError:
            continue
        if not stat.S_ISREG(mode) or path.is_symlink():
            continue
        # Bootstrap and check output is conventionally .log.  Include the
        # results TSV because it identifies the failing check without exposing
        # command output, but never include arbitrary source/dependency files.
        if path.suffix == ".log" or path.name == "bootstrap.log" or path.name == "results.tsv":
            yield path


def atomic_write(path: pathlib.Path, content: str) -> None:
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix=f".{path.name}.", dir=path.parent)
    try:
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as handle:
            handle.write(content)
        os.replace(temporary, path)
    finally:
        try:
            os.unlink(temporary)
        except FileNotFoundError:
            pass


def digest_file(path: pathlib.Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(64 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def main() -> int:
    args = parse_args()
    runtime = pathlib.Path(args.runtime).expanduser().resolve()
    if not runtime.is_dir():
        raise SystemExit(f"diagnostic runtime is not a directory: {runtime}")

    output = runtime / "failure-diagnostics"
    if output.exists() and output.is_symlink():
        raise SystemExit("diagnostic output directory must not be a symlink")
    output.mkdir(mode=0o700, exist_ok=True)
    os.chmod(output, 0o700)

    generated = dt.datetime.now(dt.timezone.utc).isoformat().replace("+00:00", "Z")
    rows: list[dict[str, object]] = []
    rendered: list[str] = []
    total = 0

    for path in regular_files(runtime, output):
        try:
            text, truncated, original_bytes = bounded_text(path, str(runtime))
        except OSError:
            continue
        relative = path.relative_to(runtime).as_posix()
        if total + len(text.encode("utf-8")) > MAX_TOTAL_BYTES:
            rendered.append("[additional diagnostic logs omitted at aggregate limit]\n")
            break
        digest = digest_file(path)
        rows.append(
            {
                "path": relative,
                "sha256": digest,
                "bytes": original_bytes,
                "truncated": truncated,
            }
        )
        rendered.append(f"--- begin {relative} sha256={digest} ---\n")
        rendered.append(text or "[empty log]\n")
        rendered.append(f"--- end {relative} ---\n")
        total += len(text.encode("utf-8"))

    if not rendered:
        rendered.append(
            "[no command logs were available; failure occurred before logging started]\n"
        )

    log_text = (
        "RC failure diagnostics (sanitized; raw logs remain in the disposable "
        "runtime only)\n"
        + "".join(rendered)
    )
    log_path = output / "logs.txt"
    summary_path = output / "diagnostic.json"
    summary = {
        "schema_version": 1,
        "kind": "rc-failure-diagnostics",
        "generated_utc": generated,
        "phase": safe_phase(args.phase),
        "exit": args.exit_code,
        "runtime": runtime.name,
        "logs": rows,
        "artifacts": {
            "summary": "failure-diagnostics/diagnostic.json",
            "sanitized_logs": "failure-diagnostics/logs.txt",
        },
    }
    atomic_write(log_path, log_text)
    atomic_write(summary_path, json.dumps(summary, indent=2, sort_keys=True) + "\n")
    os.chmod(log_path, 0o600)
    os.chmod(summary_path, 0o600)

    print(f"RC_FAILURE_ARTIFACT_DIR={output}", file=sys.stderr)
    print(f"RC_FAILURE_SUMMARY={summary_path}", file=sys.stderr)
    print(f"RC_FAILURE_LOG={log_path}", file=sys.stderr)
    # Printing the sanitized body is intentional: this is the only diagnostic
    # content the calling RC script may place in a CI log on failure.
    print(log_text, file=sys.stderr, end="")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except BrokenPipeError:
        raise SystemExit(1)