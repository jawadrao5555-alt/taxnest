#!/bin/bash
# TaxNest Caller ID — Play release guard. Run on the built AAB BEFORE it is
# uploaded to the Play Console, and on the two website APKs in the same run.
#
# Why this is a script and not a checklist:
# The Play build (`play` flavor) is accepted by Google only because it carries
# NO self-update code and NO `REQUEST_INSTALL_PACKAGES`. That difference is not
# written anywhere the compiler can see it — it comes purely from which source
# sets and which manifest the flavor happens to pull in
# (`caller-app/app/build.gradle`). So any ordinary-looking future change can
# break it silently:
#   * a new class dropped into `src/main/java` instead of `src/web/java`
#     -> it compiles into the Play build too;
#   * a permission added to `src/main/AndroidManifest.xml`
#     -> it merges into the Play manifest unless `src/play` removes it;
#   * a new `src/web` feature whose manifest half is copied into all three
#     flavor manifests "for consistency".
# Nothing fails. The build is green, the AAB uploads, and the news arrives weeks
# later as a Play review rejection.
#
# The reverse regression is just as expensive: "clean up the Play build" edits
# that strip self-update or bump `targetSdk` for the WEBSITE flavors too. Then
# every phone that installed from taxnest.pk silently stops self-updating
# (there is no store to fall back on) and their runtime behaviour changes under
# them. So this script asserts BOTH directions.
#
# ── What it checks ────────────────────────────────────────────────────────────
#
# A. Play bundle (.aab) — every one of these is a FAILURE:
#    1. any forbidden permission in the merged manifest of any module:
#         REQUEST_INSTALL_PACKAGES              self-update; Play's Device and
#                                               Network Abuse policy forbids it
#         REQUEST_IGNORE_BATTERY_OPTIMIZATIONS  the direct "allow?" dialog is
#                                               allowed only for a short list of
#                                               use cases the app is not on
#         FOREGROUND_SERVICE                    call back (Task 1381) —
#         FOREGROUND_SERVICE_DATA_SYNC          website builds only; leaking it
#         POST_NOTIFICATIONS                    into Play adds review surface
#         RECEIVE_BOOT_COMPLETED                the listing never declared
#         READ_PHONE_STATE                      would make Play's Call Log
#         READ_CALL_LOG                         declaration form mandatory
#    2. any website-only class in any dex of the bundle: UpdateCheck (the
#       self-update itself), Updater's real download/install path lives with it,
#       plus CallerApp / DialWatchService / DialActivity / DialBootReceiver
#       (call back) and PhoneStateReceiver (the clean build's detector).
#    3. targetSdk below 36 (Play's floor for new apps since 31 Aug 2026).
#    4. wrong package, unreadable manifest, or a targetSdk it cannot read —
#       "could not verify" is a failure, never a pass.
#
# B. Website APKs (sim + plus) — the reverse regression. Each must still have:
#    1. REQUEST_INSTALL_PACKAGES in its manifest,
#    2. the UpdateCheck class in its dex,
#    3. targetSdk exactly 34.
#
# Blocked-permission / signing / version checks for those two APKs stay in
# `scripts/apk-release-check.sh` — run that too, it is a different guard.
#
# ── Usage ─────────────────────────────────────────────────────────────────────
#   bash scripts/play-build-check.sh                      # default build paths
#   bash scripts/play-build-check.sh --aab path/to.aab --sim a.apk --plus b.apk
#   bash scripts/play-build-check.sh --aab-only           # website APKs not built
#   bash scripts/play-build-check.sh --apks-only          # website-only release
#   bash scripts/play-build-check.sh --min-target-sdk 37  # when Play raises it
#
# Needs only python3 — the AAB's protobuf manifest, the APKs' binary manifests
# and the dex files are parsed directly, so it also runs before the Android SDK
# has been re-downloaded after a container reset.
#
# Exit codes: 0 = all clear, 1 = FAILURE (do not upload), 2 = could not run.
set -uo pipefail
ORIG_PWD="$PWD"           # file paths are resolved against the caller's cwd…
cd "$(dirname "$0")/.."   # …everything else against the repo root.

