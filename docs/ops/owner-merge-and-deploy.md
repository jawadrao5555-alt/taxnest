# Owner Merge & Deploy

Cursor Cloud Agents implement, test, and open **one focused `cursor/*` PR**, then
**STOP**. They must not merge and must not dispatch production deploy.

The owner initiates merge+deploy with an auditable GitHub Actions
`workflow_dispatch` (not a chat workaround). Cloud Agent `gh` typically cannot
create `workflow_dispatch` events (HTTP 403).

## Confirmation phrase

Exactly:

```
Approved — Merge & Deploy
```

(em dash `—`, not a hyphen.)

## Steps (after the implementation PR for this workflow is already on `main`)

1. Wait until the feature PR is **Ready** (not draft), targets **`main`**, branch
   starts with **`cursor/`**, and **PR checks / validate** is green on the current
   head SHA.
2. Copy the PR **number** and the full 40-character **head SHA**.
3. GitHub → **Actions** → **Owner Merge & Deploy** → **Run workflow**.
4. Branch: **`main`** (this selects the workflow file on `main`).
5. Inputs:
   - `pull_number`: the PR number
   - `expected_head_sha`: that 40-char head SHA
   - `confirm`: `Approved — Merge & Deploy`
6. Run. The job squash-merges **only if** the head SHA still matches, then
   verifies the squash commit is the current `origin/main` tip, then
   `workflow_dispatch`es **Deploy Production** with `inputs.target_sha` only.
7. Watch **Deploy Production**. Environment `production-deploy` has **no
   reviewer wait**. Live-verify must pass before anyone says LIVE VERIFIED.

Do **not** approve Environment `production` for this path. That Environment is
Live Ops only.

## What the workflow rejects

- Wrong confirmation phrase
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

## First landing of this workflow (chicken-and-egg)

Until `owner-merge-and-deploy.yml` exists on `main`, this Actions workflow
cannot run. The PR that introduces it must be squash-merged **once** by a human
with permission to land on `main`.

**Do not mark that implementation PR Ready while `enable-pr-auto-merge.yml` on
`main` still squash-merges Ready `cursor/*` PRs** — the retired workflow on
`main` would auto-land it. Keep it **draft**, then land it with a local
`git merge --squash` (or equivalent admin merge) onto `main`, **or** mark Ready
only when you accept that the *current* main auto-merge will be the one-time
bootstrap that disables itself.

After that squash is on `main`, future PRs use the steps above. GitHub does not
merge draft PRs from the UI, so later feature PRs should be marked Ready
**after** this workflow is on `main` (auto-merge is then retired).

## Chat command

If the owner types `Approved — Merge & Deploy` in Cursor chat, the agent must
**not** invent a merge. Point the owner at this Actions workflow and the PR
number + head SHA from the PR report.
