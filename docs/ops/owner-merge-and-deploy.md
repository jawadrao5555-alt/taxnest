# Owner Merge & Deploy

Cursor Cloud Agents implement, test, and open **one focused `cursor/*` PR**, then
**STOP**. They must not merge, must not click the GitHub PR Merge button, and
must not dispatch **Deploy Production**.

Production merge+deploy is initiated only by the GitHub Actions workflow
**Owner Merge & Deploy** (`workflow_dispatch`). After explicit owner approval
in chat, the agent runs `scripts/owner-merge-and-deploy-request.sh`, which
re-validates the PR and SHA and attempts that dispatch. Cloud Agent `gh` is
typically HTTP 403; then the owner clicks **Run workflow** with the printed
values. Do **not** use the GitHub PR Merge button when this workflow is
available.

## Confirmation phrases

After this file is on `origin/main`, exactly one of (match spaces and case;
em dash `—`, not a hyphen):

```
Approved — Merge & Deploy
Deploy kar do
Live kar do
Approved, put it live
```

`Approved - Merge & Deploy` (hyphen) is **rejected**.

**Actions checks out `main`, not the PR branch.** `scripts/owner-merge-and-deploy-request.sh`
runs `origin/main`'s `decide()` before dispatch. A phrase that exists only on a
feature branch is **not** live yet. Until this PR is squash-merged, origin/main
accepts only `Approved — Merge & Deploy`. Do not send `Deploy kar do` to the
Actions workflow on `main` until that alias is in `origin/main`'s
`scripts/lib/owner-merge-and-deploy.py`.

## Steps (workflow is already on `main`)

1. Wait until the feature PR is **Ready** (not draft), targets **`main`**, branch
   starts with **`cursor/`**, and **PR checks / validate** is green on the current
   head SHA.
2. Copy the PR **number** and the full 40-character **head SHA**.
3. After an explicit approval phrase in chat, the agent runs:

   ```bash
   bash scripts/owner-merge-and-deploy-request.sh <pull_number> <expected_head_sha> '<confirm phrase>'
   ```

   That prints the exact PR number and HEAD SHA being sent. Exit **0** means
   dispatch was accepted. Exit **3** means gates passed but `workflow_dispatch`
   is 403 — owner clicks **Run workflow** with the printed fill-ins. Exit **2**
   means reject: do not merge, do not deploy.
4. If the owner runs it from the Actions tab instead:
   GitHub → **Actions** → **Owner Merge & Deploy** → **Run workflow**.
   Branch: **`main`**. Inputs: `pull_number`, `expected_head_sha`, `confirm`.
5. The job squash-merges **only if** the head SHA still matches, then
   verifies the squash commit is the current `origin/main` tip, then
   `workflow_dispatch`es **Deploy Production** with `inputs.target_sha` only.
6. Watch **Deploy Production**. Environment `production-deploy` has **no
   reviewer wait**. Live-verify must pass before anyone says LIVE VERIFIED.

Do **not** approve Environment `production` for this path. That Environment is
Live Ops only.

Do **not** click **Merge** on the PR page. Deploy Production does **not** start
on `push`, so a UI merge will not go live. Use Owner Merge & Deploy so the
squash SHA is pinned and dispatched. GitHub UI squash/rebase still bypasses
that pin if someone later dispatches the resulting tip SHA by hand — do not
use them. Empty `target_sha` on Deploy Production is refused.

## What the workflow rejects

- Wrong confirmation phrase (including the hyphen variant)
- Draft PRs
- Non-`cursor/` branches
- Forks
- PRs not targeting `main`
- Head SHA different from `expected_head_sha`
- Failed or missing `validate` check
- Merge conflicts / non-clean state
- Already-merged PRs whose squash SHA is **not** the current `origin/main` tip
  (never deploy an older SHA)

Duplicate approval of a PR that is **already** the current main tip is
idempotent: it dispatches Deploy Production again, or no-ops if that SHA already
had a successful Deploy Production run.

## Chat command (Phase 2)

These chat phrases are authorization to **initiate Owner Merge & Deploy** for
the reported PR + SHA — not to merge from the PR page, not to SSH, and not to
start Deploy Production directly:

- `Approved — Merge & Deploy`
- `Deploy kar do`
- `Live kar do`
- `Approved, put it live`

The agent must re-verify the PR number, Ready state, required checks, unchanged
HEAD SHA, `main` target, and mergeability; show those exact values; then run
`scripts/owner-merge-and-deploy-request.sh`. That script gates confirm on
**origin/main** `decide()` (what Actions will run). It must **not** invent a
`gh pr merge`. If origin/main rejects an alias that only exists on the PR,
use the phrase origin/main currently accepts (today: `Approved — Merge & Deploy`).
