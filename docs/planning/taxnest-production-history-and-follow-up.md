# TaxNest production history and follow-up

This is the draft register for findings from the production audit and issues the owner reports. The audit starts at PR #1 and includes the changes reviewed through [#476](https://github.com/jawadrao5555-alt/taxnest/pull/476). Use the deployed commit for each observation. The repository baseline when this draft opened is `c168d713c17a3cf9f6de44aa4fcba7964fe832f5`.

The [per-PR repository inventory](taxnest-pr-history-001-476.md) covers 437 pull requests and 39 separately numbered issues through #476. It links each PR and records the integration commit where established.

## Evidence and status

For each PR, record its feature or fix, GitHub state, related replacement PRs, and the commit that carried the effective change. Then compare it with the deployed commit, permission and rollout settings, and an observed Admin or customer workflow where applicable. A merge documents repository history. A passing deployed check documents the tested production behavior.

Use the terms **Verified live**, **Present in deployed code**, **Merged after deployment**, **Open**, **Superseded**, and **Needs evidence** for their respective findings. Where a capability needs a production flag, tenant role, package, or real device, include that condition in the finding. Treat issue numbers separately from pull requests.

## Audit coverage

| Scope | Evidence to record | Status at opening |
| --- | --- | --- |
| Historical PRs from #1 onward | PR title and purpose, disposition, superseding work, and commit evidence | GitHub inventory linked; production reconciliation pending |
| Public site, ERP packages and demo flows | Public routes, displayed prices and signup controls, and successful signed-in journeys when available | Verify after owner deploys #476 |
| Admin and product workflows | Admin roles, billing and usage, product Company IDs, standing demo records, and available operational evidence | Read-only review after deployment |
| Physical and vendor flows | Signed client/device, printer, biometric, or vendor verification tied to the actual build | Record scoped evidence and dependencies |
| Mobile-reported issues | Observed symptom, expected behavior, reproduction, fix, and test or blocked reason | Awaiting owner reports |

## Findings and mobile issues

| Reference | Area and symptom | Expected behavior and scope | Evidence and deployed SHA | State |
| --- | --- | --- | --- | --- |
| #476 | First post-deploy audit checkpoint | Check the launched functionality and compare against GitHub evidence | Owner approval and verified deploy pending at opening | Awaiting release |

Keep the audit draft open while issues are being collected. Use synthetic fixtures for fixes and record a test for each confirmed gap. Sanitize audit notes and keep customer records, credentials, screenshots with customer data, and payment details out of the PR. Retain the deployed ERP demo companies for owner review.
