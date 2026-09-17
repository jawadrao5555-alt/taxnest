#!/usr/bin/env python3
"""Small, fail-closed text redactor shared by RC evidence producers.

Only text is accepted.  Redaction is deliberately key-aware so useful
versions, package names, and error messages remain visible while common
credential forms (including quoted JSON) do not.
"""

from __future__ import annotations

import argparse
import os
import pathlib
import re
import sys
import tempfile


DEFAULT_MAX_BYTES = 1024 * 1024
ANSI_ESCAPE = re.compile(r"\x1b(?:\[[0-?]*[ -/]*[@-~]|\][^\x07]*(?:\x07|\x1b\\))")
CONTROL_CHARS = re.compile(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]")

PRIVATE_KEY = re.compile(
    r"-----BEGIN [^-]*PRIVATE KEY-----.*?"
    r"-----END [^-]*PRIVATE KEY-----",
    re.IGNORECASE | re.DOTALL,
)
PRIVATE_KEY_OPEN = re.compile(
    r"-----BEGIN [^-]*PRIVATE KEY-----.*",
    re.IGNORECASE | re.DOTALL,
)
AUTH_HEADER = re.compile(
    r"(?i)(\b(?:authorization|proxy-authorization)\s*:\s*)"
    r"(?:bearer|basic|token)\s+\S+"
)
URL_CREDENTIALS = re.compile(r"(?i)(\bhttps?://)([^/\s:@]+):([^@\s]+)@")
# Keep the URL's scheme/host/path and harmless query fields for debugging, but
# never publish values used to authorize a signed download.  The exact-key
# boundary prevents `monkey=` or an ordinary error message from matching the
# generic `key` alternative.
SIGNED_QUERY_VALUE = re.compile(
    r"(?i)([?&](?:x-amz-credential|x-amz-signature|"
    r"x-amz-security-token|signature|sig|token|api[_-]?key|access[_-]?token|"
    r"access[_-]?key|auth(?:entication)?|credential|key|secret|password|passwd|"
    r"private[_-]?key|client[_-]?secret|sas-token)\s*=\s*)"
    r"([^&#\s\"'<>]+)"
)
KNOWN_TOKEN = re.compile(
    r"(?<![A-Za-z0-9])(?:"
    r"(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9_]{8,}"
    r"|github_pat_[A-Za-z0-9_]{8,}"
    r"|glpat-[A-Za-z0-9_-]{8,}"
    r"|xox[baprs]-[A-Za-z0-9-]{8,}"
    r"|AKIA[0-9A-Z]{16}"
    r"|ASIA[0-9A-Z]{16}"
    r"|sk-[A-Za-z0-9][A-Za-z0-9._-]{15,}"
    r"|sk_[A-Za-z0-9_.-]{8,}"
    r"|AIza[0-9A-Za-z_-]{20,}"
    r"|ya29\.[0-9A-Za-z_-]{20,}"
    r"|npm_[A-Za-z0-9_.-]{8,}"
    r")(?=$|[^A-Za-z0-9])"
)

# The key must be credential-shaped; do not redact ordinary "error", "version",
# or package fields.  The optional quote is retained so JSON and shell output
# remain machine-readable after replacement.
SENSITIVE_KEY = (
    r"(?:password|passwd|pwd|token|secret|api[_-]?key|access[_-]?key|"
    r"private[_-]?key|client[_-]?secret|auth[_-]?token|database[_-]?url|"
    r"app[_-]?key|cookie|set-cookie|aws[_-]?secret[_-]?access[_-]?key|"
    r"aws[_-]?session[_-]?token)"
)
QUOTED_VALUE = re.compile(
    rf"(?i)(?P<prefix>[\"']?{SENSITIVE_KEY}[\"']?\s*[:=]\s*)"
    r"(?P<quote>[\"'])(?P<value>(?:\\.|(?! (?P=quote)).)*) (?P=quote)",
    re.VERBOSE,
)
QUOTED_UNCLOSED = re.compile(
    rf"(?is)(?P<prefix>[\"']?{SENSITIVE_KEY}[\"']?\s*[:=]\s*)"
    r"(?P<quote>[\"'])(?P<value>(?:\\.|(?! (?P=quote)).)*)\Z",
    re.VERBOSE,
)
UNQUOTED_VALUE = re.compile(
    rf"(?i)(?P<prefix>[\"']?{SENSITIVE_KEY}[\"']?\s*[:=]\s*)"
    r"(?P<value>(?![\"'])[^ \s,;}]+)"
)


