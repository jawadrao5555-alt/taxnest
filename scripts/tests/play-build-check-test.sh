#!/bin/bash
# Regression test for scripts/play-build-check.sh (Task 1364).
#
# The real inputs (a signed AAB + two release APKs) need the Android SDK and
# Gradle, which are not in the container and take ~10 min to re-download. So
# this test BUILDS the fixtures byte by byte instead: a protobuf `.aab` manifest
# (aapt2 proto XML, what bundletool produces) and binary `.axml` APK manifests,
# each wrapped in a real zip with a dex-shaped blob. That exercises both
# manifest parsers and the dex scan for real.
#
# Cases:
#   1.  clean Play AAB + both website APKs                       -> PASS
#   2.  AAB with REQUEST_INSTALL_PACKAGES back in the manifest    -> FAIL
#   3.  AAB with REQUEST_IGNORE_BATTERY_OPTIMIZATIONS             -> FAIL
#   4.  AAB with a call-back permission (POST_NOTIFICATIONS)      -> FAIL
#   5.  AAB whose dex still contains UpdateCheck                  -> FAIL
#   6.  AAB whose dex contains a call-back class (DialWatchService) -> FAIL
#   7.  AAB on targetSdk 34                                       -> FAIL
#   8.  AAB with no targetSdk at all (cannot verify == fail)      -> FAIL
#   9.  an APK passed as the AAB                                  -> FAIL
#   10. website APK that lost REQUEST_INSTALL_PACKAGES            -> FAIL
#   11. website APK that lost the UpdateCheck class               -> FAIL
#   12. website APK dragged up to targetSdk 36                    -> FAIL
#   13. missing website APK is a loud failure, not a skip         -> FAIL
#   14. --aab-only checks the bundle and says the APKs were skipped -> PASS
#   15. missing AAB is a failure, not a skip                      -> FAIL
#   16. --apks-only (website-only release, no bundle built)       -> PASS
#   17. --apks-only still catches a website regression            -> FAIL
#   18. --aab-only + --apks-only would check nothing              -> refused
#   19. a value-taking flag with no value                         -> refused
#   20. AAB: minSdkVersion must not stand in for a missing targetSdk -> FAIL
#   21. AAB: a codename targetSdk we cannot compare               -> FAIL
#   22. website APK: same minSdkVersion stand-in                  -> FAIL
#
# Usage: bash scripts/tests/play-build-check-test.sh   (exit 0 = all pass)
set -uo pipefail
cd "$(dirname "$0")/../.."
ROOT="$PWD"
CHECK="$ROOT/scripts/play-build-check.sh"
[ -f "$CHECK" ] || { echo "FAIL: $CHECK missing"; exit 1; }

TMP=$(mktemp -d /tmp/play-build-check-test.XXXXXX)
trap 'rm -rf "$TMP"' EXIT
FAILS=0
ok()  { echo "PASS: $*"; }
bad() { echo "FAIL: $*" >&2; FAILS=$((FAILS + 1)); }

# ──────────────────────────────────────────────────────────────────────────────
# Fixture builder
# ──────────────────────────────────────────────────────────────────────────────
python3 - "$TMP" <<'PYEOF'
import os
import struct
import sys
import zipfile

OUT = sys.argv[1]
PKG = "pk.taxnest.callerid"
VERSION_NAME = "1.4.0"
VERSION_CODE = 5
P = "android.permission."


# ── protobuf (aapt2 proto XML, Resources.proto) ───────────────────────────────
def _v(n):
    b = bytearray()
    while True:
        chunk = n & 0x7F
        n >>= 7
        b.append(chunk | 0x80 if n else chunk)
        if not n:
            return bytes(b)


def _len(fno, payload):
    return _v((fno << 3) | 2) + _v(len(payload)) + payload


def _str(fno, s):
    return _len(fno, s.encode())


def _int(fno, v):
    return _v((fno << 3) | 0) + _v(v)


def pattr(name, value=None, int_value=None):
    """XmlAttribute{name=2, value=3, compiled_item=6{prim=7{int_decimal=6}}}"""
    b = _str(2, name)
    if value is not None:
        b += _str(3, value)
    if int_value is not None:
        b += _len(6, _len(7, _int(6, int_value)))
    return b


def pel(name, attrs=(), children=()):
    """XmlElement{name=3, attribute=4, child=5}"""
    b = _str(3, name)
    for a in attrs:
        b += _len(4, a)
    for c in children:
        b += _len(5, c)
    return b


def pnode(el):
    """XmlNode{element=1}"""
    return _len(1, el)


