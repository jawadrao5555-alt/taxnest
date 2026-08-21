#!/bin/bash
# TaxNest APK release guard — run on EVERY built APK BEFORE it is hosted.
#
# Real incident (Caller ID v1.0.0): the manifest declared
# BIND_NOTIFICATION_LISTENER_SERVICE, and Google Play Protect's "enhanced fraud
# protection" silently refuses to install ANY sideloaded APK (browser /
# WhatsApp / file manager) that declares one of exactly four permissions:
#
#     RECEIVE_SMS · READ_SMS · BIND_NOTIFICATION_LISTENER_SERVICE
#     BIND_ACCESSIBILITY_SERVICE
#
# Every shop — and the owner — got "App blocked to protect your device" /
# "App not installed". Nothing in the build fails; you only find out on a phone.
# One permission line in any of the six apps (POS, FBR POS, DI, Waiter, Rider,
# Caller ID) can do it again, so this check is a script, not a step someone
# remembers.
#
# What it checks, per APK:
#   1. BLOCKED PERMISSIONS — the four above, anywhere in the manifest
#      (<uses-permission>, and android:permission on a service/receiver —
#      the listener/accessibility ones are declared that way).
#   2. SIGNING KEY — must be the shared rider key (CN=TaxNest Rider). A wrong
#      key means no in-place update: every phone would have to uninstall first.
#   3. IDENTITY — prints package + versionName/versionCode (+ min/target SDK),
#      and cross-checks them against the module's app/build.gradle so a stale
#      APK built before the version bump (a re-used versionCode) is caught.
#
# Known exception: the Caller ID "plus" build (SIM + WhatsApp) is ALLOWED to
# carry the notification listener — it is never the default download. The check
# reports it as a loud, named exception, never as a silent pass, and still fails
# if that build is about to be hosted under the default download name.
#
# Usage:
#   bash scripts/apk-release-check.sh <apk> [<apk> ...]
#   bash scripts/apk-release-check.sh --expect-version 1.4.0 --expect-code 5 <apk>
#   bash scripts/apk-release-check.sh --strict <apk>          # exceptions FAIL too
#   bash scripts/apk-release-check.sh --no-source-check <apk> # e.g. an old APK
#                                                             # pulled back for a rollback
#
# Needs only python3 (the manifest and the signing block are parsed directly —
# no Android SDK required). keytool (JDK) is used for the certificate subject
# when present; apksigner (if ANDROID_HOME is set up) additionally verifies the
# signature itself.
#
# Exit codes: 0 = all clear, 1 = FAILURE (do not host), 2 = could not run.
set -uo pipefail
ORIG_PWD="$PWD"           # APK paths are resolved against the caller's cwd…
cd "$(dirname "$0")/.."   # …everything else against the repo root.

STRICT=0
NO_SOURCE_CHECK=0
EXPECT_VERSION=""
EXPECT_CODE=""
APKS=()

while [ $# -gt 0 ]; do
  case "$1" in
    --strict)          STRICT=1; shift ;;
    --no-source-check) NO_SOURCE_CHECK=1; shift ;;
    --expect-version)  EXPECT_VERSION="${2:-}"; shift 2 ;;
    --expect-code)     EXPECT_CODE="${2:-}"; shift 2 ;;
    -h|--help)
      awk 'NR>1 && /^#/ { sub(/^# ?/, ""); print; next } NR>1 { exit }' "$0"
      exit 0 ;;
    -*)
      echo "ERROR: unknown option $1 (try --help)" >&2; exit 2 ;;
    *)  APKS+=("$1"); shift ;;
  esac
done

