#!/bin/bash
# Static proof that an owner-approved Agent change packages the exact squash
# commit even though GITHUB_TOKEN merges do not emit recursive push workflows.
# Does not call GitHub, build, merge, dispatch, or publish a release.
set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
BUILD="$ROOT/.github/workflows/build-agent.yml"
OWNER="$ROOT/.github/workflows/owner-merge-and-deploy.yml"
HANDOFF="$ROOT/scripts/owner-merge-and-deploy.sh"
FAILS=0
ok() { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS + 1)); }

for file in "$BUILD" "$OWNER" "$HANDOFF"; do
  [ -f "$file" ] || bad "missing $file"
done

grep -q "target_sha:" "$BUILD" \
  && grep -q 'ref: \${{ inputs.target_sha || github.sha }}' "$BUILD" \
  && ok "Agent build accepts and checks out an exact target SHA" \
  || bad "Agent build must be exact-SHA dispatchable"

grep -q "target_sha must be a full 40-character SHA" "$BUILD" \
  && grep -q "Checked out \$actual, expected \$env:REQUESTED_SHA" "$BUILD" \
  && grep -q "merge-base --is-ancestor \$actual origin/main" "$BUILD" \
  && grep -q 'target_commitish: \${{ steps.release-source.outputs.target_sha }}' "$BUILD" \
  && ok "Agent build validates source and binds release target to it" \
  || bad "Agent release source verification is incomplete"

grep -q "Release \$tag already belongs to \$tagTarget; bump package version" "$BUILD" \
  && ok "existing version tag cannot be silently moved to another commit" \
  || bad "Agent release tag collision guard missing"

grep -q "actions/checkout@v7" "$BUILD" \
  && ok "Agent build uses current supported checkout" \
  || bad "Agent build checkout action is stale"

grep -q "pulls/\${PULL}/files" "$HANDOFF" \
  && grep -q "^AGENT_RELEASE_NEEDED=false" "$HANDOFF" \
  && grep -q "agent_release_needed=\$AGENT_RELEASE_NEEDED" "$HANDOFF" \
  && ok "owner handoff detects Agent/build-workflow changes and exports decision" \
  || bad "owner handoff Agent-change detection missing"

grep -q "build-agent.yml/dispatches" "$OWNER" \
  && grep -q 'inputs\[target_sha\]=\$TARGET_SHA' "$OWNER" \
  && grep -q 'steps.owner-handoff.outputs.merge_sha' "$OWNER" \
  && grep -q 'for attempt in 1 2 3' "$OWNER" \
  && grep -q "actions: write" "$OWNER" \
  && ok "single owner approval dispatches Agent build for exact merge SHA with bounded recovery" \
  || bad "owner workflow exact-SHA Agent dispatch missing"

grep -q 'group: build-pra-agent-\${{ inputs.target_sha || github.sha }}' "$BUILD" \
  && grep -q 'already_released=true' "$BUILD" \
  && grep -q "no assets were replaced" "$BUILD" \
  && ok "duplicate exact-SHA Agent dispatches are serialized and idempotent" \
  || bad "Agent build duplicate-dispatch guard missing"

if grep -qiE 'GH_PAT|PERSONAL_ACCESS_TOKEN|PRODUCTION_SSH_PRIVATE_KEY|LIVE_QA_PASS' "$BUILD" "$OWNER" "$HANDOFF"; then
  bad "Agent release chain must not introduce PAT or production credentials"
else
  ok "Agent release chain remains PAT- and production-credential-free"
fi

python3 - "$OWNER" <<'PY' && ok "Agent dispatch runs only after owner handoff" || bad "Agent dispatch ordering unsafe"
import sys
text = open(sys.argv[1], encoding="utf-8").read()
handoff = text.find("Owner-approved merge + exact-SHA deploy handoff")
agent = text.find("Dispatch exact-SHA Agent release")
raise SystemExit(0 if 0 <= handoff < agent else 1)
PY

python3 - "$BUILD" "$OWNER" <<'PY' && ok "workflow YAML parses" || bad "workflow YAML parse failed"
import sys, yaml
for path in sys.argv[1:]:
    with open(path, encoding="utf-8") as handle:
        yaml.safe_load(handle)
PY

if [ "$FAILS" -eq 0 ]; then
  echo "agent-release-chain-check: ALL PASS"
  exit 0
fi
echo "agent-release-chain-check: $FAILS FAIL(S)" >&2
exit 1
