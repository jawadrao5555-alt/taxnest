#!/bin/bash
# TaxNest Caller ID — three-language string guard (Task 1388).
#
# Run it on the SOURCE tree (no Android SDK, no build, ~0.2 s):
#
#   bash scripts/caller-lang-check.sh
#
# It also runs by itself on every `gradle -p caller-app ...` build — the
# `checkStringLanguages` task in caller-app/app/build.gradle hangs off preBuild.
#
# ── Why this is a script and not lint ────────────────────────────────────────
# The app ships three languages (Task 1382): values/ = English, values-ur/ =
# Urdu, values-b+ur+Latn/ = Roman Urdu. Lint's `MissingTranslation` guards only
# ONE shape of that: a key that no locale file of the module declares.
#
# It stays completely silent on the shape that actually bites, because the
# result is legal resource resolution:
#
#   src/main/res/values/strings.xml      howto_body = "...SIM only..."   (en)
#   src/main/res/values-ur/strings.xml   howto_body = "...SIM only..."   (ur)
#   src/notif/res/values/strings.xml     howto_body = "...WhatsApp..."   (en)   <-- override
#   src/notif/res/values-ur/            (no override)
#
# The merged table then has an English value from src/notif and an Urdu value
# from src/main. In English the WhatsApp build reads right; the moment the shop
# switches to Urdu it silently shows the CLEAN build's wording — the exact bug
# the language work was meant to remove. Lint sees a key that exists in every
# locale and says nothing. Same blind spot for the reverse (an override put
# only in values-ur/), and for a line left in English inside the Urdu file.
#
# Until now this was caught only by hand, by dumping each built APK
# (`aapt2 dump resources` | grep a key, looking for a bare "()", a "(ur)" and a
# "(b+ur+Latn)" line). That needs the SDK, a finished build, and someone
# remembering to look.
#
# ── What it checks ───────────────────────────────────────────────────────────
#
# A. Per res folder (every caller-app source set that has values/strings.xml) —
#    each is a FAILURE:
#    1. a translatable key in values/ that is missing from values-ur/ or
#       values-b+ur+Latn/ of the SAME folder (the silent-fallback case above);
#    2. a key in values-ur/ or values-b+ur+Latn/ that is not in that folder's
#       values/ (an override that leaves English inherited from src/main);
#    3. a `translatable="false"` key that is nevertheless translated
#       (language-neutral keys — app_name, welcome_fmt, lang_* — live in
#       values/ only);
#    4. a values-ur/ line with no Urdu-script character (an obviously
#       untranslated copy-paste), or a values-b+ur+Latn/ line that HAS Urdu
#       script (Roman Urdu is written in Latin letters);
#    5. a duplicate key inside one file, a missing locale file, or a resource
#       type this script does not understand (<plurals>, <string-array>) —
#       "cannot verify" is a failure, never a pass.
#
# B. Per flavor (sim / plus / play, read out of app/build.gradle so the map
#    cannot go stale) — each is a FAILURE:
#    6. a key whose English text and Urdu/Roman text end up coming from
#       DIFFERENT source sets, i.e. the flavor overrides it in only some of its
#       three locale folders. This is A.1/A.2 stated as the shop sees it, and
#       it also catches an override split across two res dirs of one flavor
#       (src/plus/res + src/notif/res + src/web/res are all "plus");
#    7. the same key declared by two res dirs of one flavor in the same locale
#       — that is the "Duplicate resources" build error, reported here in one
#       line instead of an AAPT dump.
#
# A line that is deliberately the same in Urdu (a brand name such as
# "TaxNest Caller ID") is waived by putting a comment on the line above it:
#
#     <!-- lang-check: allow-latin (brand name) -->
#     <string name="dial_service_title">TaxNest Caller ID</string>
#
# Exit 0 = PASS, 1 = a real problem, 2 = bad usage.
#
# Sister guards, all three run before hosting an APK — see caller-app/RELEASE.md:
#   scripts/apk-release-check.sh   blocked permissions / signing key / version
#   scripts/play-build-check.sh    website-vs-Play drift
#   scripts/caller-lang-check.sh   this one (source tree, needs no build)

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
APP_DIR="$REPO_ROOT/caller-app/app"

