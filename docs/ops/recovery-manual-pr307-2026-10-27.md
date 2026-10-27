# Recovery after manual merge of PRA notification PR #307

On 27 October 2026, the owner manually squash-merged PR #307 into main as `96f27ba6621406ea9eddbd5fec62fedc686f6641` after the Admin deployment verification reported HTTP 403 and created no approval. The PR's source head was `7f71c78d8ae9203f849bc2c995c0971c067e5e1f`; its own CI run 40488451586 passed the eight required checks, including validate, full PHP and desktop/mobile browser acceptance.

A manual merge is not an approval record and does not itself dispatch production deployment. At the time of this recovery, the merged commit is main's tip; the latest observed successful Deploy Production run was 40458592182 for the earlier #303 commit `a7875f3cb27f1f73b7dc9ea0ebd01c3e2dc43de1`. A newer successful deployment is not claimed.

This documentation-only recovery branch starts from the merged main tip and therefore includes the already-reviewed #307 changes. Before release, review this record and inherited main scope, verify this PR's exact head and all required checks, and obtain a fresh authenticated owner approval for this PR through Admin. The normal approval relay then merges this PR and deploys its new squash commit. Re-check main has not advanced before approving; verify the matching Deploy Production run, live verification and callback before reporting the change live. If GitHub verification still returns 403, investigate availability or rate limits and retry approval; do not infer an approval from a relay run or bypass provenance gates.

References: [PR #307](https://github.com/jawadrao5555-alt/taxnest/pull/307), [its ready-for-review CI run](https://github.com/jawadrao5555-alt/taxnest/actions/runs/40488451586), and [owner workflow policy](owner-merge-and-deploy.md).
