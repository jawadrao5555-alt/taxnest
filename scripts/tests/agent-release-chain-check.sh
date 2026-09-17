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

grep -q "Agent source/build inputs changed on \$actual; bump package version" "$BUILD" \
  && grep -q "idempotent no-build" "$BUILD" \
  && grep -q 'git diff --quiet \$tagTarget \$actual -- pra-agent' "$BUILD" \
  && ok "existing version tag on older commit is no-build when Agent inputs unchanged; bump required when changed" \
  || bad "Agent release tag collision / unchanged-input no-build guard missing"

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

grep -q "node-version: '22'" "$BUILD" \
  && ok "Agent build pins the reviewed Node 22 runtime" \
  || bad "Agent build must pin reviewed Node 22 runtime"

grep -q 'run: npm ci' "$BUILD" \
    && ok "Agent build installs the reviewed lockfile exactly" \
    || bad "Agent build must use npm ci rather than a mutable install"

grep -q "Create canonical release manifest" "$BUILD" \
  && grep -q 'release-manifest.json' "$BUILD" \
  && grep -q 'Get-FileHash -Algorithm SHA256' "$BUILD" \
  && grep -q 'source_sha = \$env:SOURCE_SHA' "$BUILD" \
    && grep -q 'build_sha = \$env:SOURCE_SHA' "$BUILD" \
  && grep -q 'min_agent_version = "1.3.0"' "$BUILD" \
    && ok "Agent build publishes a hash-bound exact-source canonical release manifest" \
  || bad "Agent canonical release manifest metadata is incomplete"

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

python3 - "$BUILD" <<'PY' && ok "missing Agent tag is a successful new-release path; unexpected git errors still fail" || bad "Agent tag lookup LASTEXITCODE handling is unsafe"
import re, sys, yaml

text = open(sys.argv[1], encoding="utf-8").read()
workflow = yaml.safe_load(text)
steps = workflow["jobs"]["build-windows"]["steps"]
agent = next(step for step in steps if step.get("id") == "agent-version")
script = agent["run"]

show_ref = re.search(
    r'git show-ref --verify --quiet "refs/tags/\$tag"\n\s*\$(\w+)\s*=\s*\$LASTEXITCODE\b',
    script,
)
if not show_ref:
    print("git show-ref result is not captured immediately", file=sys.stderr)
    sys.exit(1)
lookup = show_ref.group(1)
if f"if (${lookup} -eq 0)" not in script:
    print("exit 0 must mean the version tag already exists", file=sys.stderr)
    sys.exit(1)
if f"elseif (${lookup} -eq 1)" not in script or '"already_released=false"' not in script:
    print("exit 1 must be the new-release path", file=sys.stderr)
    sys.exit(1)
if f"git show-ref failed with exit code ${lookup}" not in script:
    print("unexpected git show-ref codes must fail the step", file=sys.stderr)
    sys.exit(1)
if "Release $tag already belongs to $tagTarget and Agent source/build inputs changed on $actual; bump package version" not in script:
    print("tag-collision protection missing from agent-version step", file=sys.stderr)
    sys.exit(1)
if "idempotent no-build" not in script:
    print("unchanged-Agent no-build path missing", file=sys.stderr)
    sys.exit(1)
if "git diff --quiet $tagTarget $actual -- pra-agent" not in script:
    print("Agent input tree compare missing", file=sys.stderr)
    sys.exit(1)
if not script.rstrip().endswith("$global:LASTEXITCODE = 0"):
    print("successful process exit state must be restored at end of agent-version", file=sys.stderr)
    sys.exit(1)

# Decision matrix: tag lookup + agent tree diff → outcome.
def decide(tag_lookup: int, same_commit: bool, agent_diff: int | None) -> str:
    if tag_lookup == 0:
        if same_commit:
            return "already_released"
        if agent_diff == 0:
            return "already_released_unchanged_inputs"
        if agent_diff == 1:
            raise RuntimeError("bump package version")
        raise RuntimeError(f"git diff failed with exit code {agent_diff}")
    if tag_lookup == 1:
        return "new_release"
    raise RuntimeError(f"git show-ref failed with exit code {tag_lookup}")

assert decide(0, True, None) == "already_released"
assert decide(0, False, 0) == "already_released_unchanged_inputs"
assert decide(1, False, None) == "new_release"
for bad in (
    lambda: decide(0, False, 1),
    lambda: decide(0, False, 2),
    lambda: decide(128, False, None),
):
    try:
        bad()
    except RuntimeError:
        pass
    else:
        print("expected failure path did not raise", file=sys.stderr)
        sys.exit(1)

# Static model of the captured lookup: missing tag is not a step failure.
def classify(tag_lookup: int) -> str:
    if tag_lookup == 0:
        return "already_released"
    if tag_lookup == 1:
        return "new_release"
    raise RuntimeError(f"git show-ref failed with exit code {tag_lookup}")

assert classify(0) == "already_released"
assert classify(1) == "new_release"
for code in (-1, 2, 128):
    try:
        classify(code)
    except RuntimeError:
        continue
    print(f"unexpected exit {code} must fail", file=sys.stderr)
    sys.exit(1)

source = next(step for step in steps if step.get("id") == "release-source")
src = source["run"]
for needle in (
    "target_sha must be a full 40-character SHA",
    "Checked out $actual, expected $env:REQUESTED_SHA",
    "merge-base --is-ancestor $actual origin/main",
):
    if needle not in src:
        print("exact target-SHA validation drifted", file=sys.stderr)
        sys.exit(1)

install = next(step for step in steps if step.get("run") == "npm ci")
if install.get("if") != "${{ steps.agent-version.outputs.already_released != 'true' }}":
    print("locked Agent install must remain protected by no-build idempotency", file=sys.stderr)
    sys.exit(1)

manifest = next(step for step in steps if step.get("name") == "Create canonical release manifest")
manifest_script = manifest["run"]
for needle in (
    '$zip = Get-Item "dist/TaxNest-PRA-Agent-Windows.zip" -ErrorAction Stop',
    '$exe = Get-Item (Join-Path "dist" $exeName) -ErrorAction Stop',
    'source_sha = $env:SOURCE_SHA.ToLowerInvariant()',
    'build_sha = $env:SOURCE_SHA.ToLowerInvariant()',
    'min_agent_version = "1.3.0"',
    'max_agent_version = "2.99.99"',
):
    if needle not in manifest_script:
        print("canonical manifest metadata or exact-source binding drifted", file=sys.stderr)
        sys.exit(1)
sys.exit(0)
PY

if [ "$FAILS" -eq 0 ]; then
  echo "agent-release-chain-check: ALL PASS"
  exit 0
fi
echo "agent-release-chain-check: $FAILS FAIL(S)" >&2
exit 1