AAB="caller-app/app/build/outputs/bundle/playRelease/app-play-release.aab"
SIM="caller-app/app/build/outputs/apk/sim/release/app-sim-release.apk"
PLUS="caller-app/app/build/outputs/apk/plus/release/app-plus-release.apk"
AAB_ONLY=0
APKS_ONLY=0
MIN_TARGET_SDK=36
WEB_TARGET_SDK=34

# `shift 2` with only one argument left is a no-op, which would spin the loop
# forever — so a value-taking flag checks for its value first.
need_val() { [ "$2" -ge 2 ] || { echo "ERROR: $1 needs a value." >&2; exit 2; }; }

while [ $# -gt 0 ]; do
  case "$1" in
    --aab)             need_val --aab $#;            AAB="$2"; shift 2 ;;
    --sim)             need_val --sim $#;            SIM="$2"; shift 2 ;;
    --plus)            need_val --plus $#;           PLUS="$2"; shift 2 ;;
    --aab-only)        AAB_ONLY=1; shift ;;
    --apks-only)       APKS_ONLY=1; shift ;;
    --min-target-sdk)  need_val --min-target-sdk $#; MIN_TARGET_SDK="$2"; shift 2 ;;
    --web-target-sdk)  need_val --web-target-sdk $#; WEB_TARGET_SDK="$2"; shift 2 ;;
    -h|--help)
      awk 'NR>1 && /^#/ { sub(/^# ?/, ""); print; next } NR>1 { exit }' "$0"
      exit 0 ;;
    *) echo "ERROR: unknown argument $1 (try --help)" >&2; exit 2 ;;
  esac
done

command -v python3 >/dev/null 2>&1 || { echo "ERROR: python3 not found." >&2; exit 2; }

