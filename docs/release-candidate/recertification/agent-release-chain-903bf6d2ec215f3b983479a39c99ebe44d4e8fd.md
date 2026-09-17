# Agent release-chain gate reproduction

- Generated UTC: `2026-09-16T12:58:10.964682Z`
- Requested source SHA: `903bf6d2ec215f3b983479a39c99ebe44d4e8fd4`
- Command: `bash scripts/rc-safe-run -- bash scripts/tests/agent-release-chain-check.sh`
- Scope: loopback-only guarded static checker; no GitHub calls, dispatch, merge, build, or release.

## Independent committed 903 clone (baseline workflows)

This is the actual baseline workflow gate reproduction. The independent clone was clean before execution and did not use the held local workflow proposal.

**Result: FAIL — 4 failures / 10 passes, exit `1`.**

- Durable raw-log hash: `9f0dacf5da706720a4923f2bcdecd894965f73d4fbdb4d3b2b4e97bbdde49e48`
- Baseline `.github/workflows/build-agent.yml` SHA-256: `7726ae3c64cb05576718a620e2625b4ee272be841ba41375a281b6cb1a3b1703`
- Baseline `.github/workflows/owner-merge-and-deploy.yml` SHA-256: `050de335d796e599e1e494595ac3e82fd7537942c1e72b9eb705516f239f968d`
- Baseline `scripts/owner-merge-and-deploy.sh` SHA-256: `386aac9923d38a649aa7c69ffc14caf736cf1a5e98507305f1bd9e458c52faba`

### Exact failing check names

- `Agent build must pin reviewed Node 22 runtime`
- `Agent build must use npm ci rather than a mutable install`
- `Agent canonical release manifest metadata is incomplete`
- `Agent tag lookup LASTEXITCODE handling is unsafe`

### Passing check count

`10` exact `PASS:` lines were emitted by the baseline gate.

## Held local workflow proposal (separate evidence)

The local proposal was evaluated separately and is not substituted for the baseline reproduction.

**Result: PASS — 14 passes / 0 failures, exit `0`.**

- Durable raw-log hash: `3a691f884d578af08cd8cdc52209ec5a83191d18752891a166af9c9df6521fac`

The baseline Node 22, npm-ci, and canonical-manifest failures are therefore recorded as an external workflow-baseline blocker rather than fixed by switching the evidence to the local proposal.
