# Agent dependency-patch release recovery

Build PRA Agent run 39625230208 for deployed main
`d08eeacb5558e8f82d76eb18da0afd07226c0ab5` failed at the version guard.
Tag v1.13.20 already belongs to
`e7137de2b07a0a0f8d7a11d04f845e1dd8d3bf72`. Comparing its Agent/build
inputs against deployed main shows only the locked follow-redirects security
patch from 1.16.0 to 1.16.1. The unchanged package version correctly prevents
overwriting the published release with different bytes.

Advance package.json and both root package-lock version fields to 1.13.21,
and pin the reviewed lock graph. Do not move the old tag, weaken collision
checks, revert the security patch, or change Agent settings/printing behavior.
Existing Agent installations are not updated by opening this PR.

Compatibility: existing v1.13.20 artifacts remain immutable; a missing
v1.13.21 tag uses the normal new-release path; an unexpected Git error still
fails closed; unchanged-input/idempotent releases retain their existing path.
No tenant, printer, fiscal or company configuration is read or changed.

The failure is reproduced from the exact released/current source comparison
and protected build log. Local version consistency, release-chain safety and
lock portability checks are required, followed by all exact-HEAD PR CI gates.
Actual Windows packaging, signed/released artifact availability and installed
fleet updates remain unverified until the owner-approved release build and
independent rollout acceptance. Do not describe this version bump as a shipped
Windows release or as a diagnosis of offline fleet warnings.

Refresh after PR #227: base is deployed main
`7de0ae0e897786881a25105f7e639ec173050b87`. Deploy run 39654969409
passed exact live SHA/marker verification at 18:45:02 UTC on 21 October 2026,
and its relay callback succeeded. The only merge conflict was the announcement:
keep this Agent release note because the tutorial announcement is already live.
Tutorial recording, actual container upload outputs and fail-closed CI contracts
are retained byte-for-byte from main. Fresh all-eight exact-HEAD PR checks are
required; prior checks on the older base are not this refreshed acceptance.
