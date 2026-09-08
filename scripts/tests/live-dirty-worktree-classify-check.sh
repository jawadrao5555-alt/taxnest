#!/bin/bash
# Prove live dirty-worktree classification:
#   - leftover live-remote-apply.sh public/sw.js CACHE_VERSION stamp is allowed
#   - any other tracked dirty file still fail-closes
#   - unexpected sw.js hunks still fail-close
#   - classifier/preflight introduce no stash/reset/checkout/clean
# Does NOT SSH, deploy, or read production secrets.
# Usage: bash scripts/tests/live-dirty-worktree-classify-check.sh
set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
PY="$ROOT/scripts/lib/live-dirty-worktree-classify.py"
SH="$ROOT/scripts/lib/live-dirty-worktree.sh"
CI="$ROOT/scripts/ci-deploy-production.sh"
DL="$ROOT/scripts/deploy-live.sh"
LIB="$ROOT/scripts/lib/live-remote-apply.sh"
WF="$ROOT/.github/workflows/deploy-production.yml"
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS+1)); }

python3 -m py_compile "$PY" && ok "live-dirty-worktree-classify.py compiles" \
  || bad "classify helper py_compile failed"
bash -n "$SH" && ok "live-dirty-worktree.sh bash -n clean" \
  || bad "live-dirty-worktree.sh bash -n failed"

run_cls() {
  local pfile dfile rc
  pfile=$(mktemp)
  dfile=$(mktemp)
  printf '%s' "$1" > "$pfile"
  if [ "$#" -ge 2 ]; then
    printf '%s' "$2" > "$dfile"
    python3 "$PY" --porcelain-file "$pfile" --sw-diff-file "$dfile" 2>/dev/null
    rc=$?
    rm -f "$pfile" "$dfile"
    return "$rc"
  fi
  python3 "$PY" --porcelain-file "$pfile" 2>/dev/null
  rc=$?
  rm -f "$pfile" "$dfile"
  return "$rc"
}

STAMP_DIFF=$(cat <<'EOF'
diff --git a/public/sw.js b/public/sw.js
index 1111111..2222222 100644
--- a/public/sw.js
+++ b/public/sw.js
@@ -1,6 +1,6 @@
 const CACHE_NAME = 'taxnest-v1';
-const CACHE_VERSION = 'taxnest-20260907-043308-2476f770'; // auto-bumped by deploy-live.sh — purges old caches + triggers SW update badge on every deploy (Task 710)
+const CACHE_VERSION = 'taxnest-20260908-aecc864d'; // stamped on live by live-remote-apply.sh from the deployed SHA
 const STATIC_CACHE = `${CACHE_VERSION}-static`;
EOF
)

EXTRA_HUNK_DIFF=$(cat <<'EOF'
diff --git a/public/sw.js b/public/sw.js
index 1111111..2222222 100644
--- a/public/sw.js
+++ b/public/sw.js
@@ -1,8 +1,8 @@
 const CACHE_NAME = 'taxnest-v1';
-const CACHE_VERSION = 'taxnest-20260907-043308-2476f770'; // auto-bumped by deploy-live.sh — purges old caches + triggers SW update badge on every deploy (Task 710)
+const CACHE_VERSION = 'taxnest-20260908-aecc864d'; // stamped on live by live-remote-apply.sh from the deployed SHA
 const STATIC_CACHE = `${CACHE_VERSION}-static`;
-const skipPatterns = [];
+const skipPatterns = ['/secret'];
EOF
)

TIMESTAMP_PLUS_DIFF=$(cat <<'EOF'
diff --git a/public/sw.js b/public/sw.js
index 1111111..2222222 100644
--- a/public/sw.js
+++ b/public/sw.js
@@ -1,6 +1,6 @@
 const CACHE_NAME = 'taxnest-v1';
-const CACHE_VERSION = 'taxnest-20260907-043308-2476f770'; // auto-bumped by deploy-live.sh
+const CACHE_VERSION = 'taxnest-20260908-043308-aecc864d'; // manual or unknown bump
 const STATIC_CACHE = `${CACHE_VERSION}-static`;
EOF
)

# --- clean / untracked
OUT=$(run_cls "")
RC=$?
[ "$RC" -eq 0 ] && [ "$OUT" = "CLEAN" ] && ok "empty porcelain is CLEAN" \
  || bad "empty porcelain expected CLEAN rc0 got rc=$RC out=$OUT"