if [ ${#APKS[@]} -eq 0 ]; then
  echo "ERROR: no APK given." >&2
  echo "Usage: bash scripts/apk-release-check.sh [--strict] [--no-source-check] [--expect-version X.Y.Z] [--expect-code N] <apk> [<apk> ...]" >&2
  exit 2
fi
command -v python3 >/dev/null 2>&1 || { echo "ERROR: python3 not found." >&2; exit 2; }

FAIL=0
EXCEPTIONS=0

for apk in "${APKS[@]}"; do
  case "$apk" in
    /*) ;;
    *)  [ -f "$apk" ] || [ ! -f "$ORIG_PWD/$apk" ] || apk="$ORIG_PWD/$apk" ;;
  esac
  echo "==> $apk"
  if [ ! -f "$apk" ]; then
    echo "    FAIL: file not found." >&2
    FAIL=1
    continue
  fi
  STRICT="$STRICT" NO_SOURCE_CHECK="$NO_SOURCE_CHECK" \
  EXPECT_VERSION="$EXPECT_VERSION" EXPECT_CODE="$EXPECT_CODE" \
  python3 - "$apk" <<'PYEOF'
import hashlib
import os
import re
import shutil
import struct
import subprocess
import sys
import tempfile
import zipfile

apk_path = sys.argv[1]
STRICT = os.environ.get("STRICT") == "1"
NO_SOURCE_CHECK = os.environ.get("NO_SOURCE_CHECK") == "1"
EXPECT_VERSION = os.environ.get("EXPECT_VERSION") or ""
EXPECT_CODE = os.environ.get("EXPECT_CODE") or ""

# Play Protect's enhanced fraud protection blocks a sideload when the manifest
# declares any of these. Never widen this list without a phone test.
BLOCKED = [
    "android.permission.RECEIVE_SMS",
    "android.permission.READ_SMS",
    "android.permission.BIND_NOTIFICATION_LISTENER_SERVICE",
    "android.permission.BIND_ACCESSIBILITY_SERVICE",
]

# Shared rider keystore (.local/rider-signing/rider-release.p12, alias `rider`).
# A certificate fingerprint is public — it ships inside every APK — but the
# keystore and its password never leave .local/ (this repo is PUBLIC).
RIDER_CERT_SHA256 = os.environ.get(
    "RIDER_CERT_SHA256",
    "490d5c3bae13abb212bfe2a33abac66e8387e623cc0ecd724c5fdd8ffb72245b",
).lower().replace(":", "")

# package -> (module dir, human name, canonical DEFAULT download filename)
APPS = {
    "pk.taxnest.pos":      ("pos-app",     "TaxNest POS shell (PRA)",  "taxnest-pos.apk"),
    "pk.taxnest.fbrpos":   ("fbr-pos-app", "TaxNest FBR POS shell",    "taxnest-fbr-pos.apk"),
    "pk.taxnest.di":       ("di-app",      "TaxNest DI shell",         "taxnest-di.apk"),
    "pk.taxnest.waiter":   ("waiter-app",  "TaxNest Waiter shell",     "taxnest-waiter.apk"),
    "pk.taxnest.rider":    ("rider-app",   "TaxNest Rider",            "taxnest-rider.apk"),
    "pk.taxnest.callerid": ("caller-app",  "TaxNest Caller ID",        "taxnest-caller.apk"),
}

# Filenames that ARE the default download (or the clean build that becomes it).
# A blocked permission under one of these names is the v1.0.0 disaster itself,
# so the Caller ID exception below never applies to them.
DEFAULT_NAMES = {v[2] for v in APPS.values()} | {"app-sim-release.apk", "app-release.apk"}

fails = []
notes = []
exception_hit = False


def out(line):
    print("    " + line)


def bad(line):
    fails.append(line)


# ----------------------------------------------------------------------
# Binary AndroidManifest.xml (AXML) parser — same data aapt2 dump xmltree
# prints, without needing the Android SDK installed.
# ----------------------------------------------------------------------
def parse_axml(data):
    elements = []   # (tag, {attr: value})
    strings = []
    resmap = []
    try:
        _magic, header_size, _fsize = struct.unpack_from("<HHI", data, 0)
    except struct.error:
        return elements, strings
    pos = header_size
    while pos + 8 <= len(data):
        ctype, chunk_header, chunk_size = struct.unpack_from("<HHI", data, pos)
        if chunk_size <= 0 or pos + chunk_size > len(data):
            break
        if ctype == 0x0001:  # RES_STRING_POOL_TYPE
            count, _styles, flags, strings_start, _st = struct.unpack_from("<IIIII", data, pos + 8)
            utf8 = bool(flags & (1 << 8))
            offsets = struct.unpack_from("<%dI" % count, data, pos + 28)
            base = pos + strings_start
            for off in offsets:
                p = base + off
                if p >= len(data):
                    strings.append("")
                    continue
                if utf8:
                    n = data[p]; p += 1
                    if n & 0x80:
                        p += 1
                    n = data[p]; p += 1
                    if n & 0x80:
                        n = ((n & 0x7F) << 8) | data[p]; p += 1
                    strings.append(data[p:p + n].decode("utf-8", "replace"))
                else:
                    n = struct.unpack_from("<H", data, p)[0]; p += 2
                    if n & 0x8000:
                        n = ((n & 0x7FFF) << 16) | struct.unpack_from("<H", data, p)[0]; p += 2
                    strings.append(data[p:p + n * 2].decode("utf-16le", "replace"))
        elif ctype == 0x0180:  # RES_XML_RESOURCE_MAP_TYPE
            n = (chunk_size - chunk_header) // 4
            resmap = list(struct.unpack_from("<%dI" % n, data, pos + chunk_header))
        elif ctype == 0x0102:  # RES_XML_START_ELEMENT_TYPE
            p = pos + chunk_header
            _ns, name_i, attr_start, _attr_size, attr_count = struct.unpack_from("<IIHHH", data, p)
            tag = strings[name_i] if name_i < len(strings) else "?"
            attrs = {}
            ap = p + attr_start
            for _ in range(attr_count):
                _a_ns, a_name, _raw = struct.unpack_from("<III", data, ap)
                dtype = data[ap + 15]
                adata = struct.unpack_from("<I", data, ap + 16)[0]
                key = strings[a_name] if a_name < len(strings) else ""
                if not key and a_name < len(resmap):
                    key = "0x%08x" % resmap[a_name]
                if dtype == 0x03:      # TYPE_STRING
                    val = strings[adata] if adata < len(strings) else ""
                elif dtype == 0x12:    # TYPE_INT_BOOLEAN
                    val = "true" if adata else "false"
                elif dtype == 0x11:    # TYPE_INT_HEX
                    val = "0x%x" % adata
                elif dtype == 0x01:    # TYPE_REFERENCE
                    val = "@0x%08x" % adata
                else:
                    val = str(adata)
                attrs[key] = val
                ap += 20
            elements.append((tag, attrs))
        pos += chunk_size
    return elements, strings


# ----------------------------------------------------------------------
# Signer certificate — read straight out of the APK Signing Block (v2/v3),
# falling back to a v1 (JAR) signature.
# ----------------------------------------------------------------------
SIG_MAGIC = b"APK Sig Block 42"
SCHEME_IDS = {0x7109871A: "v2", 0xF05368C0: "v3", 0x1B93AD61: "v3.1"}


def _len_prefixed(buf):
    p = 0
    while p + 4 <= len(buf):
        n = struct.unpack_from("<I", buf, p)[0]
        if n <= 0 or p + 4 + n > len(buf):
            break
        yield buf[p + 4:p + 4 + n]
        p += 4 + n


def signer_certificate(data):
    """Return (der_bytes, [schemes]) for the first signer, or (None, [])."""
    eocd = data.rfind(b"PK\x05\x06", max(0, len(data) - 65557))
    if eocd < 0:
        return None, []
    cd_offset = struct.unpack_from("<I", data, eocd + 16)[0]
    if cd_offset < 32 or data[cd_offset - 16:cd_offset] != SIG_MAGIC:
        return None, []
    size = struct.unpack_from("<Q", data, cd_offset - 24)[0]
    start = cd_offset - size - 8
    if start < 0 or struct.unpack_from("<Q", data, start)[0] != size:
        return None, []
    block = data[start + 8:cd_offset - 24]
    der, schemes = None, []
    p = 0
    while p + 12 <= len(block):
        pair_len = struct.unpack_from("<Q", block, p)[0]
        if pair_len <= 4 or p + 8 + pair_len > len(block):
            break
        pair_id = struct.unpack_from("<I", block, p + 8)[0]
        value = block[p + 12:p + 8 + pair_len]
        if pair_id in SCHEME_IDS:
            schemes.append(SCHEME_IDS[pair_id])
            if der is None:
                for signers in _len_prefixed(value):
                    for signer in _len_prefixed(signers):
                        parts = list(_len_prefixed(signer))
                        if not parts:
                            continue
                        signed_data = list(_len_prefixed(parts[0]))
                        if len(signed_data) >= 2:      # [0] digests, [1] certificates
                            for cert in _len_prefixed(signed_data[1]):
                                der = cert
                                break
                        break
                    break
        p += 8 + pair_len
    return der, schemes


def cert_subject(der):
    """Certificate subject line — keytool when available, else the CN out of the DER."""
    keytool = shutil.which("keytool")
    if not keytool and os.environ.get("JAVA_HOME"):
        cand = os.path.join(os.environ["JAVA_HOME"], "bin", "keytool")
        keytool = cand if os.path.exists(cand) else None
    if keytool:
        tmp = tempfile.NamedTemporaryFile(suffix=".der", delete=False)
        try:
            tmp.write(der)
            tmp.close()
            r = subprocess.run([keytool, "-printcert", "-file", tmp.name],
                               capture_output=True, text=True)
            for line in r.stdout.splitlines():
                if line.startswith("Owner:"):
                    return line.split(":", 1)[1].strip()
        finally:
            os.unlink(tmp.name)
    # Minimal fallback: last CN (OID 2.5.4.3) in the DER = the subject's.
    cn = None
    for m in re.finditer(b"\x06\x03\x55\x04\x03", der):
        p = m.end()
        if p + 2 <= len(der) and der[p] in (0x0C, 0x13, 0x16):
            n = der[p + 1]
            cn = der[p + 2:p + 2 + n].decode("utf-8", "replace")
    return "CN=" + cn if cn else "unknown"


# ----------------------------------------------------------------------
# Read the APK
# ----------------------------------------------------------------------
try:
    raw = open(apk_path, "rb").read()
    with zipfile.ZipFile(apk_path) as z:
        manifest_bytes = z.read("AndroidManifest.xml")
except (OSError, KeyError, zipfile.BadZipFile) as e:
    print("    FAIL: not a readable APK (%s)" % e, file=sys.stderr)
    sys.exit(1)

elements, pool = parse_axml(manifest_bytes)
if not elements:
    print("    FAIL: could not parse AndroidManifest.xml — is this a real APK?", file=sys.stderr)
    sys.exit(1)

manifest_attrs = elements[0][1]
package = manifest_attrs.get("package", "?")
version_code = manifest_attrs.get("versionCode", "?")
version_name = manifest_attrs.get("versionName", "?")
min_sdk = target_sdk = "?"
for tag, attrs in elements:
    if tag == "uses-sdk":
        min_sdk = attrs.get("minSdkVersion", "?")
        target_sdk = attrs.get("targetSdkVersion", min_sdk)
        break

module, human, default_name = APPS.get(package, (None, "UNKNOWN app", None))
basename = os.path.basename(apk_path)

out("package      %s  (%s)" % (package, human))
out("version      %s  (versionCode %s)" % (version_name, version_code))
out("sdk          minSdk %s, targetSdk %s" % (min_sdk, target_sdk))
if module is None:
    notes.append("package %s is not one of the six TaxNest apps — check you built the right thing" % package)

# ----------------------------------------------------------------------
# 1. Blocked permissions (the whole manifest, not just <uses-permission>)
# ----------------------------------------------------------------------
declared = []
found = {}
for tag, attrs in elements:
    if tag == "uses-permission" or tag == "uses-permission-sdk-23":
        name = attrs.get("name", "")
        if name:
            declared.append(name)
    for key, val in attrs.items():
        if not val or not val.startswith("android.permission."):
            continue
        for b in BLOCKED:
            if val.lower() == b.lower():
                where = "<%s>" % tag if tag == "uses-permission" else "<%s %s=…>" % (tag, key)
                found.setdefault(b, set()).add(where)
# Backstop: a declaration this walk somehow missed still leaves the permission
# string in the manifest's string pool.
for s in pool:
    for b in BLOCKED:
        if s.lower() == b.lower() and b not in found:
            found.setdefault(b, set()).add("manifest string pool")

out("permissions  %d declared" % len(declared))

# The Caller ID "plus" build is the one allowed carrier of the listener.
caller_plus_exception = (
    package == "pk.taxnest.callerid"
    and set(found) == {"android.permission.BIND_NOTIFICATION_LISTENER_SERVICE"}
    and basename not in DEFAULT_NAMES
)

if not found:
    out("OK: none of Play Protect's four blocked permissions are in the manifest.")
elif caller_plus_exception:
    exception_hit = True
    out("EXCEPTION (intentional — this is NOT a clean pass):")
    out("  BIND_NOTIFICATION_LISTENER_SERVICE %s"
        % ", ".join(sorted(found["android.permission.BIND_NOTIFICATION_LISTENER_SERVICE"])))
    out("  Caller ID notification build (the 'plus' SIM+WhatsApp download; the Play")
    out("  flavor carries it too). Play Protect WILL block a sideload of this file —")
    out("  allowed ONLY because it is never the default download. Host it as")
    out("  taxnest-caller-plus.apk and leave taxnest-caller.apk on the clean 'sim'")
    out("  build. Any other app hitting this line is a real bug.")
    if STRICT:
        bad("--strict: the known Caller ID listener exception is being treated as a failure")
else:
    for b in sorted(found):
        out("BLOCKED: %s declared in %s" % (b, ", ".join(sorted(found[b]))))
    bad("Play Protect blocked permission in the manifest — sideloading this APK from the "
        "website/WhatsApp will fail with \"App blocked to protect your device\" on every "
        "phone. Remove the permission (or move the feature into a separate non-default build).")
    if basename in DEFAULT_NAMES and package == "pk.taxnest.callerid":
        bad("...and this file is named like the DEFAULT download (%s) — that is exactly the "
            "v1.0.0 incident. The default download must always be the clean 'sim' build." % basename)

# ----------------------------------------------------------------------
# 2. Signing key
# ----------------------------------------------------------------------
der, schemes = signer_certificate(raw)
if der is None:
    # v1 (JAR) signature fallback — apksigner-less environments still catch
    # "you built an unsigned APK".
    with zipfile.ZipFile(apk_path) as z:
        v1 = [n for n in z.namelist()
              if n.upper().startswith("META-INF/") and n.upper().endswith((".RSA", ".DSA", ".EC"))]
    if v1:
        bad("APK has only a v1 (JAR) signature — the release build must be v2-signed by "
            "Gradle with the shared rider keystore (RIDER_KS/RIDER_KS_PASS were probably unset)")
    else:
        bad("APK is UNSIGNED (no APK Signing Block) — RIDER_KS / RIDER_KS_PASS were not set "
            "when Gradle built it; an unsigned APK cannot be installed at all")
else:
    digest = hashlib.sha256(der).hexdigest()
    subject = cert_subject(der)
    pretty = ":".join(digest[i:i + 2] for i in range(0, len(digest), 2)).upper()
    if digest == RIDER_CERT_SHA256:
        out("signer       %s  [shared rider key, %s]" % (subject, "+".join(schemes) or "v2"))
        out("             SHA-256 %s" % pretty)
        out("OK: signed with the shared TaxNest rider key.")
    else:
        out("signer       %s  [%s]" % (subject, "+".join(schemes) or "v2"))
        out("             SHA-256 %s" % pretty)
        bad("signed with an UNKNOWN key — every TaxNest APK must use the shared rider "
            "keystore (.local/rider-signing/rider-release.p12, alias `rider`). Hosting a "
            "differently-signed APK breaks in-place updates: phones get \"App not installed\" "
            "until the user uninstalls the old app first.")

# apksigner, when the SDK happens to be installed, also proves the signature
# actually verifies over the file's contents (we only read the certificate).
apksigner = shutil.which("apksigner")
if not apksigner:
    home = os.environ.get("ANDROID_HOME") or os.environ.get("ANDROID_SDK_ROOT") or ""
    bt = os.path.join(home, "build-tools")
    if home and os.path.isdir(bt):
        for d in sorted(os.listdir(bt), reverse=True):
            cand = os.path.join(bt, d, "apksigner")
            if os.path.exists(cand):
                apksigner = cand
                break
if apksigner:
    r = subprocess.run([apksigner, "verify", apk_path], capture_output=True, text=True)
    if r.returncode == 0:
        out("OK: apksigner verifies the signature.")
    else:
        print("    " + (r.stderr or r.stdout).strip().splitlines()[0], file=sys.stderr)
        bad("apksigner could not verify this APK's signature")

# ----------------------------------------------------------------------
# 3. Version — printed above; asserted here.
# ----------------------------------------------------------------------
if EXPECT_VERSION and EXPECT_VERSION != version_name:
    bad("versionName is %s, expected %s" % (version_name, EXPECT_VERSION))
if EXPECT_CODE and EXPECT_CODE != version_code:
    bad("versionCode is %s, expected %s" % (version_code, EXPECT_CODE))

if module and not NO_SOURCE_CHECK:
    gradle_path = os.path.join(module, "app", "build.gradle")
    try:
        gradle = open(gradle_path, encoding="utf-8", errors="replace").read()
    except OSError:
        gradle = ""
    g_code = re.search(r"^\s*versionCode\s+(\d+)", gradle, re.M)
    g_name = re.search(r"""^\s*versionName\s+["']([^"']+)["']""", gradle, re.M)
    if g_code and g_name:
        if g_code.group(1) != version_code or g_name.group(1) != version_name:
            bad("APK is %s (code %s) but %s says %s (code %s) — you are about to host a STALE "
                "APK built before the version bump (a re-used versionCode never updates a "
                "phone in place). Rebuild, or pass --no-source-check if this really is an old "
                "file (rollback)."
                % (version_name, version_code, gradle_path, g_name.group(1), g_code.group(1)))
        else:
            out("OK: version matches %s (fresh build)." % gradle_path)
    else:
        notes.append("could not read versionCode/versionName from %s" % gradle_path)

if default_name:
    out("hosting      default download = %s%s"
        % (default_name,
           "  (this build must be hosted as taxnest-caller-plus.apk instead)"
           if exception_hit else ""))

for n in notes:
    out("NOTE: " + n)
for f in fails:
    print("    FAIL: " + f, file=sys.stderr)

if fails:
    sys.exit(1)
sys.exit(3 if exception_hit else 0)
PYEOF
  rc=$?
  case $rc in
    0) ;;
    3) EXCEPTIONS=$((EXCEPTIONS + 1)) ;;
    *) FAIL=1 ;;
  esac
  echo ""
done

if [ $FAIL -ne 0 ]; then
  echo "APK RELEASE CHECK: FAILED — do NOT host the APK(s) above." >&2
  exit 1
fi
if [ $EXCEPTIONS -ne 0 ]; then
  echo "APK RELEASE CHECK: PASS WITH $EXCEPTIONS KNOWN EXCEPTION(S) — re-read the EXCEPTION block above before hosting."
  exit 0
fi
echo "APK RELEASE CHECK: PASS — no blocked permission, shared rider key, versions printed above."
exit 0