while [ $# -gt 0 ]; do
  case "$1" in
    -h|--help)
      awk 'NR>1 && /^#/ { sub(/^# ?/, ""); print; next } NR>1 { exit }' "$0"
      exit 0 ;;
    *)
      echo "ERROR: unknown option $1 (try --help)" >&2; exit 2 ;;
  esac
done

command -v python3 >/dev/null 2>&1 || { echo "ERROR: python3 not found." >&2; exit 2; }
[ -d "$APP_DIR/src" ] || { echo "ERROR: $APP_DIR/src not found." >&2; exit 2; }

python3 - "$APP_DIR" <<'PYEOF'
import os
import re
import sys
import xml.etree.ElementTree as ET

APP = sys.argv[1]
SRC = os.path.join(APP, "src")
GRADLE = os.path.join(APP, "build.gradle")

# folder -> label. Order is the order they are reported in.
LOCALES = [
    ("values", "English"),
    ("values-b+ur+Latn", "Roman Urdu"),
    ("values-ur", "Urdu"),
]
DEFAULT = "values"
URDU = "values-ur"
ROMAN = "values-b+ur+Latn"

# Urdu / Arabic script blocks (Arabic, Arabic Supplement, Presentation Forms).
URDU_RE = re.compile("[\u0600-\u06ff\u0750-\u077f\ufb50-\ufdff\ufe70-\ufeff]")
WAIVER = "lang-check: allow-latin"
UNSUPPORTED = ("plurals", "string-array", "integer-array", "array")

fail = []       # list of strings, each already formatted
notes = []      # non-fatal lines printed under the summary


def add_fail(msg):
    fail.append(msg)


def rel(path):
    return os.path.relpath(path, os.path.dirname(os.path.dirname(APP)))


def line_numbers(raw):
    """key -> 1-based line of its declaration (best effort, for the message)."""
    out = {}
    for i, line in enumerate(raw.splitlines(), start=1):
        m = re.search(r'name="([^"]+)"', line)
        if m and m.group(1) not in out:
            out[m.group(1)] = i
    return out


def read_strings(path):
    """{key: {"text":..., "translatable":bool, "waived":bool, "line":int}} or None."""
    try:
        raw = open(path, encoding="utf-8").read()
    except OSError as exc:
        add_fail("[%s] cannot be read: %s" % (rel(path), exc))
        return None
    try:
        parser = ET.XMLParser(target=ET.TreeBuilder(insert_comments=True))
        root = ET.fromstring(raw, parser=parser)
    except ET.ParseError as exc:
        add_fail("[%s] is not valid XML: %s" % (rel(path), exc))
        return None

    lines = line_numbers(raw)
    entries = {}
    last_comment = ""
    for el in root:
        if not isinstance(el.tag, str):          # comment / processing instruction
            last_comment = (el.text or "")
            continue
        if el.tag in UNSUPPORTED:
            add_fail("[%s] <%s name=\"%s\"> is a resource type this guard does not "
                     "read — extend scripts/caller-lang-check.sh before shipping it"
                     % (rel(path), el.tag, el.get("name")))
            last_comment = ""
            continue
        if el.tag != "string":
            last_comment = ""
            continue
        key = el.get("name") or ""
        if key in entries:
            add_fail("[%s] key '%s' is declared twice in the same file" % (rel(path), key))
        entries[key] = {
            "text": "".join(el.itertext()),
            "translatable": (el.get("translatable") or "true").lower() != "false",
            "waived": WAIVER in last_comment,
            "line": lines.get(key, 0),
        }
        last_comment = ""
    return entries


def at(path, key, entry):
    return "%s:%d" % (rel(path), entry["line"]) if entry["line"] else rel(path)


# ── source sets on disk ──────────────────────────────────────────────────────
res_dirs = []
for name in sorted(os.listdir(SRC)):
    d = os.path.join(SRC, name, "res")
    if os.path.isfile(os.path.join(d, DEFAULT, "strings.xml")):
        res_dirs.append("src/%s/res" % name)

