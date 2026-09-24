# Owner Merge & Deploy

Agents implement, test, and open **one focused `cursor/*` or `replit/*` PR**,
then **STOP**.
They must not merge, approve, or dispatch production.

The owner authorizes an exact release from the authenticated TaxNest SaaS admin
panel. Plain chat text is intent, not authentication.

## Mobile owner approval

1. Wait until the PR is Ready, targets `main`, comes from a same-repository
   `cursor/*` or `replit/*` branch, and its current **PR checks / validate**
   check is green.
2. Copy the PR number and its full 40-character head SHA from the agent's PR
   report.
3. On a signed-in phone or browser, open SaaS Admin → **Deploy Approvals**.
4. Create a request using that PR number and exact head SHA.
5. Review the pinned repository, PR, SHA, expiry, and status. Enter the current
   super-admin password and tap **Approve exact release**.
6. GitHub's scheduled **Approval Relay Dispatch** job picks up the approval.
   GitHub cron is **not** a guaranteed five-minute timer. Observed gaps after
   a successful scheduled run have been hours long (Approval Relay #38 at
   10:31Z, then no schedule until manual #39 at 13:06Z). Staggered crons and
   OIDC curl retries reduce ordinary latency; they cannot force GitHub to fire.
7. TaxNest Admin → Deploy Approvals shows the last authenticated poller
   heartbeat (including empty claims). If an approval is waiting and that
   heartbeat is stale, **do not approve again**. Use GitHub Actions →
   Approval Relay Dispatch → Run workflow.
8. The page records the final deployment result and links to the GitHub run.
   Live verification must pass before anyone reports the release as live.

No GitHub PAT, owner credentials, VPS key, production secret, or shared relay
secret is stored in the web application.

## A PR was manually merged before the relay picked it up

A merged PR disappears from the Admin release selector, which lists only open,
validated PRs. A successful Approval Relay run with zero claims did **not**
authorize a deploy. Do not rerun the relay until a fresh, eligible approval
exists, and do not infer the earlier merge is live.

To recover without bypassing approval or deploying an unreviewed SHA:

1. Read-only verify the merged PR's squash SHA equals the current `main` tip,
   its required checks passed, and no production deployment has succeeded for
   that SHA. Stop and investigate if any identity or deployment status differs.
2. Open a new, focused `cursor/*` or `replit/*` PR **from that exact main tip**
   with a genuine reviewed change, such as documenting the recovery incident.
   This new branch already includes the manually merged code. Never create an
   empty commit or disguise a different code change as release recovery.
3. Let the new PR's checks finish. The owner reviews its diff and the inherited
   main changes, then approves **the new PR and its exact HEAD SHA** in Admin
   using the current super-admin password. Old or expired approval records do
   not authorize the new request.
4. The normal relay merges the new PR and deploys its **new** squash SHA. Verify
   the resulting workflow, deployed SHA, and live acceptance before saying the
   earlier fix is in production. If Admin still shows no eligible PR, inspect
   the new PR's branch, draft status, check result, and GitHub availability
   before taking another action.

This recovery deploys the entire then-current `main`, including the earlier
merge. It does not retroactively deploy the old squash SHA or attribute a
production run to the old approval record. If `main` advances before approval,
reassess the full release scope and never silently substitute a different tip.

## Immediate-dispatch boundary

Guaranteed immediate Admin → GitHub dispatch fundamentally requires a GitHub
App installation token (or another owner-held credential) with `actions:write`
so TaxNest can start **Owner Merge & Deploy** at approval time. Cloud Agents
must not invent, store, or configure that credential. Until the owner makes
that security decision, the durable path is:

- one TaxNest Admin password approval
- OIDC-authenticated scheduled poller + stale-lease retry
- poller heartbeat / delayed-schedule status in Admin
- owner emergency `workflow_dispatch` of Approval Relay Dispatch

Exact-SHA, OIDC, repository, branch, and approval provenance gates stay closed.

## Security binding

Every approval is bound to:

- repository `jawadrao5555-alt/taxnest`
- PR number
- exact 40-character PR head SHA
- authenticated super-admin identity and approval time
- unique request ID
- short expiry

The dispatcher, owner merge workflow, and deploy workflow authenticate to the
relay with short-lived GitHub Actions OIDC tokens. The relay requires the exact
repository, `refs/heads/main`, audience, issuer, and workflow identity.

After approval:

1. The dispatcher leases the request once and starts **Owner Merge & Deploy**.
2. Owner Merge & Deploy revalidates the PR and required check, then claims the
   request once.
3. The relay issues a random owner-workflow receipt once and stores only its
   SHA-256 hash.
4. The workflow squash-merges the pinned head SHA and records the exact squash
   SHA against the approval.
5. The owner workflow creates a random correlation nonce, dispatches Deploy
   Production, selects the earliest exact nonce/SHA run, and consumes the
   receipt while registering that GitHub run ID with the relay.
6. If the approved PR changed `pra-agent/**` or the Agent build workflow, the
   owner workflow also dispatches **Build PRA Agent** with the exact recorded
   squash SHA. This explicit dispatch is required because a merge performed
   with `GITHUB_TOKEN` does not create a second workflow from the resulting
   ordinary `push` event. The Agent workflow checks out and validates that
   full SHA before packaging, so a later `main` commit cannot enter the build.
   Dispatch uses three bounded attempts for transient GitHub API failures.
   Repeated requests remain idempotent: builds serialize by target SHA, and an
   existing version tag on that exact SHA is reported without replacing its
   release assets.
7. **Deploy Production** is authorized only when its OIDC run ID, run attempt,
   workflow SHA, nonce hash, and `target_sha` match the registered run and
   recorded squash SHA.
8. Deploy Production reports success or failure back to the approval record.

Direct `push` deployment is disabled. Deploy Production is
`workflow_dispatch`-only and has no `skip_elaan` or `allow_settings` bypass
inputs.

## What the relay or workflow rejects

- non-super-admin or wrong current password
- expired approval
- wrong repository, PR, request ID, head SHA, workflow, audience, ref, or OIDC signature
- draft, fork, non-`cursor/`/`replit/`, non-main, moved, conflicted, or stale PR
- failed or missing `validate` check
- duplicate claim or provenance receipt replay
- merge SHA different from the current `origin/main` tip
- deploy target different from the recorded squash SHA

## One-time bootstrap

The relay cannot authorize the PR that first introduces it because the relay
code and scheduled workflow are not yet on `main`/production. That PR needs one
final manual owner-controlled merge and existing deployment action. After its
migration and workflows are live, all later releases use the mobile approval
flow above.

This bootstrap does not permit an agent to merge or deploy.
