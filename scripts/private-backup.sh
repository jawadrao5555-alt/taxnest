#!/bin/bash
# private-backup.sh — OWNER-RUN: one 7-Zip AES-256 archive of everything TaxNest
# deliberately keeps OUT of Git, so development can continue outside Replit.
#
# WHAT GOES IN (fixed list — each item is reported EXISTS / MISSING)
#   1. .agents/                        whole directory (agent memory, outputs, notes)
#   2. .local/                         whole directory MINUS the reproducible parts
#                                      listed under EXCLUDED (INCLUDE_ALL_LOCAL=1 = verbatim)
#   3. .env  + every other git-ignored .env* file found outside vendor/,
#      node_modules/, caches and build output (never .env.example)
#   4. every google-services.json / GoogleService-Info.plist / Firebase
#      service-account JSON found outside vendor/, node_modules/, caches, builds
#   5. storage/app/firebase/           reported MISSING when absent — not an error
#   6. .config/.android/debug.keystore if present (debug SHA-1s registered with Firebase)
#
# EXCLUDED from .local/ by default (owner's rule: no vendor / node_modules /
# caches / local MySQL data / video output):
#   .local/private-backup/                     the output directory itself
#   .local/state/                              Replit-internal runtime state (~1.8 GB)
#   .local/mysql_data/ mysql_run/ mysql_log/   the dev database (see INCLUDE_DEV_DB)
#   .local/video-studio/{node_modules,pw-cache,takes}/ and every rendered
#     *.webm *.mp4 *.mkv *.mov under .local/video-studio/ — scripts, captions,
#     JSON configs, stills and the narration audio stay in
#   .local/skills/ .local/secondary_skills/    Replit re-provisions these
#   Everything else under .local/ stays in (historical live SQL exports in
#   archive/ backups/ migration/, mail-backup/, ssh/, vps/, nayatel/, tasks/ ...).
#
# USAGE — run it yourself in the Shell tab; the password is typed into 7-Zip's
# own prompt and nowhere else:
#   bash scripts/private-backup.sh                      # .local/private-backup/taxnest-private-backup-<YYYY-MM-DD>.7z
#   bash scripts/private-backup.sh my-name.7z           # optional first argument = archive name
#   INCLUDE_DEV_DB=1    bash scripts/private-backup.sh  # + gzip'd logical dump of taxnest_staging when the
#                                                        #   dev server is up, or the raw STOPPED datadir when
#                                                        #   it is down (a running datadir is never raw-copied)
#   INCLUDE_ALL_LOCAL=1 bash scripts/private-backup.sh  # .local/ verbatim (only the output dir excluded)
#   bash scripts/private-backup.sh --dry-run            # report only: no 7-Zip, no password, no archive
#
# PASSWORD PROMPTS — nothing is shown while you type (the script switches the
# terminal to hidden input around every 7-Zip call and restores it afterwards):
#   p7zip build (nix-shell p7zip):   1) Enter password  2) Verify password  3) integrity test
#   7zz build (PATH / Nix store):    1) Enter password  2) integrity test
#      7zz has no separate verify prompt on Linux, so the integrity test IS the
#      verification: a mistyped password fails the test, the .part file stays
#      and you simply re-run the script.
#   There is deliberately NO other way to give the password: no argument, no
#   environment variable, no file, no pipe (stdin must be a terminal), no log.
#
# 7-ZIP DISCOVERY (first hit wins): 7zz / 7z on PATH -> the Nix-store 7zz build
# (glob over /nix/store/*-7zz-*/bin/7zz, the verified 24.08 preferred, newest
# usable next) -> `nix-shell -p p7zip` (resolved once, then called directly).
# Whichever is used must advertise the 7zAES (AES-256) method or the run stops.
#
# GUARANTEES
#   * 7z format, AES-256, encrypted headers (-mhe=on: even file names are
#     hidden), symbolic links stored as links (-snl, never followed).
#   * Writes <name>.7z.part and renames it to <name>.7z ONLY after a full
#     integrity test passes; a failed test leaves the .part in place with a
#     clear message. Nothing is ever deleted or modified — a metadata manifest
#     (path, size, mtime — never atime, never contents) of every source is
#     hashed before and after the run and reported as "originals untouched".
#   * Refuses to run if the output directory or the archive would be git-tracked.
#   * Never reads or prints the CONTENTS of a private file (names/sizes only).
#
# EXIT CODES
#   0 archive complete and verified (even if some requested items were MISSING)
#   1 archive verified BUT a must-preserve item is absent, or 7-Zip warned
#   2 could not run (arguments, no terminal, no 7-Zip, git guard, name in use)
#   3 7-Zip failed to create the archive
#   4 integrity test failed — .part left in place (wrong password? re-run)
#
# DEV/TEST ONLY: BACKUP_ROOT=/path points the script at another tree (a fixture)
# instead of the repository root. Never needed for the real run.