def proto_manifest(permissions, target_sdk=36, with_uses_sdk=True, min_sdk=26,
                   target_sdk_str=None, with_target_sdk=True):
    kids = []
    if with_uses_sdk:
        sdk_attrs = [pattr("minSdkVersion", int_value=min_sdk)]
        if target_sdk_str is not None:
            sdk_attrs.append(pattr("targetSdkVersion", value=target_sdk_str))
        elif with_target_sdk:
            sdk_attrs.append(pattr("targetSdkVersion", int_value=target_sdk))
        kids.append(pnode(pel("uses-sdk", sdk_attrs)))
    for perm in permissions:
        kids.append(pnode(pel("uses-permission", [pattr("name", value=perm)])))
    kids.append(pnode(pel("application", [], [
        pnode(pel("service", [
            pattr("name", value=".CallListenerService"),
            pattr("exported", value="false"),
            pattr("permission", value=P + "BIND_NOTIFICATION_LISTENER_SERVICE"),
        ])),
        pnode(pel("activity", [pattr("name", value=".NotificationDisclosureActivity")])),
    ])))
    return pnode(pel("manifest", [
        pattr("package", value=PKG),
        pattr("versionCode", int_value=VERSION_CODE),
        pattr("versionName", value=VERSION_NAME),
    ], kids))


# ── binary AXML (what an APK carries) ─────────────────────────────────────────
TYPE_STRING = 0x03
TYPE_INT_DEC = 0x10


def axml(elements):
    """elements: [(tag, [(attr, TYPE_*, str|int)])] in document order."""
    pool = []

    def idx(s):
        if s not in pool:
            pool.append(s)
        return pool.index(s)

    encoded = []
    for tag, attrs in elements:
        t = idx(tag)
        ea = []
        for name, dtype, val in attrs:
            n = idx(name)
            ea.append((n, dtype, idx(val) if dtype == TYPE_STRING else val))
        encoded.append((t, ea))

    data = bytearray()
    offsets = []
    for s in pool:
        offsets.append(len(data))
        data += struct.pack("<H", len(s)) + s.encode("utf-16-le") + b"\x00\x00"
    while len(data) % 4:
        data += b"\x00"
    offs = b"".join(struct.pack("<I", o) for o in offsets)
    strings_start = 28 + len(offs)
    pool_chunk = struct.pack("<HHIIIIII", 0x0001, 28, strings_start + len(data),
                             len(pool), 0, 0, strings_start, 0) + offs + bytes(data)

    body = b""
    for tag_i, attrs in encoded:
        ab = b""
        for name_i, dtype, d in attrs:
            raw = d if dtype == TYPE_STRING else 0xFFFFFFFF
            ab += struct.pack("<IIIHBBI", 0xFFFFFFFF, name_i, raw, 8, 0, dtype, d)
        ext = struct.pack("<IIHHHHHH", 0xFFFFFFFF, tag_i, 20, 20, len(attrs), 0, 0, 0)
        body += struct.pack("<HHIII", 0x0102, 16, 16 + len(ext) + len(ab), 0,
                            0xFFFFFFFF) + ext + ab

    payload = pool_chunk + body
    return struct.pack("<HHI", 0x0003, 8, 8 + len(payload)) + payload


def apk_manifest(permissions, target_sdk=34, min_sdk=26, with_target_sdk=True):
    sdk_attrs = [("minSdkVersion", TYPE_INT_DEC, min_sdk)]
    if with_target_sdk:
        sdk_attrs.append(("targetSdkVersion", TYPE_INT_DEC, target_sdk))
    els = [("manifest", [("package", TYPE_STRING, PKG),
                         ("versionCode", TYPE_INT_DEC, VERSION_CODE),
                         ("versionName", TYPE_STRING, VERSION_NAME)]),
           ("uses-sdk", sdk_attrs)]
    for perm in permissions:
        els.append(("uses-permission", [("name", TYPE_STRING, perm)]))
    els.append(("application", [("label", TYPE_STRING, "TaxNest Caller ID")]))
    return axml(els)


# ── dex-shaped blob: only the class descriptors matter to the checker ─────────
def dex(classes):
    blob = b"dex\n035\x00" + b"\x00" * 24
    for c in classes:
        blob += ("Lpk/taxnest/callerid/%s;" % c).encode() + b"\x00"
    return blob


PLAY_CLASSES = ["MainActivity", "LoginActivity", "RingReporter", "Updater",
                "CallListenerService", "CallSourceRules", "Detector",
                "NotificationDisclosureActivity", "Lang", "BaseActivity"]
WEB_CLASSES = PLAY_CLASSES + ["UpdateCheck", "CallerApp", "DialWatchService",
                              "DialActivity", "DialBootReceiver"]

