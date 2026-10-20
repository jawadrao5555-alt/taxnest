# Caller ID durable recovery

Scope: PRA POS Caller ID is first in the owner's three-feature upgrade queue. WhatsApp auto sending remains a separate opt-in feature (default OFF); preserve current manual share/auto-open settings. All three upgrades must obey server-side package/add-on entitlements. This PR does not implement WhatsApp or rider upgrades.

Source reproduction: RingReporter claims dedupe before transport and has no durable outbox, so both transports failing loses the call while suppressing repeat detection. It considers every HTTP 2xx a delivery even when the server explicitly refuses an event for package lock/disabled feature. Backend substitutes current time for a timestamp over ten minutes old, turning recovery into a false live popup.

Compatibility matrix:

| Configuration | Required result |
|---|---|
| Cloud/LAN unavailable, app process restarts | Same saved UUID/time retried, no new identity |
| Cloud reply lost, later retry | UUID duplicate acknowledgement removes pending event |
| Existing fresh LAN pairing | Preserve host/port/token and fresh LAN-first fallback |
| Old event or changed LAN pairing | Cloud history only, never replay through a legacy timestamp-free LAN API |
| Feature disabled/package locked/revoked token | No success timestamp and no fallback after explicit rejection |
| Second account/token | No queue delivery under another credential |
| Older app, no timestamp/UUID | Existing immediate delivery remains supported |
| Existing language/printer/company settings | No reset or reinterpretation |

Local Android, Chrome and PHP executables are unavailable; source inspection is not a physical-phone reproduction. JVM filesystem/transport-policy tests and fresh CI results are recorded below when run. Draft until Android compilation and backend regression checks pass; real phone reconnect/process-death checks and signed APK publication remain required before rollout. Owner controls merge/deploy; no live customer access.

Local validation: `java scripts/tests/CallerOutboxCheck.java .` PASS (30 behavioral assertions exercising the actual Java production classes, filesystem restart, concurrent writers, token isolation, retained timestamp/UUID, dedupe/ack, capacity, corruption and 2xx denial/duplicate/retry policies). `bash scripts/caller-lang-check.sh` PASS for all three flavors/languages. `git diff --check` PASS. PHP and native Android SDK/Gradle unavailable locally; targeted backend and all three unsigned Android builds/unit tasks are required in the existing mandatory CI lanes. No new third-party application dependency.

Concurrency review follow-up: network delivery never holds the credential/enqueue lock, so a new receiver can persist its call while a previous request blocks. Credential changes and queue operations share a short lock. Existing LAN pairing is adopted once and bound to the authenticated session; credential changes retain saved pairing values but require re-pairing before LAN use. A pairing response cannot be committed if the cloud account changed during its request.

First current-source CI passed the 30-assertion production JVM harness under egress blocking. Android setup then failed before compilation because setup-android's default `tools` package is no longer available in the SDK repository. Explicitly request `platform-tools` and the actual pinned SDK/build-tools packages. This is a setup failure, not Android build proof; replacement exact-HEAD CI is mandatory.

## 19 October refresh after live PR213

Integrate deployed main e7137de2b07a0a0f8d7a11d04f845e1dd8d3bf72, preserving Agent1.13.20 packaging/version/date display and PR210/215/216 contrast, address and complete-search fixes. Only the announcement conflicts; retain this Caller ID release announcement. Required CI retains Agent browser identity and address/search coverage plus Caller JVM/Android/backend checks. No caller recovery implementation change during this refresh.

Current local validation: all30 actual-production filesystem/policy behavioral assertions PASS plain and egress-blocked; all three Caller flavor/language guards, whitespace and announcement parsing PASS. Fresh exact-HEAD CI must repeat Android Sim/Plus/Play JUnit/debug builds and targeted backend/package/legacy tests plus every required lane. PHP/Android SDK/Gradle/Chrome unavailable locally; no runtime or physical-phone proof is inferred. Source remains Caller1.8.0/code10. Signed APK publication and real phone restart/reconnect/package/account verification remain separate owner rollout steps; no download metadata changed.