set -uo pipefail
set +x          # tracing is forbidden here even if inherited via SHELLOPTS
umask 077       # the archive and anything else we create is owner-only

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="${BACKUP_ROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"

OUT_DIR=".local/private-backup"
DEV_DB_NAME="taxnest_staging"
TODAY="$(date +%F)"
DEFAULT_NAME="taxnest-private-backup-${TODAY}.7z"

usage() {
  sed -n '2,72p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

die() { echo "private-backup: $*" >&2; exit 2; }

# ---------------------------------------------------------------- arguments
DRY_RUN=0
ARCHIVE_NAME=""
while [ $# -gt 0 ]; do
  case "$1" in
    -h|--help) usage; exit 0 ;;
    --dry-run) DRY_RUN=1; shift ;;
    -*) die "unknown option '$1' (the password is never accepted as an argument — 7-Zip prompts for it)" ;;
    *)
      [ -z "$ARCHIVE_NAME" ] || die "only one archive name is accepted"
      ARCHIVE_NAME="$1"; shift ;;
  esac
done
[ -n "$ARCHIVE_NAME" ] || ARCHIVE_NAME="$DEFAULT_NAME"
case "$ARCHIVE_NAME" in *.7z) ;; *) ARCHIVE_NAME="${ARCHIVE_NAME}.7z" ;; esac
if ! [[ "$ARCHIVE_NAME" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*\.7z$ ]]; then
  die "archive name must be a plain file name (letters, digits, . _ -), got '$ARCHIVE_NAME'"
fi

cd "$ROOT" || die "cannot enter $ROOT"

# ---------------------------------------------------------------- terminal
# The password can only be typed. A pipe or a redirect on stdin is refused so
# that no wrapper can ever feed it in silently.
TTY_STATE=""
if [ "$DRY_RUN" -eq 0 ]; then
  [ -t 0 ] || die "stdin is not a terminal — run this interactively in the Shell tab (the password is typed into 7-Zip's prompt)"
  TTY_STATE="$(stty -g 2>/dev/null || true)"
fi
restore_tty() { [ -n "$TTY_STATE" ] && stty "$TTY_STATE" 2>/dev/null; return 0; }
TMP_DIR=""
# shellcheck disable=SC2317  # invoked through the traps below
cleanup() { restore_tty; [ -n "$TMP_DIR" ] && rm -rf -- "$TMP_DIR"; }
trap cleanup EXIT
trap 'cleanup; echo; echo "private-backup: interrupted — terminal restored, nothing deleted (an unfinished .part may remain in ${OUT_DIR:-.local/private-backup}; if 7-Zip is still waiting at its prompt, press Enter)" >&2; trap - INT; kill -INT $$' INT
trap 'cleanup; exit 143' TERM
trap 'cleanup; exit 129' HUP

# Runs a 7-Zip command with terminal echo OFF so the typed password is never
# displayed (the official Linux 7zz does not hide it by itself). Echo is
# restored right after, and by the EXIT/INT traps on any interruption.
hidden_input() {
  local rc
  [ -n "$TTY_STATE" ] && stty -echo 2>/dev/null
  "$@"; rc=$?
  restore_tty
  echo
  return $rc
}

hsize() {   # bytes -> human
  if command -v numfmt >/dev/null 2>&1; then numfmt --to=iec-i --suffix=B --format='%.1f' "$1" 2>/dev/null && return 0; fi
  echo "$1 B"
}
dusize() {  # directory/file -> human apparent size (metadata only, same unit style as hsize)
  local b; b="$(du -sb -- "$1" 2>/dev/null | cut -f1)"; hsize "${b:-0}"
}
git_ok() { git rev-parse --is-inside-work-tree >/dev/null 2>&1; }
is_ignored() { git check-ignore -q -- "$1" 2>/dev/null; }

