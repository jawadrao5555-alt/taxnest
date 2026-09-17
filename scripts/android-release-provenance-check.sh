#!/usr/bin/env bash
# Canonical Android release provenance guard. Run alongside apk-release-check
# before a hosted APK is replaced:
#
# bash scripts/android-release-provenance-check.sh --manifest release.json \
#   --source-sha "$(git rev-parse HEAD)" --build-inputs-sha "$APP_INPUTS_SHA" apk...
#
# APP_INPUTS_SHA is the SHA-256 of the approved out-of-band build-input
# inventory (Firebase/signing/config inputs). It records provenance without
# committing those sensitive inputs or their paths to this repository.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
MANIFEST=""
SOURCE_SHA=""
BUILD_INPUTS_SHA=""
APKS=()
while (($#)); do
  case "$1" in
    --manifest) MANIFEST="${2:-}"; shift 2 ;;
    --source-sha) SOURCE_SHA="${2:-}"; shift 2 ;;
    --build-inputs-sha) BUILD_INPUTS_SHA="${2:-}"; shift 2 ;;
    *) APKS+=("$1"); shift ;;
  esac
done
[[ -n "$MANIFEST" && -n "$SOURCE_SHA" && -n "$BUILD_INPUTS_SHA" && ${#APKS[@]} -gt 0 ]] || {
  echo "Usage: $0 --manifest release.json --source-sha <40-hex> --build-inputs-sha <64-hex> <apk>..." >&2; exit 2; }
node - "$MANIFEST" "$SOURCE_SHA" "$BUILD_INPUTS_SHA" "${APKS[@]}" <<'NODE'
const fs = require('fs');
const crypto = require('crypto');
const [manifestPath, sourceSha, inputsSha, ...files] = process.argv.slice(2);
const fail = (message) => { console.error(`ANDROID PROVENANCE FAIL: ${message}`); process.exit(1); };
if (!/^[a-f0-9]{40}$/i.test(sourceSha) || !/^[a-f0-9]{64}$/i.test(inputsSha)) fail('caller supplied malformed source/build-input SHA');
let manifest;
try { manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8')); } catch (_) { fail('manifest is unreadable JSON'); }
if (!manifest || manifest.schema_version !== 1 || manifest.product !== 'taxnest-android-release'
  || String(manifest.source_sha || '').toLowerCase() !== sourceSha.toLowerCase()
  || String(manifest.build_inputs_sha256 || '').toLowerCase() !== inputsSha.toLowerCase()
  || !Array.isArray(manifest.artifacts)) fail('manifest identity or provenance binding is invalid');
const entries = new Map();
for (const artifact of manifest.artifacts) {
  if (!artifact || typeof artifact.name !== 'string' || entries.has(artifact.name)
    || !/^[a-f0-9]{64}$/i.test(String(artifact.sha256 || ''))
    || !Number.isSafeInteger(artifact.size) || artifact.size <= 0
    || typeof artifact.package !== 'string' || !/^\d{1,2}\.\d+\.\d+$/.test(String(artifact.version || ''))
    || !Number.isSafeInteger(artifact.version_code) || artifact.version_code <= 0) {
    fail('manifest artifact schema is invalid');
  }
  entries.set(artifact.name, artifact);
}
if (entries.size !== files.length) fail('manifest must contain exactly the artifacts being released');
for (const file of files) {
  let stat; try { stat = fs.statSync(file); } catch (_) { fail(`missing artifact ${file}`); }
  const name = require('path').basename(file);
  const entry = entries.get(name);
  if (!entry) fail(`artifact ${name} is not canonical in manifest`);
  const digest = crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
  if (stat.size !== entry.size || digest !== String(entry.sha256).toLowerCase()) fail(`artifact bytes differ from manifest: ${name}`);
}
console.log(`ANDROID PROVENANCE PASS: ${files.length} exact artifact(s) bound to ${sourceSha.slice(0, 12)}`);
NODE