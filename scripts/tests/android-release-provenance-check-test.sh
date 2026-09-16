#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
printf 'canonical-apk-bytes' > "$TMP/taxnest-pos.apk"
SHA="$(sha256sum "$TMP/taxnest-pos.apk" | awk '{print $1}')"
cat > "$TMP/manifest.json" <<EOF
{"schema_version":1,"product":"taxnest-android-release","source_sha":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","build_inputs_sha256":"$SHA","artifacts":[{"name":"taxnest-pos.apk","package":"pk.taxnest.pos","version":"1.2.3","version_code":7,"sha256":"$SHA","size":19}]}
EOF
bash "$ROOT/scripts/android-release-provenance-check.sh" --manifest "$TMP/manifest.json" \
  --source-sha aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa --build-inputs-sha "$SHA" "$TMP/taxnest-pos.apk"
printf 'x' >> "$TMP/taxnest-pos.apk"
if bash "$ROOT/scripts/android-release-provenance-check.sh" --manifest "$TMP/manifest.json" \
  --source-sha aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa --build-inputs-sha "$SHA" "$TMP/taxnest-pos.apk"; then
  echo "expected byte mismatch rejection" >&2; exit 1
fi
echo "android-release-provenance-check test: all cases pass"