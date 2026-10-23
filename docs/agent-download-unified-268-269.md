# Agent download and company pairing — Issues #268 and #269

**Draft specification only; implementation and tests are not yet included. Do not merge/deploy as a working fix.**

## Shared approved release
All website, admin, company, FBR POS and PRA POS download actions must resolve the same latest approved Windows Agent installer from the canonical validated release manifest (AgentReleaseManifest). Reject invalid/unavailable assets; expose version, build date and validated digest. Audit static/legacy download links and CDN caches, promotion, rollback, ZIP and EXE variants, and auto-update feeds. Preserve access and plan gates per entry point.

## Company-specific setup
Do not embed long-lived API keys or tenant credentials in the public/shared EXE. After authenticated company login, provide a time-limited, single-use company-bound pairing claim for first run, or an equivalent secure exchange. Verify authorized company/admin scope server-side; bind the intended company and show company name/ID confirmation before activation. Never expose tokens in logs, installer filenames, or URLs. Support reinstall, revoked pairing, existing configurations and update recovery; do not silently change PRA/FBR submission routing.

## Diagnostics and tests
Verify all download entry points resolve the same release version and hash, fresh promotion, invalid manifest failure, rollback and caching. Test multi-tenant isolation, cross-company denial, replay, expiry, first-run success, and upgrade retaining correct company identity. Confirm Windows installer and actual app behavior before readiness.