OUT=$(run_cls $'?? scratch.txt\n?? foo.log\n')
RC=$?
[ "$RC" -eq 0 ] && [ "$OUT" = "CLEAN" ] && ok "untracked-only porcelain is CLEAN" \
  || bad "untracked-only expected CLEAN rc0 got rc=$RC out=$OUT"

# --- Deploy #16 reconstruction: leftover PR #28 stamp
OUT=$(run_cls $' M public/sw.js\n' "$STAMP_DIFF")
RC=$?
[ "$RC" -eq 0 ] && [ "$OUT" = "EXPECTED_SW_STAMP" ] \
  && ok "Deploy #16 leftover sw.js stamp is EXPECTED_SW_STAMP" \
  || bad "stamp-only sw.js expected EXPECTED_SW_STAMP rc0 got rc=$RC out=$OUT"

OUT=$(run_cls $'M  public/sw.js\n' "$STAMP_DIFF")
RC=$?
[ "$RC" -eq 0 ] && [ "$OUT" = "EXPECTED_SW_STAMP" ] \
  && ok "staged-only sw.js stamp is still EXPECTED_SW_STAMP" \
  || bad "staged stamp expected EXPECTED_SW_STAMP got rc=$RC out=$OUT"

OUT=$(run_cls $' M public/sw.js\n')
RC=$?
[ "$RC" -eq 3 ] && [ "$OUT" = "NEED_SW_DIFF" ] \
  && ok "sw.js-only without diff asks for git diff HEAD (no mutation)" \
  || bad "sw.js without diff expected NEED_SW_DIFF rc3 got rc=$RC out=$OUT"

# --- unrelated dirty files still block
OUT=$(run_cls $' M app/Http/Kernel.php\n')
RC=$?
[ "$RC" -eq 2 ] && [ "$OUT" = "UNEXPECTED_TRACKED" ] \
  && ok "unrelated dirty tracked file is UNEXPECTED_TRACKED" \
  || bad "Kernel.php dirty expected UNEXPECTED_TRACKED rc2 got rc=$RC out=$OUT"

OUT=$(run_cls $' M public/sw.js\n M app/Models/User.php\n' "$STAMP_DIFF")
RC=$?
[ "$RC" -eq 2 ] && [ "$OUT" = "UNEXPECTED_TRACKED" ] \
  && ok "stamp + unrelated dirty file still blocks (stamp is not a blanket allow)" \
  || bad "sw.js+User.php expected UNEXPECTED_TRACKED rc2 got rc=$RC out=$OUT"

OUT=$(run_cls $' D public/index.php\n')
RC=$?
[ "$RC" -eq 2 ] && [ "$OUT" = "UNEXPECTED_TRACKED" ] \
  && ok "deleted tracked file is UNEXPECTED_TRACKED" \
  || bad "deleted index.php expected UNEXPECTED_TRACKED got rc=$RC out=$OUT"

# --- unexpected sw.js content still blocks
OUT=$(run_cls $' M public/sw.js\n' "$EXTRA_HUNK_DIFF")
RC=$?
[ "$RC" -eq 2 ] && [ "$OUT" = "UNEXPECTED_SW_JS" ] \
  && ok "sw.js stamp plus extra hunk is UNEXPECTED_SW_JS" \
  || bad "extra hunk expected UNEXPECTED_SW_JS rc2 got rc=$RC out=$OUT"

OUT=$(run_cls $' M public/sw.js\n' "$TIMESTAMP_PLUS_DIFF")
RC=$?
[ "$RC" -eq 2 ] && [ "$OUT" = "UNEXPECTED_SW_JS" ] \
  && ok "non-stamp CACHE_VERSION working-tree edit is UNEXPECTED_SW_JS" \
  || bad "timestamp plus expected UNEXPECTED_SW_JS got rc=$RC out=$OUT"

OUT=$(run_cls $' M public/sw.js\n' $'diff --git a/public/sw.js b/public/sw.js\n')
RC=$?
[ "$RC" -eq 2 ] && [ "$OUT" = "UNEXPECTED_SW_JS" ] \
  && ok "empty/header-only sw.js diff is UNEXPECTED_SW_JS" \
  || bad "header-only diff expected UNEXPECTED_SW_JS got rc=$RC out=$OUT"

# --- no destructive git in the new preflight path
for f in "$PY" "$SH"; do
  if grep -E 'git[[:space:]]+(stash|reset|checkout|clean)' "$f" >/dev/null; then
    bad "$(basename "$f") must not invoke git stash/reset/checkout/clean"
  else
    ok "$(basename "$f") has no git stash/reset/checkout/clean"
  fi
done

