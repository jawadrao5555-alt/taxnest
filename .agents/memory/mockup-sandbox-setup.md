---
name: Mockup sandbox setup quirks
description: How to get the canvas mockup sandbox dev server running in this workspace
---

- After `createArtifact({artifactType:"mockup-sandbox"})`, the bundled `node_modules`/`package-lock.json` can be corrupt: `npm install` fails with `Invalid Version:` (arborist dedupe crash). Fix: `rm -rf node_modules package-lock.json && npm install` inside `artifacts/mockup-sandbox/` — then the "artifacts/mockup-sandbox: Component Preview Server" workflow starts fine.
- **Why:** first workflow start fails with `ERR_MODULE_NOT_FOUND @vitejs/plugin-react` because deps never installed; a plain `npm install` on top of the shipped lockfile hits the Invalid Version crash.
- **How to apply:** whenever the mockup sandbox workflow fails on boot with missing vite plugins, clean-install before debugging anything else.
- Preview URLs are path-based via `https://$REPLIT_DOMAINS/__mockup/preview/{folder}/{Component}` — no port; all four NestPOS mockups verified 200 this way.
- The `screenshot` tool CANNOT capture `/__mockup/` paths — it hits the Laravel workflow on port 5000 directly (ERR_CONNECTION_CLOSED), not the edge proxy. Verify previews via curl 200 + the generated registry `src/.generated/mockup-components.ts` + vite logs instead.
- `presentArtifact` needs `artifactId: "artifacts/mockup-sandbox"` — re-calling `createArtifact` errors ("only one allowed"); get the id via `listArtifacts()` in code_execution.