PLAY_PERMS = [P + "INTERNET", P + "ACCESS_NETWORK_STATE"]
WEB_PERMS = [P + "INTERNET", P + "ACCESS_NETWORK_STATE",
             P + "REQUEST_INSTALL_PACKAGES", P + "REQUEST_IGNORE_BATTERY_OPTIMIZATIONS",
             P + "FOREGROUND_SERVICE", P + "FOREGROUND_SERVICE_DATA_SYNC",
             P + "POST_NOTIFICATIONS", P + "RECEIVE_BOOT_COMPLETED"]


def write_aab(name, permissions=PLAY_PERMS, classes=PLAY_CLASSES, target_sdk=36,
              with_uses_sdk=True, min_sdk=26, target_sdk_str=None, with_target_sdk=True):
    with zipfile.ZipFile(os.path.join(OUT, name), "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("BundleConfig.pb", b"\x00")
        z.writestr("base/manifest/AndroidManifest.xml",
                   proto_manifest(permissions, target_sdk, with_uses_sdk, min_sdk,
                                  target_sdk_str, with_target_sdk))
        z.writestr("base/dex/classes.dex", dex(classes))
        z.writestr("base/resources.pb", b"\x00")


def write_apk(name, permissions=WEB_PERMS, classes=WEB_CLASSES, target_sdk=34,
              min_sdk=26, with_target_sdk=True):
    with zipfile.ZipFile(os.path.join(OUT, name), "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("AndroidManifest.xml",
                   apk_manifest(permissions, target_sdk, min_sdk, with_target_sdk))
        z.writestr("classes.dex", dex(classes))
        z.writestr("resources.arsc", b"\x00")


# the clean release everything else is a mutation of
write_aab("good.aab")
write_apk("good-sim.apk")
write_apk("good-plus.apk")

# AAB regressions
write_aab("bad-install.aab", permissions=PLAY_PERMS + [P + "REQUEST_INSTALL_PACKAGES"])
write_aab("bad-battery.aab", permissions=PLAY_PERMS + [P + "REQUEST_IGNORE_BATTERY_OPTIMIZATIONS"])
write_aab("bad-callback.aab", permissions=PLAY_PERMS + [P + "POST_NOTIFICATIONS"])
write_aab("bad-updatecheck.aab", classes=PLAY_CLASSES + ["UpdateCheck"])
write_aab("bad-dialclass.aab", classes=PLAY_CLASSES + ["DialWatchService"])
write_aab("bad-sdk34.aab", target_sdk=34)
write_aab("bad-nosdk.aab", with_uses_sdk=False)
# No targetSdkVersion, but a minSdkVersion that WOULD clear the Play floor.
# Android would read this as targetSdk 36; the guard must not.
write_aab("bad-minsdkonly.aab", with_target_sdk=False, min_sdk=36)
# A targetSdkVersion we cannot turn into a number (preview/codename builds).
write_aab("bad-sdkcodename.aab", target_sdk_str="Baklava", min_sdk=36)

# website-APK regressions
write_apk("noperm-sim.apk", permissions=[p for p in WEB_PERMS if not p.endswith("REQUEST_INSTALL_PACKAGES")])
write_apk("noupdate-plus.apk", classes=[c for c in WEB_CLASSES if c != "UpdateCheck"])
write_apk("sdk36-sim.apk", target_sdk=36)
# Same bypass on the website side: minSdkVersion 34 must not stand in for a
# missing targetSdkVersion.
write_apk("minsdkonly-sim.apk", with_target_sdk=False, min_sdk=34)
print("fixtures written to %s" % OUT)
PYEOF
[ $? -eq 0 ] || { echo "FAIL: could not build fixtures" >&2; exit 1; }

# ──────────────────────────────────────────────────────────────────────────────
# Cases
# ──────────────────────────────────────────────────────────────────────────────
# run <label> <expected-exit> <expected-substring> -- <args...>
run() {
  local label="$1" want_rc="$2" want_txt="$3"; shift 4
  local outfile="$TMP/out.txt"
  bash "$CHECK" "$@" >"$outfile" 2>&1
  local rc=$?
  if [ "$rc" -ne "$want_rc" ]; then
    bad "$label — exit $rc, expected $want_rc"
    sed 's/^/      | /' "$outfile" >&2
    return
  fi
  if [ -n "$want_txt" ] && ! grep -qF -e "$want_txt" "$outfile"; then
    bad "$label — exit $rc as expected but output does not mention '$want_txt'"
    sed 's/^/      | /' "$outfile" >&2
    return
  fi
  ok "$label"
}

G_AAB="$TMP/good.aab"; G_SIM="$TMP/good-sim.apk"; G_PLUS="$TMP/good-plus.apk"

run "1.  clean release passes" 0 "PLAY BUILD CHECK: PASS" -- \
  --aab "$G_AAB" --sim "$G_SIM" --plus "$G_PLUS"

run "2.  AAB: REQUEST_INSTALL_PACKAGES is caught" 1 "REQUEST_INSTALL_PACKAGES" -- \
  --aab "$TMP/bad-install.aab" --sim "$G_SIM" --plus "$G_PLUS"

run "3.  AAB: battery permission is caught" 1 "REQUEST_IGNORE_BATTERY_OPTIMIZATIONS" -- \
  --aab "$TMP/bad-battery.aab" --sim "$G_SIM" --plus "$G_PLUS"

run "4.  AAB: call-back permission is caught" 1 "POST_NOTIFICATIONS" -- \
  --aab "$TMP/bad-callback.aab" --sim "$G_SIM" --plus "$G_PLUS"

run "5.  AAB: UpdateCheck in the dex is caught" 1 "class UpdateCheck" -- \
  --aab "$TMP/bad-updatecheck.aab" --sim "$G_SIM" --plus "$G_PLUS"

run "6.  AAB: call-back class in the dex is caught" 1 "class DialWatchService" -- \
  --aab "$TMP/bad-dialclass.aab" --sim "$G_SIM" --plus "$G_PLUS"

run "7.  AAB: targetSdk 34 is caught" 1 "targetSdk is 34, Play needs >= 36" -- \
  --aab "$TMP/bad-sdk34.aab" --sim "$G_SIM" --plus "$G_PLUS"

run "8.  AAB: unreadable targetSdk fails, never passes" 1 "could not read targetSdk" -- \
  --aab "$TMP/bad-nosdk.aab" --sim "$G_SIM" --plus "$G_PLUS"

run "9.  an APK handed in as the AAB is rejected" 1 "this is not an AAB" -- \
  --aab "$G_SIM" --sim "$G_SIM" --plus "$G_PLUS"

run "10. website APK: lost REQUEST_INSTALL_PACKAGES" 1 "REQUEST_INSTALL_PACKAGES is GONE" -- \
  --aab "$G_AAB" --sim "$TMP/noperm-sim.apk" --plus "$G_PLUS"

run "11. website APK: lost the UpdateCheck class" 1 "UpdateCheck class is MISSING" -- \
  --aab "$G_AAB" --sim "$G_SIM" --plus "$TMP/noupdate-plus.apk"

run "12. website APK: targetSdk dragged to 36" 1 "targetSdk is 36, expected 34" -- \
  --aab "$G_AAB" --sim "$TMP/sdk36-sim.apk" --plus "$G_PLUS"

run "13. missing website APK is a failure, not a skip" 1 "file not found" -- \
  --aab "$G_AAB" --sim "$TMP/does-not-exist.apk" --plus "$G_PLUS"

run "14. --aab-only passes without the APKs" 0 "the website APKs were not checked" -- \
  --aab "$G_AAB" --aab-only

# The reverse guard must not be satisfiable by an unbuilt bundle either.
run "15. missing AAB is a failure" 1 "file not found" -- \
  --aab "$TMP/does-not-exist.aab" --aab-only

run "16. --apks-only passes without the bundle" 0 "the Play bundle was not checked" -- \
  --sim "$G_SIM" --plus "$G_PLUS" --apks-only

run "17. --apks-only still catches a website regression" 1 "targetSdk is 36, expected 34" -- \
  --sim "$TMP/sdk36-sim.apk" --plus "$G_PLUS" --apks-only

run "18. --aab-only + --apks-only is refused" 2 "check nothing" -- \
  --aab "$G_AAB" --aab-only --apks-only

# `shift 2` past the end is a no-op — this used to spin forever.
run "19. a flag with no value is refused" 2 "--aab needs a value" -- --aab

# Android reads an absent targetSdkVersion as "same as minSdkVersion". A guard
# must not: that would let a manifest it failed to read clear the Play floor on
# a minSdkVersion that happens to be high enough.
run "20. AAB: minSdk does not stand in for targetSdk" 1 "could not read targetSdk" -- \
  --aab "$TMP/bad-minsdkonly.aab" --sim "$G_SIM" --plus "$G_PLUS"

run "21. AAB: a codename targetSdk is not a pass" 1 "could not read targetSdk" -- \
  --aab "$TMP/bad-sdkcodename.aab" --sim "$G_SIM" --plus "$G_PLUS"

run "22. website APK: minSdk does not stand in for targetSdk" 1 "could not read targetSdk" -- \
  --aab "$G_AAB" --sim "$TMP/minsdkonly-sim.apk" --plus "$G_PLUS"

echo ""
if [ "$FAILS" -ne 0 ]; then
  echo "play-build-check test: $FAILS CASE(S) FAILED" >&2
  exit 1
fi
echo "play-build-check test: all cases pass"
exit 0