# Callers source the classifier and still fail-close with Not auto-stashing
grep -q 'live-dirty-worktree.sh' "$CI" \
  && ok "ci-deploy-production.sh sources live-dirty-worktree.sh" \
  || bad "ci-deploy-production.sh must source live-dirty-worktree.sh"
grep -q 'live_dirty_worktree_preflight' "$CI" \
  && ok "ci-deploy-production.sh calls live_dirty_worktree_preflight" \
  || bad "ci-deploy-production.sh must call live_dirty_worktree_preflight"
grep -q 'Not auto-stashing' "$CI" \
  && ok "ci-deploy-production.sh still fail-closes with Not auto-stashing" \
  || bad "ci-deploy-production.sh must keep Not auto-stashing fail-closed message"

grep -q 'live-dirty-worktree.sh' "$DL" \
  && ok "deploy-live.sh sources live-dirty-worktree.sh" \
  || bad "deploy-live.sh must source live-dirty-worktree.sh"
grep -q 'live_dirty_worktree_preflight' "$DL" \
  && ok "deploy-live.sh calls live_dirty_worktree_preflight" \
  || bad "deploy-live.sh must call live_dirty_worktree_preflight"
grep -q 'Not auto-stashing' "$DL" \
  && ok "deploy-live.sh still fail-closes with Not auto-stashing" \
  || bad "deploy-live.sh must keep Not auto-stashing fail-closed message"

# Preflight must run before remote_apply in the new-SHA branch
python3 - "$CI" <<'PY' && ok "ci dirty preflight is after Elaan and before remote_apply" || bad "preflight order vs Elaan/remote_apply is wrong"
import sys
text = open(sys.argv[1], encoding="utf-8").read()
elaan = text.find("\ninsert_committed_elaan_spec\ncheck_elaan_freshness")
pre = text.find("live_dirty_worktree_preflight")
apply = text.find("remote_apply 1")
if min(elaan, pre, apply) < 0 or not (elaan < pre < apply):
    sys.exit(1)
sys.exit(0)
PY

# remote_apply still restores-then-stamps (not moved into the preflight)
grep -q 'git checkout -- public/sw.js' "$LIB" \
  && ok "remote_apply still restores public/sw.js immediately before checkout" \
  || bad "do not remove git checkout -- public/sw.js from live-remote-apply.sh"
grep -q "stamped on live by live-remote-apply.sh from the deployed SHA" "$LIB" \
  && ok "remote_apply still stamps CACHE_VERSION after exact-SHA checkout" \
  || bad "do not remove the live CACHE_VERSION stamp"

if grep -q 'git checkout -- public/sw.js' "$SH"; then
  bad "preflight must not restore/checkout public/sw.js (that would discard unknown live edits)"
else
  ok "preflight does not checkout public/sw.js"
fi

# Exact-SHA + Elaan + tip guards remain in CI script / workflow
grep -q 'deploy_require_origin_main_tip' "$CI" \
  && ok "origin/main tip guard still present in ci-deploy-production.sh" \
  || bad "must not weaken origin/main tip guard"
grep -q 'check_elaan_freshness' "$CI" \
  && ok "Elaan freshness still called in ci-deploy-production.sh" \
  || bad "must not weaken Elaan freshness"
grep -q 'git checkout -B main' "$LIB" \
  && ok "exact-SHA checkout -B main still in live-remote-apply.sh" \
  || bad "must not weaken exact-SHA checkout"
if grep -E '^concurrency:' "$WF" >/dev/null; then
  bad "workflow-level concurrency must stay absent (PR #29)"
else
  ok "deploy-production.yml still has no workflow-level concurrency"
fi
grep -q 'cancel-in-progress: false' "$WF" \
  && ok "deploy job still does not cancel in-progress apply" \
  || bad "must not weaken cancel-in-progress: false"
grep -qE '^[[:space:]]+environment: production-deploy[[:space:]]*$' "$WF" \
  && ok "workflow still uses environment: production-deploy" \
  || bad "must not drop environment: production-deploy"

# skip_elaan remains opt-in
if grep -E 'skip_elaan.*=.*true' "$CI" | grep -v 'SKIP_ELAAN' >/dev/null; then
  bad "ci-deploy must not default skip_elaan true"
else
  ok "skip_elaan is not defaulted on in ci-deploy-production.sh"
fi

echo ""
if [ "$FAILS" -eq 0 ]; then
  echo "ALL CHECKS PASSED"
  exit 0
fi
echo "$FAILS CHECK(S) FAILED" >&2
exit 1