# ---------------------------------------------------------------- 7-Zip
SEVENZIP=""; SEVENZIP_SOURCE=""; SEVENZIP_FLAVOUR=""; SEVENZIP_BANNER=""
sevenzip_ok() { [ -x "$1" ] && "$1" i 2>/dev/null | grep -q '7zAES'; }
find_sevenzip() {
  local c v
  for c in 7zz 7z; do
    if command -v "$c" >/dev/null 2>&1; then
      c="$(command -v "$c")"
      if sevenzip_ok "$c"; then SEVENZIP="$c"; SEVENZIP_SOURCE="PATH"; return 0; fi
    fi
  done
  local -a cands=() ordered=()
  shopt -s nullglob; cands=(/nix/store/*-7zz-*/bin/7zz); shopt -u nullglob
  if [ "${#cands[@]}" -gt 0 ]; then
    # verified 24.08 first, then newest first; a store path that does not run is skipped
    mapfile -t ordered < <(
      for c in "${cands[@]}"; do v="${c%/bin/7zz}"; v="${v##*-7zz-}"; printf '%s\t%s\t%s\n' "$([ "$v" = "24.08" ] && echo 1 || echo 0)" "$v" "$c"; done \
        | sort -t "$(printf '\t')" -k1,1r -k2,2Vr | cut -f3)
    for c in "${ordered[@]}"; do
      if sevenzip_ok "$c"; then SEVENZIP="$c"; SEVENZIP_SOURCE="Nix store"; return 0; fi
    done
  fi
  if command -v nix-shell >/dev/null 2>&1; then
    echo "No 7-Zip on PATH and no usable Nix-store 7zz — resolving p7zip through nix-shell (about 15 s)..."
    c="$(nix-shell -p p7zip --run 'command -v 7z' 2>/dev/null | tail -n 1)"
    if [ -n "$c" ] && sevenzip_ok "$c"; then SEVENZIP="$c"; SEVENZIP_SOURCE="nix-shell p7zip"; return 0; fi
  fi
  return 1
}
find_sevenzip || die "no 7-Zip with AES-256 found (tried PATH, /nix/store/*-7zz-*/bin/7zz, nix-shell -p p7zip)"
SEVENZIP_BANNER="$("$SEVENZIP" i 2>/dev/null | grep -m1 -oE '(7-Zip|p7zip)[^:]*' | sed 's/ *$//')"
if "$SEVENZIP" i 2>/dev/null | head -n 3 | grep -qi 'p7zip'; then SEVENZIP_FLAVOUR="p7zip"; else SEVENZIP_FLAVOUR="7zz"; fi

# ---------------------------------------------------------------- output paths + git guard
FINAL="$OUT_DIR/$ARCHIVE_NAME"
PART="$FINAL.part"
if git_ok; then
  if ! is_ignored "$OUT_DIR" || ! is_ignored "$FINAL"; then
    die "refusing: $OUT_DIR / $FINAL would be git-TRACKED (not covered by .gitignore) — this repo is public"
  fi
  if [ -n "$(git ls-files -- "$OUT_DIR" 2>/dev/null)" ]; then
    die "refusing: git already tracks files under $OUT_DIR"
  fi
  GIT_NOTE="git-ignored (checked with git check-ignore)"
else
  GIT_NOTE="not a git work tree here — nothing can be committed from this location"
fi
[ -e "$FINAL" ] && die "$FINAL already exists — pass a different name (nothing is ever overwritten)"
if [ -e "$PART" ]; then
  # an unverified leftover from an earlier run is never deleted; work beside it
  n=2; while [ -e "$FINAL.part.$n" ]; do n=$((n+1)); done; PART="$FINAL.part.$n"
