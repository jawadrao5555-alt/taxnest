#!/usr/bin/env bash
# Recovery guard: the original DI MariaDB application-race harness and raw
# transcript were lost with the temporary worktree. Do not emit a false PASS.
set -Eeuo pipefail
printf '%s\n' 'DI fiscal MariaDB lab is not recertified after 2026-09-16T11:04Z worktree loss.' >&2
exit 2