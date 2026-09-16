#!/usr/bin/env bash
# Read-only branch cleanup evidence. Patch-id equivalence covers squash merges.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
BASE="${1:-origin/main}"
git rev-parse --verify "$BASE^{commit}" >/dev/null
BASE_PATCH_IDS="$(mktemp)"
trap 'rm -f -- "$BASE_PATCH_IDS"' EXIT HUP INT TERM
git log --no-merges --format=%H "$BASE" | while read -r sha; do
    git show --pretty=format: "$sha" | git patch-id --stable | cut -d' ' -f1
done | sort -u >"$BASE_PATCH_IDS"
printf 'ref\tclassification\treason\n'
git for-each-ref --format='%(refname:short)' refs/remotes/origin | while IFS= read -r ref; do
    [[ "$ref" == origin || "$ref" == "$BASE" ]] && continue
    if git merge-base --is-ancestor "$ref" "$BASE"; then
        printf '%s\tsuperseded-candidate\tancestor-of-%s\n' "$ref" "$BASE"
        continue
    fi
    # A patch-id intersection proves content equivalence even when a PR was
    # squash-merged; no ancestry-only deletion decision is made.
    patch_ids="$(git log --no-merges --format=%H "$ref" --not "$BASE" | while read -r sha; do git show --pretty=format: "$sha" | git patch-id --stable | cut -d' ' -f1; done | sort -u)"
    if [[ -n "$patch_ids" ]] && comm -12 <(printf '%s\n' "$patch_ids") "$BASE_PATCH_IDS" | grep -q .; then
        printf '%s\treview-for-squash-equivalence\tshared-stable-patch-id-with-%s\n' "$ref" "$BASE"
    else
        printf '%s\tretain-pending-review\tno-proven-equivalence\n' "$ref"
    fi
done