fi
LEFTOVER_PARTS=()
shopt -s nullglob; for f in "$OUT_DIR"/*.part "$OUT_DIR"/*.part.*; do LEFTOVER_PARTS+=("$f"); done; shopt -u nullglob
# The output dir is created now, BEFORE the metadata manifest is taken: creating it later
# would bump .local/'s own mtime and make the "originals untouched" check cry wolf.
if [ "$DRY_RUN" -eq 0 ]; then
  if ! mkdir -p -- "$OUT_DIR" || ! chmod 700 -- "$OUT_DIR"; then die "cannot create $OUT_DIR"; fi
fi

# ---------------------------------------------------------------- sources
declare -a SRC=() SRC_LABEL=() MISSING=() OPTIONAL_ABSENT=() SKIPPED_TRACKED=()
add_src() {   # <path> <label> <required: yes|optional>
  if [ -e "$1" ] || [ -L "$1" ]; then SRC+=("$1"); SRC_LABEL+=("$2")
  elif [ "$3" = "optional" ]; then OPTIONAL_ABSENT+=("$1  ($2)")
  else MISSING+=("$1  ($2)")
  fi
}
add_src ".agents"                          "agent memory / outputs / notes"                 yes
add_src ".local"                           "private ops tree (see exclusions)"              yes
add_src ".env"                             "Laravel environment"                            yes
add_src "storage/app/firebase"             "Firebase service files (if ever added)"         yes
add_src ".config/.android/debug.keystore"  "Android debug signing identity"                 optional

# git-ignored .env* files and Firebase config files anywhere else in the tree
# (vendor/, node_modules/, caches, build output and the trees above are pruned)
mapfile -t FOUND < <(
  find . -xdev \( -name .git -o -name vendor -o -name node_modules -o -path ./.local -o -path ./.agents \
                  -o -path ./.config -o -path ./attached_assets -o -path ./storage -o -path ./bootstrap/cache \
                  -o -path ./public/build -o -name dist -o -name build -o -name .cache -o -name .npm \
                  -o -name .pythonlibs -o -name .gradle -o -path ./tools/video-pipeline/out \) -prune -o \
         -type f \( -name '.env*' -o -name 'google-services.json' -o -name 'GoogleService-Info.plist' \
                    -o -name '*firebase-adminsdk*.json' -o -name '*service-account*.json' \
                    -o -name '*service_account*.json' -o -name 'serviceAccountKey.json' \
                    -o -name 'firebase-credentials.json' \) -print 2>/dev/null | sed 's#^\./##' | LC_ALL=C sort)
for f in ${FOUND[@]+"${FOUND[@]}"}; do
  [ "$f" = ".env" ] && continue                       # already the fixed item 3
  case "$f" in .env.example|*/.env.example) continue ;; esac
  if git_ok && ! is_ignored "$f"; then SKIPPED_TRACKED+=("$f"); continue; fi
  case "$f" in
    *google-services.json)      add_src "$f" "Firebase Android config" yes ;;
    *GoogleService-Info.plist)  add_src "$f" "Firebase iOS config" yes ;;
    .env*|*/.env*)              add_src "$f" "extra git-ignored env file" yes ;;
    *)                          add_src "$f" "Firebase service-account JSON" yes ;;
  esac
done
GS_COUNT=0; for f in "${SRC[@]}"; do case "$f" in *google-services.json) GS_COUNT=$((GS_COUNT+1)) ;; esac; done

# ---------------------------------------------------------------- exclusions
INCLUDE_ALL_LOCAL="${INCLUDE_ALL_LOCAL:-0}"
INCLUDE_DEV_DB="${INCLUDE_DEV_DB:-0}"
declare -a EXCL_DIRS=() MEDIA_EXT=() EXCL_REASON=()
if [ "$INCLUDE_ALL_LOCAL" = "1" ]; then
  EXCL_DIRS=(); MEDIA_EXT=()
else
  EXCL_DIRS=( .local/state .local/mysql_data .local/mysql_run .local/mysql_log
              .local/video-studio/node_modules .local/video-studio/pw-cache .local/video-studio/takes
              .local/skills .local/secondary_skills )
  EXCL_REASON=( "Replit runtime state" "dev DB datadir" "dev DB run dir" "dev DB logs"
                "video deps" "Playwright cache" "video takes"
                "Replit-provisioned skills" "Replit-provisioned skills" )
  MEDIA_EXT=( webm mp4 mkv mov WEBM MP4 MKV MOV )
fi

