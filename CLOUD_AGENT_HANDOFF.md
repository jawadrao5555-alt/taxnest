# TaxNest Cloud Agent Handoff

Permanent development handoff for Cursor Cloud Agents working on **jawadrao5555-alt/taxnest**.

## Product map

`replit.md` is the authoritative product and operations map (modules, invariants, owner preferences, NestPOS PRA focus). Read the matching `.agents/memory/` topic before editing a subsystem. Do not copy or restate that map here.

## Current focus

Owner focus is **NestPOS PRA** unless the owner explicitly expands scope. Do not propose or implement DI, FBR POS, admin/SaaS, or other-stream work unless asked.

## Git workflow (required)

1. **`main` is protected / read-only** for Cloud Agent work. Never commit application changes on `main`.
2. Before every task: fetch `origin/main` and confirm a clean working tree.
3. Start from the latest `origin/main`.
4. Create **one feature branch per task** from that baseline.
5. Make all edits, commits, and pushes **only** on that feature branch.
6. Commit only the task’s intentional changes.
7. Push the feature branch to GitHub.
8. Open a PR targeting **`main`**.
9. **Do not merge** the PR unless the owner explicitly instructs you to merge.
10. If a change is wrong, **preserve branch/PR history** so the change can be safely reverted. Do not force-rewrite shared history to hide mistakes.

## Testing

- Run appropriate **targeted tests** after changes.
- When feasible, run the full suite with `php artisan test` before declaring a task complete.
- Documentation-only or purely procedural tasks may skip the full suite when application tests are clearly unnecessary.

## Safety / never without explicit owner instruction

- **Never deploy production** unless the owner explicitly instructs you to deploy.
- Do not modify production/staging infrastructure or live customer data unless the task explicitly requires it.
- Never commit secrets, credentials, passwords, tokens, `.env` contents, or `.local` contents.
- Do not change company settings, permissions, feature toggles, or legacy chosen values unless the task explicitly requires it (see `replit.md`).

## This file’s purpose

Keep Cloud Agents on a safe, reusable branch → test → PR path against `main`, with NestPOS PRA as default scope and `replit.md` as the product source of truth.
