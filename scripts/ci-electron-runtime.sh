#!/usr/bin/env bash
# Keep the locked Electron runtime and all native acceptance; bypass only a
# failing GitHub release front door, using its official API asset and digest.
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
version='42.11.16'
expected='e27e9ec78d12edc83c817a1454b706530c000eedd217f65aed2a5d9657ec1be0'
electron_root="$ROOT/pra-agent/node_modules/electron"
[[ "$(uname -s)" == Linux && "$(uname -m)" == x86_64 ]] || { echo 'Unsupported native Electron CI platform' >&2; exit 2; }
[[ -d "$electron_root" && ! -L "$electron_root" ]] || { echo 'Locked Electron package is missing' >&2; exit 2; }
[[ "$(node -p "require('./pra-agent/node_modules/electron/package.json').version")" == "$version" ]] || {
  echo 'Locked Electron version does not match the verified runtime' >&2; exit 2;
}
if node "$electron_root/install.js" && [[ -x "$electron_root/dist/electron" && -f "$electron_root/dist/version" ]] &&
  [[ "$(tr -d '\r\n' < "$electron_root/dist/version")" == "$version" ]]; then
  echo 'Pinned Electron runtime installed through its standard verified installer.'
  exit 0
fi
printf '%s\n' 'Standard Electron download failed; retrying the same official release asset through GitHub API.'
archive="$(mktemp /tmp/taxnest-electron-runtime-XXXXXX.zip)"
trap 'rm -f -- "$archive"' EXIT
curl --fail --location --silent --show-error --retry 3 --retry-delay 2 --retry-all-errors \
  --connect-timeout 20 --max-time 180 -H 'Accept: application/octet-stream' \
  -H 'X-GitHub-Api-Version: 2022-11-28' \
  'https://api.github.com/repos/electron/electron/releases/assets/657960598' -o "$archive"
printf '%s  %s\n' "$expected" "$archive" | sha256sum --check --status
python3 - "$archive" <<'PY'
import pathlib, sys, zipfile
with zipfile.ZipFile(sys.argv[1]) as archive:
    for item in archive.infolist():
        path = pathlib.PurePosixPath(item.filename)
        if path.is_absolute() or '..' in path.parts:
            raise SystemExit('Unsafe runtime archive path')
PY
[[ ! -L "$electron_root/dist" ]] || { echo 'Refusing a linked runtime directory' >&2; exit 2; }
rm -rf -- "$electron_root/dist"
mkdir -p "$electron_root/dist"
unzip -q "$archive" -d "$electron_root/dist"
[[ "$(tr -d '\r\n' < "$electron_root/dist/version")" == "$version" ]] || { echo 'Runtime version mismatch' >&2; exit 2; }
[[ -x "$electron_root/dist/electron" ]] || { echo 'Verified Electron executable missing' >&2; exit 2; }
printf 'electron' > "$electron_root/path.txt"
node -e "require('fs').accessSync(require('./pra-agent/node_modules/electron'))"
echo 'Pinned Electron runtime installed from official API asset; exact SHA-256 and version verified.'