# ---------------------------------------------------------------- dev DB (opt-in)
DEVDB_NOTE="skipped (INCLUDE_DEV_DB not set)"
DUMP_FILE=""
RAW_DATADIR=0
devdb_probe() { [ -f "scripts/dev-mysql-ready.sh" ] && bash scripts/dev-mysql-ready.sh "$@" --quiet; }
devdb_running() {
  pgrep -f -- "mysqld.*${ROOT}/.local/mysql_run/my.cnf" >/dev/null 2>&1 && return 0
  devdb_probe
}
if [ "$INCLUDE_DEV_DB" = "1" ]; then
  if devdb_running; then
    if devdb_probe --wait 30; then
      if ! command -v mysqldump >/dev/null 2>&1; then
        DEVDB_NOTE="SKIPPED — server is up but mysqldump is not installed (a running datadir is never raw-copied)"
      elif [ "$DRY_RUN" -eq 1 ]; then
        DEVDB_NOTE="would take a gzip'd logical dump of $DEV_DB_NAME (server is up) — dry run, nothing dumped"
      else
        DUMP_FILE="$OUT_DIR/dev-db-${DEV_DB_NAME}-$(date +%F-%H%M%S).sql.gz"
        echo "Dumping dev database $DEV_DB_NAME -> $DUMP_FILE ..."
        if mysqldump --defaults-file=".local/mysql_run/my.cnf" --protocol=TCP -h 127.0.0.1 -P 9000 -u root \
             --single-transaction --quick --routines --triggers --events --set-gtid-purged=OFF "$DEV_DB_NAME" \
             2>"$OUT_DIR/.dump-stderr.tmp" | gzip -c > "$DUMP_FILE.part" && [ "${PIPESTATUS[0]}" -eq 0 ]; then
          mv -n -- "$DUMP_FILE.part" "$DUMP_FILE"
          rm -f -- "$OUT_DIR/.dump-stderr.tmp"
          DEVDB_NOTE="gzip'd logical dump of $DEV_DB_NAME included: $DUMP_FILE ($(hsize "$(stat -c %s -- "$DUMP_FILE")"))"
        else
          DEVDB_NOTE="SKIPPED — mysqldump failed (details: $OUT_DIR/.dump-stderr.tmp; partial output left at $DUMP_FILE.part)"
          DUMP_FILE=""
        fi
      fi
    else
      DEVDB_NOTE="SKIPPED — mysqld is running but not accepting connections after 30 s; a running datadir is never raw-copied. Retry later, or stop the 'MySQL Staging' workflow to get the raw datadir."
    fi
  else
    RAW_DATADIR=1
    if [ -d ".local/mysql_data" ]; then
      DEVDB_NOTE="server is DOWN — raw stopped datadir .local/mysql_data/ included ($(dusize .local/mysql_data))"
      keep_d=(); keep_r=(); i=0
      for d in ${EXCL_DIRS[@]+"${EXCL_DIRS[@]}"}; do
        if [ "$d" != ".local/mysql_data" ]; then keep_d+=("$d"); keep_r+=("${EXCL_REASON[$i]}"); fi
        i=$((i+1))
      done
      EXCL_DIRS=(${keep_d[@]+"${keep_d[@]}"}); EXCL_REASON=(${keep_r[@]+"${keep_r[@]}"})
    else
      DEVDB_NOTE="server is DOWN and .local/mysql_data/ does not exist — nothing to include"
    fi
  fi
fi

