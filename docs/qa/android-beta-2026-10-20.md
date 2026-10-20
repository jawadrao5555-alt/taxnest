# Signed beta preparation

Reproduction: only `rider-app/ci/build-rider-app.yml.example` exists; it uses a
throwaway key. No active Android signed-release workflow exists. Source Rider
and Caller 1.8.0 therefore do not establish installable stable-key artifacts.

Change: manual main-only protected signing job for Rider/SIM/Plus, existing
certificate/permission/Firebase/provenance checks, exact-source inventory and
artifact-only delivery. No package IDs, versions, phone detection/tracking,
server contracts, tenant settings or public download metadata change.

Compatibility: existing installed signing lineage is mandatory; Plus retains
its named permission exception; no Firebase-less Rider release; no Play
rollout. Owner phone matrix lives in docs/ops/android-beta-release.md.

Local check: Node artifact-inventory behavior test passes (mutated bytes change
digest; missing artifact and invalid source fail). Workflow YAML and embedded
shell syntax checked. PHP/Gradle/Android SDK/Chrome unavailable locally; regular
PR CI must pass before approval. No stable signing material or real phones
were accessed. Signed packaging and device acceptance remain pending owner
Environment setup and beta execution, not proved by this PR.
