# KOT printer claim fix release

Release marker for the production rollout of the shared kitchen KOT printer reliability fix merged in PR #51.

Included main commit:

- `2710250dd28292fa2df01c852d45424c136f0e37`

The functional change prevents a Desktop Agent that has not reported the target kitchen printer from claiming an unstamped KOT job. Both capable cashier PCs remain eligible failover workers, and the existing atomic claim transition continues to prevent duplicate printing.

This documentation-only marker exists so the normal owner-controlled approval relay can create a fresh, auditable merge-and-deploy handoff. It does not bypass relay provenance or production deployment controls.