# ---------------------------------------------------------------- 7-Zip argument list
# Everything inside the output dir is excluded from the archive except a dump
# made by this very run (7-Zip's exclude filters win over explicit names, so the
# dir is excluded entry by entry when a dump must ride along).
declare -a SZ_EXCL=()
if [ -n "$DUMP_FILE" ]; then
  shopt -s nullglob
  for e in "$OUT_DIR"/* "$OUT_DIR"/.[!.]*; do
    [ "$e" = "$DUMP_FILE" ] || SZ_EXCL+=("-x!$e")
  done
  shopt -u nullglob
else
  SZ_EXCL+=("-x!$OUT_DIR")
fi
for d in ${EXCL_DIRS[@]+"${EXCL_DIRS[@]}"}; do SZ_EXCL+=("-x!$d"); done
for e in ${MEDIA_EXT[@]+"${MEDIA_EXT[@]}"}; do SZ_EXCL+=("-xr!.local/video-studio/*.$e"); done

# ---------------------------------------------------------------- manifest (metadata only)
# find expression mirroring the exclusions; the output dir is always pruned here
# because the archive itself appears in it during the run.
declare -a FIND_EXPR=()
FIND_EXPR+=( '(' -path "$OUT_DIR" )
for d in ${EXCL_DIRS[@]+"${EXCL_DIRS[@]}"}; do FIND_EXPR+=( -o -path "$d" ); done
FIND_EXPR+=( ')' -prune -o )
if [ "${#MEDIA_EXT[@]}" -gt 0 ]; then
  FIND_EXPR+=( '(' -path '.local/video-studio/*' '(' )
  first=1; for e in "${MEDIA_EXT[@]}"; do [ "$first" -eq 1 ] || FIND_EXPR+=( -o ); FIND_EXPR+=( -name "*.$e" ); first=0; done
  FIND_EXPR+=( ')' ')' -prune -o )
fi
manifest() {
  # type, size, mtime, path, link target — never atime, never contents.
  find "${SRC[@]}" -xdev "${FIND_EXPR[@]}" '(' -type p -o -type s ')' -o -printf '%y\t%s\t%T@\t%p\t%l\n' 2>/dev/null \
    | LC_ALL=C sort
}
TMP_DIR="$(mktemp -d)" || die "cannot create a temp dir"
manifest > "$TMP_DIR/before"
BEFORE_HASH="$(sha256sum < "$TMP_DIR/before" | cut -c1-16)"
BEFORE_ENTRIES="$(wc -l < "$TMP_DIR/before")"
src_stats() {   # <source path> -> "<files>\t<bytes>" from the before-manifest
  awk -F'\t' -v p="$1" '($4==p || index($4, p "/")==1) && $1=="f" {n++; s+=$2} END {printf "%d\t%d\n", n+0, s+0}' "$TMP_DIR/before"
}
TOTAL_BYTES="$(awk -F'\t' '$1=="f" {s+=$2} END {print s+0}' "$TMP_DIR/before")"
TOTAL_FILES="$(awk -F'\t' '$1=="f" {n++} END {print n+0}' "$TMP_DIR/before")"

# ---------------------------------------------------------------- must-preserve checklist (names only)
MUST=( .local/rider-signing/rider-release.p12 .local/rider-signing/password.txt
       .local/ssh/nayatel_vps_key .local/ssh/nayatel_vps_key.pub .local/ssh/known_hosts
       .local/qa-creds.env .local/mail-creds.env .local/mail-backup .local/vps .local/nayatel
       .agents/memory .env
       pos-app/app/google-services.json fbr-pos-app/app/google-services.json rider-app/app/google-services.json )
MUST_ABSENT=0
declare -a MUST_LINES=()
for m in "${MUST[@]}"; do
  if [ -e "$m" ]; then MUST_LINES+=("  [x] $m")
  else MUST_LINES+=("  [ ] $m   <-- ABSENT"); MUST_ABSENT=$((MUST_ABSENT+1)); fi
done

# ---------------------------------------------------------------- plan
echo "TaxNest private backup"
echo "  root      : $ROOT"
echo "  archive   : $FINAL   (written as $PART first)"
echo "  output dir: $GIT_NOTE"
echo "  7-Zip     : $SEVENZIP  [$SEVENZIP_BANNER, via $SEVENZIP_SOURCE]"
echo "  sources   : ${#SRC[@]} items, $TOTAL_FILES files, $(hsize "$TOTAL_BYTES") after exclusions"
[ "$INCLUDE_ALL_LOCAL" = "1" ] && echo "  INCLUDE_ALL_LOCAL=1: .local/ goes in verbatim"
echo "  dev DB    : $DEVDB_NOTE"
echo

RC=0
SZ_RC=0
TEST_RESULT="not run"
ARCHIVE_SIZE=""
if [ "$DRY_RUN" -eq 1 ]; then
  TEST_RESULT="not run (dry run)"
  echo "DRY RUN — 7-Zip is not invoked, no password is asked, nothing is written."
  echo
else
  if [ "$SEVENZIP_FLAVOUR" = "p7zip" ]; then
    echo "7-Zip will ask for the archive password THREE times: (1) Enter password, (2) Verify password,"
    echo "(3) once more for the integrity test. Typing is hidden — nothing appears while you type."
  else
    echo "7-Zip will ask for the archive password TWICE: (1) to create the archive, (2) for the integrity test."
    echo "This 7-Zip build has no separate verify prompt, so the test IS the verification: if the two entries"
    echo "differ the test fails, the .part file stays, and you simply re-run. Typing is hidden — nothing"
    echo "appears while you type. Press Enter after the password."
  fi
  echo
  # -t7z 7z container   -mhe=on encrypt headers (names hidden)   -snl store symlinks as links
  # -p    ask for the password interactively (no value is ever attached)
  # -bb0  no per-file chatter
  hidden_input "$SEVENZIP" a -t7z -mhe=on -snl -p -bb0 ${SZ_EXCL[@]+"${SZ_EXCL[@]}"} -- "$PART" "${SRC[@]}" ${DUMP_FILE:+"$DUMP_FILE"}
  SZ_RC=$?
  if [ "$SZ_RC" -ne 0 ] && [ "$SZ_RC" -ne 1 ]; then
    echo
    echo "7-Zip could not create the archive (exit $SZ_RC). Nothing was renamed; no source was touched."
    [ -e "$PART" ] && echo "An incomplete file may remain at $PART — it is unverified; re-run to make a fresh one."
    RC=3
  else
    [ "$SZ_RC" -eq 1 ] && echo "NOTE: 7-Zip finished with WARNINGS (exit 1) — scroll up; some items may have been skipped or changed while reading."
    echo
    echo "Integrity test of $PART — 7-Zip asks for the password again:"
    hidden_input "$SEVENZIP" t -bb0 -- "$PART"
    T_RC=$?
    if [ "$T_RC" -eq 0 ]; then
      TEST_RESULT="PASSED"
      if mv -n -- "$PART" "$FINAL" && [ -e "$FINAL" ] && [ ! -e "$PART" ]; then
        ARCHIVE_SIZE="$(stat -c %s -- "$FINAL")"
      else
        echo "Could not rename $PART to $FINAL — the verified archive is still at $PART."
        RC=3
      fi
    else
      TEST_RESULT="FAILED (7-Zip exit $T_RC)"
      echo
      echo "INTEGRITY TEST FAILED. The unverified archive was LEFT at:  $PART"
      echo "Most likely the password typed for the test differs from the one used to create the archive"
      echo "(a typo at either prompt). Nothing was deleted or renamed. Simply re-run:"
      echo "    bash scripts/private-backup.sh $ARCHIVE_NAME"
      echo "or test that file yourself first:  $SEVENZIP t $PART"
      RC=4
    fi
  fi
fi

# ---------------------------------------------------------------- originals untouched?
manifest > "$TMP_DIR/after"
AFTER_HASH="$(sha256sum < "$TMP_DIR/after" | cut -c1-16)"
if cmp -s "$TMP_DIR/before" "$TMP_DIR/after"; then
  UNTOUCHED="YES — metadata manifest unchanged ($BEFORE_ENTRIES entries, sha256 $BEFORE_HASH)"
else
  UNTOUCHED="CHANGED — manifest differs (before $BEFORE_HASH, after $AFTER_HASH). Entries that differ (names only):"
  DIFF_LINES="$(diff "$TMP_DIR/before" "$TMP_DIR/after" | grep -E '^[<>]' | cut -f4 | LC_ALL=C sort -u | head -n 20)"
  DIFF_LINES="${DIFF_LINES:-(none listed)}"
fi

# ---------------------------------------------------------------- report
echo
echo "=================== private backup — report ==================="
if [ -n "$ARCHIVE_SIZE" ]; then
  echo "Archive        : $FINAL"
  echo "Size           : $(hsize "$ARCHIVE_SIZE") ($ARCHIVE_SIZE bytes) — AES-256, encrypted headers, symlinks as links"
elif [ "$DRY_RUN" -eq 1 ]; then
  echo "Archive        : (dry run) would be $FINAL"
else
  echo "Archive        : NOT finalised — see above ($PART)"
fi
echo "7-Zip          : $SEVENZIP_BANNER via $SEVENZIP_SOURCE"
echo "Integrity test : $TEST_RESULT"
echo "Originals      : $UNTOUCHED"
if [ -n "${DIFF_LINES:-}" ]; then
  while IFS= read -r line; do printf '                 %s\n' "$line"; done <<< "$DIFF_LINES"
fi
echo
echo "Included (top level, sizes after exclusions):"
i=0
for s in "${SRC[@]}"; do
  IFS=$'\t' read -r n b <<< "$(src_stats "$s")"
  if [ -d "$s" ]; then printf '  EXISTS   %-38s %8s  %6d files   %s\n' "$s/" "$(hsize "$b")" "$n" "${SRC_LABEL[$i]}"
  else printf '  EXISTS   %-38s %8s               %s\n' "$s" "$(hsize "$b")" "${SRC_LABEL[$i]}"; fi
  i=$((i+1))
done
[ -n "$DUMP_FILE" ] && printf '  EXISTS   %-38s %8s               %s\n' "$DUMP_FILE" "$(hsize "$(stat -c %s -- "$DUMP_FILE")")" "dev DB dump made by this run"
for m in ${MISSING[@]+"${MISSING[@]}"}; do printf '  MISSING  %s\n' "$m"; done
for m in ${OPTIONAL_ABSENT[@]+"${OPTIONAL_ABSENT[@]}"}; do printf '  absent   %s — optional, nothing to do\n' "$m"; done
echo
if [ "${#MISSING[@]}" -gt 0 ]; then
  echo "Requested but MISSING (reported only — exit code unaffected):"
  for m in "${MISSING[@]}"; do echo "  - $m"; done
else
  echo "Requested but MISSING: none"
fi
[ "$GS_COUNT" -ne 3 ] && echo "  NOTE: expected 3 google-services.json files, found $GS_COUNT"
for f in ${SKIPPED_TRACKED[@]+"${SKIPPED_TRACKED[@]}"}; do echo "  (found but git-tracked or not ignored, so Git already has it: $f)"; done
echo
echo "Excluded by design:"
if [ -d "$OUT_DIR" ]; then printf '  %-42s %8s  %s\n' "$OUT_DIR/" "$(dusize "$OUT_DIR")" "the output itself"
else printf '  %-42s %8s  %s\n' "$OUT_DIR/" "-" "the output itself (not created yet)"; fi
i=0
for d in ${EXCL_DIRS[@]+"${EXCL_DIRS[@]}"}; do
  if [ -e "$d" ]; then printf '  %-42s %8s  %s\n' "$d/" "$(dusize "$d")" "${EXCL_REASON[$i]}"; fi
  i=$((i+1))
done
if [ "${#MEDIA_EXT[@]}" -gt 0 ] && [ -d .local/video-studio ]; then
  MEDIA_STATS="$(find .local/video-studio -xdev -type f \( -name '*.webm' -o -name '*.mp4' -o -name '*.mkv' -o -name '*.mov' -o -name '*.WEBM' -o -name '*.MP4' -o -name '*.MKV' -o -name '*.MOV' \) -printf '%s\n' 2>/dev/null | awk '{n++; s+=$1} END {printf "%d\t%d", n+0, s+0}')"
  IFS=$'\t' read -r mn mb <<< "$MEDIA_STATS"
  printf '  %-42s %8s  %s\n' ".local/video-studio/**/*.{webm,mp4,mkv,mov}" "$(hsize "$mb")" "$mn rendered media files"
