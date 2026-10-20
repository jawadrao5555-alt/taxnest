# Owner-run signed Android beta

The old Rider example generates a throwaway key and is not a release path.
Use **Prepare signed Rider and Caller beta** (`build-android-beta.yml`) after
this workflow is merged. It is manual-only, runs from `main`, and accepts a
full main-ancestor source SHA. It never hosts files, updates app settings,
creates a GitHub Release, accesses production, or claims device QA.

## One-time owner setup

Create GitHub Environment **android-signing**, restrict it to `main`, and add
an owner reviewer. Put these Environment secrets there, never in chat or git:

- `ANDROID_KEYSTORE_BASE64`: base64 of the EXISTING shared PKCS12 keystore,
  alias `rider`. Never generate a replacement key for an installed app.
- `ANDROID_KEYSTORE_PASSWORD`: existing keystore/alias password.
- `RIDER_FIREBASE_BASE64`: base64 of Rider's matching `google-services.json`.
  No Firebase service-account private key is required or accepted by this job.

Missing inputs fail closed. Existing certificate SHA-256
`490d5c3bae13abb212bfe2a33abac66e8387e623cc0ecd724c5fdd8ffb72245b`
is checked by `apk-release-check.sh`. The workflow does not override it.

## Run and review

Choose `main`, paste the approved full source SHA and run the workflow. Approve
the signing Environment when prompted. Download `android-beta-<SHA>`:
Rider, Caller SIM, Caller Plus and `beta-manifest.json`. Unit tests, APK source
identity/version checks, cryptographic signatures, known Plus permission
exception, Rider Firebase contents and exact-byte provenance must all pass.
The manifest's source/version/code/hash/size and build-input inventory hash
identify the tested bytes; they do not prove delivery, GPS or calls work.

Rider 1.8.0/code 14 and Caller 1.8.0/code 10 remain the current source versions.
The public download metadata stays unchanged until owner phone acceptance.

## Mandatory phone acceptance (record device/Android version and artifact hash)

1. In-place update over the currently installed app; no uninstall/data reset.
2. Rider: duty ON, moving and stationary GPS, map capture age, screen locked,
   network loss/recovery, app/process restart, duty OFF, correct account scope.
   Verify a new-delivery push while the app is closed. Do not count a debug or
   pushless APK as a successful release.
3. Caller SIM and Plus: incoming SIM call, outgoing call exclusion, Plus
   WhatsApp call, cloud/LAN duplicate collapse, internet loss, process death,
   reboot and reconnect. Check account change does not replay another shop's
   rings. Check English/Roman Urdu/Urdu and previously working callback flow.
4. Record PASS/FAIL per case, exact APK SHA-256 and observed limitations. Stop
   on a failed or untested material case; use a focused fix PR.
5. Hosting/promotion and version-setting changes are separate owner operations
   after phone sign-off. This workflow has no SSH or production credentials.

Local manifest tests use synthetic bytes only. Real signed packaging requires
the protected inputs; Android debug compilation in regular PR CI cannot prove
signing or phone acceptance.