# Resolve a path the caller gave relative to their own cwd.
resolve() {
  case "$1" in
    /*) echo "$1" ;;
    *)  if [ -f "$1" ] || [ ! -f "$ORIG_PWD/$1" ]; then echo "$1"; else echo "$ORIG_PWD/$1"; fi ;;
  esac
}
AAB="$(resolve "$AAB")"
SIM="$(resolve "$SIM")"
PLUS="$(resolve "$PLUS")"

if [ "$AAB_ONLY" -eq 1 ] && [ "$APKS_ONLY" -eq 1 ]; then
  echo "ERROR: --aab-only and --apks-only together check nothing." >&2
  exit 2
fi

AAB="$AAB" SIM="$SIM" PLUS="$PLUS" AAB_ONLY="$AAB_ONLY" APKS_ONLY="$APKS_ONLY" \
MIN_TARGET_SDK="$MIN_TARGET_SDK" WEB_TARGET_SDK="$WEB_TARGET_SDK" \
python3 - <<'PYEOF'
import os
import struct
import sys
import zipfile

AAB = os.environ["AAB"]
SIM = os.environ["SIM"]
PLUS = os.environ["PLUS"]
AAB_ONLY = os.environ.get("AAB_ONLY") == "1"
APKS_ONLY = os.environ.get("APKS_ONLY") == "1"
PACKAGE = "pk.taxnest.callerid"

try:
    MIN_TARGET_SDK = int(os.environ["MIN_TARGET_SDK"])
    WEB_TARGET_SDK = int(os.environ["WEB_TARGET_SDK"])
except ValueError:
    print("ERROR: --min-target-sdk / --web-target-sdk need a number.", file=sys.stderr)
    sys.exit(2)

P = "android.permission."

# Permissions that must NEVER reach the Play build, with the reason a reviewer
# would give. Widening this list is cheap; removing an entry means the Play
# listing / Data safety answers changed too.
FORBIDDEN_PLAY_PERMISSIONS = [
    (P + "REQUEST_INSTALL_PACKAGES",
     "self-update — Play's Device and Network Abuse policy does not list "
     "\"the app updates itself\" as a permitted use"),
    (P + "REQUEST_IGNORE_BATTERY_OPTIMIZATIONS",
     "the direct battery \"allow?\" dialog; the Play build opens the "
     "battery-optimisation LIST instead"),
    (P + "FOREGROUND_SERVICE",
     "call back (Task 1381) — website builds only"),
    (P + "FOREGROUND_SERVICE_DATA_SYNC",
     "call back (Task 1381) — website builds only"),
    (P + "POST_NOTIFICATIONS",
     "call back (Task 1381) — website builds only"),
    (P + "RECEIVE_BOOT_COMPLETED",
     "call back (Task 1381) — website builds only"),
    (P + "READ_PHONE_STATE",
     "the clean (sim) build's detector; in the Play build it makes Play's "
     "Call Log permissions declaration form mandatory"),
    (P + "READ_CALL_LOG",
     "the clean (sim) build's detector; in the Play build it makes Play's "
     "Call Log permissions declaration form mandatory"),
]

# Classes that live in src/web/java (or src/sim/java) and must not compile into
# the Play build. Matched as substrings of the dex, so nested/lambda classes
# (UpdateCheck$1, …) are caught too.
FORBIDDEN_PLAY_CLASSES = [
    ("UpdateCheck",       "self-update (src/web/java) — the whole reason Play accepts this build"),
    ("CallerApp",         "call back (src/web/java)"),
    ("DialWatchService",  "call back (src/web/java)"),
    ("DialActivity",      "call back (src/web/java)"),
    ("DialBootReceiver",  "call back (src/web/java)"),
    ("PhoneStateReceiver", "the clean (sim) build's telephony detector (src/sim/java)"),
]

fails = []


def out(line):
    print("    " + line)


def bad(where, line):
    fails.append("%s: %s" % (where, line))
    print("    FAIL: " + line, file=sys.stderr)


# ══════════════════════════════════════════════════════════════════════════════
# AAB manifest — aapt2 protobuf XML (Resources.proto), NOT the binary AXML that
# APKs use. Generic wire-format walk; no protobuf runtime needed.
# ══════════════════════════════════════════════════════════════════════════════
def _varint(buf, p):
    val = shift = 0
    while p < len(buf):
        b = buf[p]
        p += 1
        val |= (b & 0x7F) << shift
        if not b & 0x80:
            return val, p
        shift += 7
        if shift > 63:
            break
    raise ValueError("truncated varint")


def proto_fields(buf):
    """field number -> [raw value]; bytes for length-delimited, int for varint."""
    fields = {}
    p = 0
    while p < len(buf):
        key, p = _varint(buf, p)
        fno, wire = key >> 3, key & 7
        if wire == 0:
            val, p = _varint(buf, p)
        elif wire == 1:
            val, p = buf[p:p + 8], p + 8
        elif wire == 2:
            n, p = _varint(buf, p)
            if p + n > len(buf):
                raise ValueError("truncated length-delimited field")
            val, p = buf[p:p + n], p + n
        elif wire == 5:
            val, p = buf[p:p + 4], p + 4
        else:
            raise ValueError("unsupported wire type %d" % wire)
        fields.setdefault(fno, []).append(val)
    return fields


def _pstr(fields, fno):
    vals = fields.get(fno)
    return vals[0].decode("utf-8", "replace") if vals and isinstance(vals[0], bytes) else ""


def _compiled_item(raw):
    """Item -> printable value. targetSdkVersion arrives here as prim.int_decimal."""
    item = proto_fields(raw)
    if 2 in item:                                    # Item.str
        return _pstr(proto_fields(item[2][0]), 1)
    if 3 in item:                                    # Item.raw_str
        return _pstr(proto_fields(item[3][0]), 1)
    if 7 in item:                                    # Item.prim
        prim = proto_fields(item[7][0])
        if 8 in prim:                                # boolean_value
            return "true" if prim[8][0] else "false"
        for fno in (6, 7, 9, 10, 11, 12, 13, 14):    # int_decimal / hex / colors / dimen
            if fno in prim:
                v = prim[fno][0]
                if isinstance(v, int):
                    return str(v - (1 << 32) if fno == 6 and v >= (1 << 31) else v)
    return ""


def parse_proto_manifest(data):
    """[(tag, {attr: value})] in document order, root first."""
    elements = []

    def walk(node_bytes):
        node = proto_fields(node_bytes)
        for el_bytes in node.get(1, []):             # XmlNode.element
            el = proto_fields(el_bytes)
            attrs = {}
            for attr_bytes in el.get(4, []):         # XmlElement.attribute
                attr = proto_fields(attr_bytes)
                name = _pstr(attr, 2)
                value = _pstr(attr, 3)
                if not value and 6 in attr:          # XmlAttribute.compiled_item
                    value = _compiled_item(attr[6][0])
                if name:
                    attrs[name] = value
            elements.append((_pstr(el, 3), attrs))
            for child in el.get(5, []):              # XmlElement.child
                walk(child)

    walk(data)
    return elements


# ══════════════════════════════════════════════════════════════════════════════
# APK manifest — binary AXML (same data `aapt2 dump xmltree` prints).
# ══════════════════════════════════════════════════════════════════════════════
def parse_axml(data):
    elements = []
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
        if ctype == 0x0001:      # RES_STRING_POOL_TYPE
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
        elif ctype == 0x0180:    # RES_XML_RESOURCE_MAP_TYPE
            n = (chunk_size - chunk_header) // 4
            resmap = list(struct.unpack_from("<%dI" % n, data, pos + chunk_header))
        elif ctype == 0x0102:    # RES_XML_START_ELEMENT_TYPE
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
                if dtype == 0x03:
                    val = strings[adata] if adata < len(strings) else ""
                elif dtype == 0x12:
                    val = "true" if adata else "false"
                elif dtype == 0x11:
                    val = "0x%x" % adata
                else:
                    val = str(adata)
                attrs[key] = val
                ap += 20
            elements.append((tag, attrs))
        pos += chunk_size
    return elements, strings


# ══════════════════════════════════════════════════════════════════════════════
# Shared helpers
# ══════════════════════════════════════════════════════════════════════════════
def manifest_summary(elements):
    """(package, versionName, versionCode, targetSdk) — targetSdk is None when it
    cannot be read, which every caller must treat as a failure.

    Deliberately does NOT fall back to minSdkVersion. Android itself does
    (an absent targetSdkVersion means "same as minSdkVersion"), but this is a
    release guard, not the platform: build.gradle sets targetSdk explicitly for
    all three flavors, so an artifact without it is already something we do not
    recognise. Inferring a number there would let a manifest we failed to parse
    sail through the Play floor on a minSdkVersion that happens to be high
    enough — the exact "passed because we could not check" outcome this script
    exists to prevent.
    """
    root = elements[0][1] if elements else {}
    target = None
    for tag, attrs in elements:
        if tag == "uses-sdk":
            try:
                target = int(attrs.get("targetSdkVersion"))
            except (TypeError, ValueError):
                target = None
            break
    return (root.get("package", "?"), root.get("versionName", "?"),
            root.get("versionCode", "?"), target)


def declared_permissions(elements):
    """Every permission string anywhere in the manifest — <uses-permission> and
    android:permission="…" on a service/receiver, which is how the notification
    listener is declared."""
    seen = {}
    for tag, attrs in elements:
        for key, val in attrs.items():
            if isinstance(val, str) and val.startswith(P):
                where = "<%s>" % tag if tag.startswith("uses-permission") else "<%s %s=…>" % (tag, key)
                seen.setdefault(val, set()).add(where)
    return seen


def dex_classes(zip_file, names):
    """name -> [dex entries it appears in], over every .dex in the archive."""
    hits = {}
    for entry in zip_file.namelist():
        if not entry.endswith(".dex"):
            continue
        blob = zip_file.read(entry)
        for name in names:
            if name.encode() in blob:
                hits.setdefault(name, []).append(entry)
    return hits


def open_archive(path, label):
    if not os.path.isfile(path):
        bad(label, "file not found: %s — build it first (see caller-app/RELEASE.md "
                   "and docs/play/signing-and-build.md), or pass the right path." % path)
        return None
    try:
        return zipfile.ZipFile(path)
    except (OSError, zipfile.BadZipFile) as exc:
        bad(label, "not a readable archive: %s (%s)" % (path, exc))
        return None


# ══════════════════════════════════════════════════════════════════════════════
# A. The Play bundle
# ══════════════════════════════════════════════════════════════════════════════
def check_aab(path):
    label = "AAB"
    print("==> Play bundle: %s" % path)
    z = open_archive(path, label)
    if z is None:
        print("")
        return
    with z:
        manifests = [n for n in z.namelist() if n.endswith("/manifest/AndroidManifest.xml")]
        if not manifests:
            bad(label, "no */manifest/AndroidManifest.xml inside — this is not an AAB. An APK "
                       "has its manifest at the root; `bundlePlayRelease` produces the bundle.")
            print("")
            return

        per_module = {}
        for entry in sorted(manifests):
            raw = z.read(entry)
            try:
                elements = parse_proto_manifest(raw)
            except (ValueError, struct.error, IndexError) as exc:
                elements = []
                bad(label, "could not parse %s (%s) — refusing to pass a bundle whose "
                           "manifest cannot be read." % (entry, exc))
            per_module[entry] = (elements, raw)

        base = "base/manifest/AndroidManifest.xml"
        base_elements = per_module.get(base, ([], b""))[0]
        if not base_elements:
            bad(label, "no readable base/manifest/AndroidManifest.xml in the bundle.")
        else:
            package, version_name, version_code, target_sdk = manifest_summary(base_elements)
            out("package      %s" % package)
            out("version      %s  (versionCode %s)" % (version_name, version_code))
            out("targetSdk    %s" % ("?" if target_sdk is None else target_sdk))
            if package != PACKAGE:
                bad(label, "package is %s, expected %s — wrong bundle." % (package, PACKAGE))

            # 3. targetSdk floor. Unreadable == failure.
            if target_sdk is None:
                bad(label, "could not read targetSdk from the manifest. Play needs >= %d for "
                           "this build; verify it by hand before uploading." % MIN_TARGET_SDK)
            else:
                if target_sdk < MIN_TARGET_SDK:
                    bad(label, "targetSdk is %d, Play needs >= %d for new app versions. The "
                               "`play` flavor sets `targetSdk = 36` in caller-app/app/build.gradle "
                               "— someone removed or lowered it. Play rejects the upload."
                               % (target_sdk, MIN_TARGET_SDK))
                else:
                    out("OK: targetSdk %d (Play floor %d)." % (target_sdk, MIN_TARGET_SDK))

        # 1. Forbidden permissions — in ANY module's manifest.
        found = {}
        for entry, (elements, raw) in sorted(per_module.items()):
            module = entry.split("/", 1)[0]
            declared = declared_permissions(elements)
            for name, _reason in FORBIDDEN_PLAY_PERMISSIONS:
                if name in declared:
                    found.setdefault(name, set()).update(
                        "%s %s" % (module, w) for w in declared[name])
                # Backstop: proto keeps strings verbatim, so a declaration the
                # walk somehow missed is still in the bytes.
                elif name.encode() in raw:
                    found.setdefault(name, set()).add("%s (manifest bytes)" % module)
        if found:
            for name, reason in FORBIDDEN_PLAY_PERMISSIONS:
                if name in found:
                    out("FORBIDDEN: %s  in %s" % (name, ", ".join(sorted(found[name]))))
                    out("           %s" % reason)
            bad(label, "the Play bundle declares %d forbidden permission(s) (above). This build "
                       "is accepted by Google only because it declares none of them. Check "
                       "src/main/AndroidManifest.xml (everything there merges into all three "
                       "flavors) and remove it in src/play/AndroidManifest.xml with "
                       "tools:node=\"remove\" if it belongs to the website builds only."
                       % len(found))
        else:
            out("OK: none of the %d forbidden Play permissions are in the merged manifest."
                % len(FORBIDDEN_PLAY_PERMISSIONS))

        # 2. Website-only classes must not be compiled in.
        names = [n for n, _ in FORBIDDEN_PLAY_CLASSES]
        dex_entries = [n for n in z.namelist() if n.endswith(".dex")]
        if not dex_entries:
            bad(label, "the bundle has no .dex at all — that is not a real build.")
        else:
            hits = dex_classes(z, names)
            if hits:
                for name, reason in FORBIDDEN_PLAY_CLASSES:
                    if name in hits:
                        out("FORBIDDEN: class %s  in %s" % (name, ", ".join(sorted(hits[name]))))
                        out("           %s" % reason)
                bad(label, "website-only code is compiled into the Play bundle. The `play` "
                           "flavor's source set (caller-app/app/build.gradle) must not include "
                           "src/web/java — and a class put in src/main/java lands in every "
                           "flavor, which is the usual cause.")
            else:
                out("OK: none of the %d website-only classes are in the bundle's %d dex file(s)."
                    % (len(names), len(dex_entries)))
    print("")