if not res_dirs:
    print("FAIL: no caller-app res folder with values/strings.xml found under %s" % rel(SRC))
    sys.exit(1)

# strings[res_dir][locale] = entries dict (or None when the file is missing)
strings = {}
for d in res_dirs:
    strings[d] = {}
    for loc, _label in LOCALES:
        path = os.path.join(APP, d, loc, "strings.xml")
        if not os.path.isfile(path):
            strings[d][loc] = None
            continue
        strings[d][loc] = read_strings(path)

# ── flavors, read out of build.gradle (so this map cannot go stale) ──────────
def strip_comments(text):
    text = re.sub(r"/\*.*?\*/", "", text, flags=re.S)
    return re.sub(r"//[^\n]*", "", text)


def block_body(text, header_re):
    """Body of the first `<header> { ... }` block, brace-matched."""
    m = re.search(header_re, text)
    if not m:
        return None
    start = text.find("{", m.start())
    if start < 0:
        return None
    depth = 0
    for i in range(start, len(text)):
        if text[i] == "{":
            depth += 1
        elif text[i] == "}":
            depth -= 1
            if depth == 0:
                return text[start + 1:i]
    return None


def parse_flavors():
    try:
        g = strip_comments(open(GRADLE, encoding="utf-8").read())
    except OSError as exc:
        add_fail("cannot read %s: %s" % (rel(GRADLE), exc))
        return {}
    declared = block_body(g, r"\bproductFlavors\s*\{")
    names = re.findall(r'create\(\s*"([^"]+)"\s*\)', declared or "")
    if not names:
        add_fail("no productFlavors found in %s — this guard cannot verify the "
                 "flavors, which is a failure, not a pass" % rel(GRADLE))
        return {}
    source_sets = block_body(g, r"\bsourceSets\s*\{") or ""
    out = {}
    for name in names:
        dirs = []
        own = "src/%s/res" % name
        if own in res_dirs:
            dirs.append(own)                      # AGP adds it implicitly
        body = block_body(source_sets, r'getByName\(\s*"%s"\s*\)\s*\{' % re.escape(name))
        if body:
            for extra in re.finditer(r"res\.srcDirs\s*\+=\s*(\[[^\]]*\]|'[^']*'|\"[^\"]*\")",
                                     body):
                for p in re.findall(r"['\"]([^'\"]+)['\"]", extra.group(1)):
                    if p not in dirs:
                        dirs.append(p)
        out[name] = dirs
    return out


flavors = parse_flavors()
BASE = "src/main/res"
wired = {BASE}
for dirs in flavors.values():
    wired.update(dirs)
for d in res_dirs:
    if d not in wired:
        notes.append("note: %s is not wired into any flavor in %s — its strings reach "
                     "no build (checked anyway)" % (d, rel(GRADLE)))
for name, dirs in flavors.items():
    for d in dirs:
        if d not in res_dirs and os.path.isdir(os.path.join(APP, d)):
            notes.append("note: flavor %s lists %s, which has no values/strings.xml" % (name, d))