def _quoted_replacement(match: re.Match[str]) -> str:
    return f"{match.group('prefix')}{match.group('quote')}[REDACTED]{match.group('quote')}"


def _unquoted_replacement(match: re.Match[str]) -> str:
    return f"{match.group('prefix')}[REDACTED]"


def redact_text(
    text: str, *, runtime: str | None = None, truncated: bool = False
) -> str:
    """Redact one text payload while retaining useful machine-readable shape."""
    text = ANSI_ESCAPE.sub("", text)
    text = CONTROL_CHARS.sub("", text)
    text = PRIVATE_KEY.sub("[REDACTED PRIVATE KEY]", text)
    # If the bounded payload ends before a matching END marker, fail closed
    # rather than publishing the beginning of a potentially long key.
    text = PRIVATE_KEY_OPEN.sub("[REDACTED PRIVATE KEY]", text)
    text = AUTH_HEADER.sub(r"\1[REDACTED]", text)
    text = URL_CREDENTIALS.sub(r"\1[REDACTED]@", text)
    text = SIGNED_QUERY_VALUE.sub(r"\1[REDACTED]", text)
    text = KNOWN_TOKEN.sub("[REDACTED]", text)
    # Run quoted before unquoted so `"token":"a value"` stays valid JSON.
    text = QUOTED_VALUE.sub(_quoted_replacement, text)
    if truncated:
        text = QUOTED_UNCLOSED.sub(_quoted_replacement, text)
    text = UNQUOTED_VALUE.sub(_unquoted_replacement, text)
    if runtime:
        text = text.replace(runtime, "[RUNTIME]")
    # Never allow sanitized output to be interpreted as a workflow command.
    text = "\n".join(
        f" {line}" if line.lstrip().startswith("::") else line
        for line in text.split("\n")
    )
    return text


def sanitize_bytes(data: bytes, *, max_bytes: int = DEFAULT_MAX_BYTES,
                   runtime: str | None = None) -> tuple[str, bool]:
    """Decode bounded UTF-8 text; reject binary/NUL and mark truncation."""
    if b"\x00" in data:
        raise ValueError("binary/NUL input is not uploadable text")
    truncated = len(data) > max_bytes
    if truncated:
        data = data[:max_bytes]
    try:
        text = data.decode("utf-8", errors="strict")
    except UnicodeDecodeError as exc:
        if truncated:
            # A byte cap may cut a valid multibyte character at the boundary.
            # Trim only that incomplete suffix; genuinely invalid input still
            # fails closed.
            for end in range(len(data) - 1, max(-1, len(data) - 4), -1):
                try:
                    text = data[:end].decode("utf-8", errors="strict")
                    break
                except UnicodeDecodeError:
                    continue
            else:
                raise ValueError("non-UTF-8 input is not uploadable text") from exc
        else:
            raise ValueError("non-UTF-8 input is not uploadable text") from exc
    text = redact_text(text, runtime=runtime, truncated=truncated)
    if truncated:
        text += "\n[redacted diagnostic truncated]\n"
    return text, truncated


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


def _cli() -> int:
    parser = argparse.ArgumentParser(description="Redact bounded UTF-8 text atomically.")
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--max-bytes", type=int, default=DEFAULT_MAX_BYTES)
    parser.add_argument("--runtime")
    args = parser.parse_args()
    source = pathlib.Path(args.input)
    destination = pathlib.Path(args.output)
    if not source.is_file() or source.is_symlink():
        raise SystemExit("redactor input must be a regular, non-symlink file")
    if args.max_bytes <= 0:
        raise SystemExit("--max-bytes must be positive")
    with source.open("rb") as handle:
        data = handle.read(args.max_bytes + 1)
    try:
        text, _ = sanitize_bytes(data, max_bytes=args.max_bytes, runtime=args.runtime)
    except ValueError as exc:
        raise SystemExit(f"redactor rejected input: {exc}") from exc
    atomic_write(destination, text)
    return 0


if __name__ == "__main__":
    raise SystemExit(_cli())