# ══════════════════════════════════════════════════════════════════════════════
# B. The website APKs — the reverse regression
# ══════════════════════════════════════════════════════════════════════════════
def check_website_apk(path, flavor):
    label = "%s APK" % flavor
    print("==> Website APK (%s): %s" % (flavor, path))
    z = open_archive(path, label)
    if z is None:
        print("")
        return
    with z:
        try:
            raw = z.read("AndroidManifest.xml")
        except KeyError:
            bad(label, "no AndroidManifest.xml at the root — this is not an APK "
                       "(an AAB keeps it under base/manifest/).")
            print("")
            return
        elements, pool = parse_axml(raw)
        if not elements:
            bad(label, "could not parse AndroidManifest.xml.")
            print("")
            return

        package, version_name, version_code, target_sdk = manifest_summary(elements)
        out("package      %s" % package)
        out("version      %s  (versionCode %s)" % (version_name, version_code))
        out("targetSdk    %s" % ("?" if target_sdk is None else target_sdk))
        if package != PACKAGE:
            bad(label, "package is %s, expected %s — wrong APK." % (package, PACKAGE))

        # 1. Self-update permission must still be there.
        want = P + "REQUEST_INSTALL_PACKAGES"
        if want in declared_permissions(elements) or want in pool:
            out("OK: REQUEST_INSTALL_PACKAGES still declared (self-update).")
        else:
            bad(label, "REQUEST_INSTALL_PACKAGES is GONE. Website phones install from "
                       "taxnest.pk and have no store to fall back on — without it the "
                       "in-app update can download the APK but never install it, and every "
                       "shop is stuck on this version for good. Only the `play` flavor may "
                       "remove it.")

        # 2. Self-update code must still be compiled in.
        hits = dex_classes(z, ["UpdateCheck"])
        if hits:
            out("OK: UpdateCheck compiled in (%s)." % ", ".join(sorted(hits["UpdateCheck"])))
        else:
            bad(label, "the UpdateCheck class is MISSING from the dex. src/web/java must stay "
                       "in the `sim` and `plus` source sets (caller-app/app/build.gradle); "
                       "only `play` drops it.")

        # 3. targetSdk must not drift with the Play build.
        if target_sdk is None:
            bad(label, "could not read targetSdk; it must be %d." % WEB_TARGET_SDK)
        else:
            if target_sdk != WEB_TARGET_SDK:
                bad(label, "targetSdk is %d, expected %d. Website builds deliberately stay on "
                           "%d — Android's behaviour changes are driven by targetSdk, so raising "
                           "it here changes how the app behaves on every shop's phone without "
                           "anyone testing it. Only the `play` flavor moves."
                           % (target_sdk, WEB_TARGET_SDK, WEB_TARGET_SDK))
            else:
                out("OK: targetSdk %d (unchanged)." % target_sdk)
    print("")


if APKS_ONLY:
    print("(--apks-only: the Play bundle was not checked. Run this again without it "
          "after `bundlePlayRelease`, before uploading to the Play Console.)")
    print("")
else:
    check_aab(AAB)

if AAB_ONLY:
    print("(--aab-only: the website APKs were not checked. Run this again without it "
          "before hosting them.)")
    print("")
else:
    check_website_apk(SIM, "sim")
    check_website_apk(PLUS, "plus")

if fails:
    print("PLAY BUILD CHECK: FAILED (%d) — do NOT upload / host." % len(fails), file=sys.stderr)
    for f in fails:
        print("  - " + f, file=sys.stderr)
    sys.exit(1)
if APKS_ONLY:
    print("PLAY BUILD CHECK: PASS — website APKs still have their self-update and targetSdk %d."
          % WEB_TARGET_SDK)
else:
    print("PLAY BUILD CHECK: PASS — Play bundle is free of self-update code and forbidden "
          "permissions%s." % ("" if AAB_ONLY else ", website APKs still have theirs"))
sys.exit(0)
PYEOF
