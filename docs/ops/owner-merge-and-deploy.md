# Owner Merge & Deploy

Agents implement, test, and open **one focused `cursor/*` PR**, then **STOP**.
They must not merge, approve, or dispatch production.

The owner authorizes an exact release from the authenticated TaxNest SaaS admin
panel. Plain chat text is intent, not authentication.

## Mobile owner approval

1. Wait until the PR is Ready, targets `main`, comes from a same-repository
   `cursor/*` branch, and its current **PR checks / validate** check is green.
2. Copy the PR number and its full 40-character head SHA from the agent's PR
   report.
3. On a signed-in phone or browser, open SaaS Admin → **Deploy Approvals**.
4. Create a request using that PR number and exact head SHA.
5. Review the pinned repository, PR, SHA, expiry, and status. Enter the current
   super-admin password and tap **Approve exact release**.
6. GitHub's scheduled **Approval Relay Dispatch** job picks up the approval.
   GitHub schedules normally run within about five minutes, but may be delayed.
7. The page records the final deployment result and links to the GitHub run.
   Live verification must pass before anyone reports the release as live.

No GitHub PAT, owner credentials, VPS key, production secret, or shared relay
secret is stored in the web application.

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
6. **Deploy Production** is authorized only when its OIDC run ID, run attempt,
   workflow SHA, nonce hash, and `target_sha` match the registered run and
   recorded squash SHA.
7. Deploy Production reports success or failure back to the approval record.

Direct `push` deployment is disabled. Deploy Production is
`workflow_dispatch`-only and has no `skip_elaan` or `allow_settings` bypass
inputs.

## What the relay or workflow rejects

- non-super-admin or wrong current password
- expired approval
- wrong repository, PR, request ID, head SHA, workflow, audience, ref, or OIDC signature
- draft, fork, non-`cursor/`, non-main, moved, conflicted, or stale PR
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