# ── A. every res folder: the three files must carry the same keys ────────────
print("Source sets (each folder's three locale files must agree)")
for d in res_dirs:
    per = strings[d]
    default = per[DEFAULT] or {}
    missing_file = [loc for loc, _l in LOCALES if per[loc] is None]
    for loc in missing_file:
        add_fail("[%s] %s/strings.xml is missing — every source set that declares a "
                 "string must declare it in all three languages" % (d, loc))

    translatable = {k: v for k, v in default.items() if v["translatable"]}
    neutral = {k for k, v in default.items() if not v["translatable"]}

    for loc, label in LOCALES[1:]:
        entries = per[loc]
        if entries is None:
            continue
        for key in sorted(translatable):
            if key not in entries:
                add_fail("[%s] key '%s' is in %s/ but MISSING from %s/ (%s) — %s mode "
                         "would silently keep another source set's wording"
                         % (d, key, DEFAULT, loc, label, label))
        for key in sorted(entries):
            if key in neutral:
                add_fail("[%s] key '%s' is translatable=\"false\" in %s/ but is also "
                         "translated in %s/ — keep language-neutral keys in %s/ only"
                         % (d, key, DEFAULT, loc, DEFAULT))
            elif key not in default:
                add_fail("[%s] key '%s' is in %s/ but NOT in %s/ of the same folder — "
                         "English would stay on another source set's wording"
                         % (d, key, loc, DEFAULT))

    # untranslated / wrong-script lines
    for key, entry in sorted((per[URDU] or {}).items()):
        text = entry["text"].strip()
        if entry["waived"] or not text:
            continue
        if not URDU_RE.search(text):
            add_fail("[%s] '%s' has no Urdu-script character: \"%s\"\n"
                     "        -> looks untranslated. If it is meant to stay as-is (a brand "
                     "name), put\n           <!-- %s (why) --> on the line above it"
                     % (at(os.path.join(APP, d, URDU, "strings.xml"), key, entry),
                        key, text[:60], WAIVER))
    for key, entry in sorted((per[ROMAN] or {}).items()):
        text = entry["text"].strip()
        if entry["waived"]:
            continue
        if URDU_RE.search(text):
            add_fail("[%s] '%s' contains Urdu script: \"%s\"\n"
                     "        -> Roman Urdu is written in Latin letters; Urdu script belongs "
                     "in %s/" % (at(os.path.join(APP, d, ROMAN, "strings.xml"), key, entry),
                                 key, text[:60], URDU))

    print("  %-18s %3d keys (%d language-neutral)   %s %s   %s %s"
          % (d.replace("src/", "").replace("/res", ""),
             len(default), len(neutral),
             ROMAN, "-" if per[ROMAN] is None else len(per[ROMAN]),
             URDU, "-" if per[URDU] is None else len(per[URDU])))

# ── B. every flavor: English, Roman and Urdu must resolve to the same set ────
if flavors:
    print("")
    print("Flavors (English, Roman and Urdu must come from the SAME source set)")
for name in sorted(flavors):
    dirs = flavors[name]
    order = [d for d in dirs if d in res_dirs] + [BASE]

    def winner(key, loc):
        """(dir, entry) that wins for this key+locale, or (None, None)."""
        hits = [d for d in order[:-1] if key in (strings.get(d, {}).get(loc) or {})]
        if len(hits) > 1:
            add_fail("[flavor %s] key '%s' is declared for %s/ by %s — two res dirs of one "
                     "source set is the AAPT \"Duplicate resources\" build error"
                     % (name, key, loc, " and ".join(hits)))
        if hits:
            return hits[0], strings[hits[0]][loc][key]
        if key in (strings.get(BASE, {}).get(loc) or {}):
            return BASE, strings[BASE][loc][key]
        return None, None

    keys = set()
    for d in order:
        keys.update((strings.get(d, {}).get(DEFAULT) or {}).keys())

    mismatched = 0
    for key in sorted(keys):
        src_default, entry = winner(key, DEFAULT)
        if entry is None or not entry["translatable"]:
            continue
        for loc, label in LOCALES[1:]:
            src_loc, loc_entry = winner(key, loc)
            if loc_entry is None:
                continue        # already reported per folder
            if src_loc != src_default:
                mismatched += 1
                add_fail("[flavor %s] key '%s' takes English from %s but %s from %s — the %s "
                         "screen keeps %s's wording"
                         % (name, key, src_default, label, src_loc, label, src_loc))
    print("  %-6s %-52s %3d keys%s"
          % (name, " over ".join(order[:-1]) + (" over " if order[:-1] else "") + BASE,
             len(keys), "" if not mismatched else "   %d MISMATCHED" % mismatched))

print("")
for n in notes:
    print(n)

if fail:
    print("")
    for msg in fail:
        print("FAIL " + msg)
    print("")
    print("caller-lang-check FAILED (%d problem%s) — fix them before building an APK."
          % (len(fail), "" if len(fail) == 1 else "s"))
    print("Every translatable key must exist in values/, %s/ and %s/ of the SAME "
          "source set; an override needs all three." % (ROMAN, URDU))
    sys.exit(1)

print("PASS — every caller-app string exists in English, Roman Urdu and Urdu, and each "
      "build takes all three from the same source set.")
sys.exit(0)
PYEOF