fi
[ "$INCLUDE_ALL_LOCAL" = "1" ] && echo "  (INCLUDE_ALL_LOCAL=1 — only the output dir was excluded)"
echo
echo "Must-preserve checklist:"
printf '%s\n' "${MUST_LINES[@]}"
echo
echo "Dev DB          : $DEVDB_NOTE"
[ "$RAW_DATADIR" -eq 1 ] && [ "$DRY_RUN" -eq 0 ] && [ -n "$ARCHIVE_SIZE" ] && echo "                  (raw datadir taken while mysqld was stopped)"
echo
echo "Present in the workspace, NOT included (out of scope by the owner's rule):"
for p in attached_assets storage/app/private storage/app/public pra-agent/dist rider-app/dist vendor node_modules tools/video-pipeline/out backup.sql routes-update.zip; do
  [ -e "$p" ] && printf '  %-42s %8s\n' "$p" "$(dusize "$p")"
done
shopt -s nullglob; for p in pra-agent-*.zip; do printf '  %-42s %8s\n' "$p" "$(dusize "$p")"; done; shopt -u nullglob
if [ "${#LEFTOVER_PARTS[@]}" -gt 0 ]; then
  echo
  echo "Leftover unverified files from earlier runs (never deleted by this script; remove by hand when sure):"
  for f in "${LEFTOVER_PARTS[@]}"; do printf '  %-42s %8s\n' "$f" "$(dusize "$f")"; done
fi
echo

# ---------------------------------------------------------------- verdict
if [ "$RC" -eq 0 ] && [ "$DRY_RUN" -eq 0 ]; then
  if [ "$MUST_ABSENT" -gt 0 ]; then
    echo "RESULT: archive verified, BUT $MUST_ABSENT must-preserve item(s) are ABSENT from this workspace (see checklist)."; RC=1
  elif [ "$SZ_RC" -eq 1 ]; then
    echo "RESULT: archive verified, BUT 7-Zip reported warnings while reading — check them before relying on it."; RC=1
  else
    echo "RESULT: OK — $FINAL is complete and verified. Download it from the Files pane (.local/private-backup/)."
  fi
elif [ "$DRY_RUN" -eq 1 ]; then
  if [ "$MUST_ABSENT" -gt 0 ]; then echo "RESULT: dry run — $MUST_ABSENT must-preserve item(s) ABSENT (see checklist)."; RC=1
  else echo "RESULT: dry run complete — run without --dry-run to create the archive."; fi
else
  echo "RESULT: FAILED (exit $RC) — see the messages above."
fi
echo "exit code: $RC"
exit "$RC"
