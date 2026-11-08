#!/usr/bin/env bash
# Pre-provision the unchanged Gradle version from an official verified asset.
# setup-gradle still performs cache, wrapper validation and version checks.
set -Eeuo pipefail
[[ -n "${RUNNER_TEMP:-}" && -n "${GITHUB_PATH:-}" ]] || { echo 'GitHub CI runtime paths are required' >&2; exit 2; }
[[ "$RUNNER_TEMP" == /* && -d "$RUNNER_TEMP" && ! -L "$RUNNER_TEMP" ]] || exit 2
version='8.11.1'
expected='f397b287023acdba1e9f6fc5ea72d22dd63669d59ed4a289a29b1a76eee151c6'
stage="$(mktemp -d "$RUNNER_TEMP/taxnest-gradle-runtime-XXXXXX")"
archive="$stage/gradle.zip"
trap 'rm -f -- "$archive"' EXIT
if ! curl --fail --location --silent --show-error --retry 2 --retry-delay 2 --retry-all-errors \
  --connect-timeout 20 --max-time 180 'https://downloads.gradle.org/distributions/gradle-8.11.1-bin.zip' -o "$archive"; then
  curl --fail --location --silent --show-error --retry 3 --retry-delay 2 --retry-all-errors \
    --connect-timeout 20 --max-time 180 -H 'Accept: application/octet-stream' \
    -H 'X-GitHub-Api-Version: 2022-11-28' \
    'https://api.github.com/repos/gradle/gradle-distributions/releases/assets/207876967' -o "$archive"
fi
printf '%s  %s\n' "$expected" "$archive" | sha256sum --check --status
python3 - "$archive" <<'PY'
import pathlib,sys,zipfile
with zipfile.ZipFile(sys.argv[1]) as archive:
    for item in archive.infolist():
        path=pathlib.PurePosixPath(item.filename)
        if path.is_absolute() or '..' in path.parts:
            raise SystemExit('Unsafe Gradle archive path')
PY
unzip -q "$archive" -d "$stage"
"$stage/gradle-$version/bin/gradle" --console=plain --version | grep -Fx "Gradle $version"
printf '%s\n' "$stage/gradle-$version/bin" >> "$GITHUB_PATH"
echo 'Official Gradle 8.11.1 distribution SHA-256 and executable version verified; setup-gradle and all builds remain required.'
