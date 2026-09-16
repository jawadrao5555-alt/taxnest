#!/usr/bin/env bash
# Reproducible full PHPUnit lane.
#
# This is deliberately committed with the release-candidate harness rather
# than assembled in an operator's shell. It enumerates the complete PHPUnit
# list, balances whole test classes into deterministic partitions, and runs
# every partition through rc-safe-run. A partitioned lane keeps the host
# command timeout from truncating a 5,000+ test run while preserving exact
# testcase/JUnit accounting.
set -uo pipefail
umask 077

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SAFE_RUN="$ROOT/scripts/rc-safe-run"
PARTITIONS=8
runtime_arg=""
report_dir="$ROOT/docs/release-candidate/recertification"

usage() {
    cat >&2 <<'EOF'
usage: scripts/rc-full-phpunit.sh [--runtime DIR] [--partitions N]
                                  [--report-dir DIR]
EOF
    exit 2
}

while (($#)); do
    case "$1" in
        --runtime)
            [[ $# -ge 2 && -n "$2" ]] || usage
            runtime_arg="$2"
            shift 2
            ;;
        --partitions)
            [[ $# -ge 2 && "$2" =~ ^[1-9][0-9]*$ ]] || usage
            PARTITIONS="$2"
            shift 2
            ;;
        --report-dir)
            [[ $# -ge 2 && -n "$2" ]] || usage
            report_dir="$2"
            shift 2
            ;;
        -h|--help) usage ;;
        *) usage ;;
    esac
done

command -v php >/dev/null 2>&1 || {
    echo "rc-full-phpunit: php is required" >&2
    exit 2
}
command -v python3 >/dev/null 2>&1 || {
    echo "rc-full-phpunit: python3 is required for exact JUnit aggregation" >&2
    exit 2
}
[[ -x "$SAFE_RUN" ]] || {
    echo "rc-full-phpunit: committed safe runner is missing: $SAFE_RUN" >&2
    exit 2
}

source_sha="$(git -C "$ROOT" rev-parse HEAD 2>/dev/null || true)"
[[ "$source_sha" =~ ^[0-9a-f]{40}$ ]] || {
    echo "rc-full-phpunit: source HEAD must be a full 40-character SHA" >&2
    exit 2
}

if [[ -n "$runtime_arg" ]]; then
    runtime="$(mkdir -p "$runtime_arg" && cd "$runtime_arg" && pwd)"
else
    runtime="$ROOT/.local/recertification/full-phpunit-$source_sha"
    mkdir -p "$runtime"
fi
for report_suffix in md json junit.xml; do
    [[ ! -e "$report_dir/full-phpunit-$source_sha.$report_suffix" ]] || {
        echo "rc-full-phpunit: refusing to overwrite report: $report_dir/full-phpunit-$source_sha.$report_suffix" >&2
        exit 2
    }
done
if [[ -e "$runtime/started" || -e "$runtime/summary.json" ]]; then
    echo "rc-full-phpunit: refusing to overwrite existing runtime: $runtime" >&2
    exit 2
fi
mkdir -p "$runtime"/{batches,logs,safe}
printf '%s\n' "$(date -u +%FT%TZ)" >"$runtime/started"

cleanup_application_caches() {
    # Tests intentionally exercise minimal schemas. Remove only known generated
    # framework files between isolated PHPUnit processes; tracked sentinels and
    # dependency metadata remain untouched.
    find "$ROOT/bootstrap/cache" -maxdepth 1 -type f -name '*.php' -delete 2>/dev/null || true
    for dir in \
        "$ROOT/storage/framework/cache/data" \
        "$ROOT/storage/framework/testing" \
        "$ROOT/storage/framework/views"; do
        [[ -d "$dir" ]] || continue
        find "$dir" -type f \
            ! -name '.gitignore' ! -name '.deploy_version' -delete 2>/dev/null || true
    done
}

list="$runtime/phpunit-list.txt"
PATH="${PATH:-/usr/bin:/bin}" bash "$SAFE_RUN" --runtime "$runtime/safe/list" -- \
    php vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result --list-tests \
    >"$list" 2>"$runtime/logs/list-tests.log"
list_count="$(grep -c '^ - ' "$list" || true)"
((list_count > 0)) || {
    echo "rc-full-phpunit: PHPUnit returned an empty test list" >&2
    exit 2
}

python3 - "$list" "$runtime/batches" "$PARTITIONS" <<'PY'
import collections
import pathlib
import re
import sys

list_path, output_dir, partition_count = sys.argv[1], pathlib.Path(sys.argv[2]), int(sys.argv[3])
classes = collections.OrderedDict()
for line in pathlib.Path(list_path).read_text(encoding="utf-8").splitlines():
    if line.startswith(" - "):
        test_name = line[3:].strip()
        class_name = test_name.split("::", 1)[0]
        classes[class_name] = classes.get(class_name, 0) + 1

batches = [[] for _ in range(partition_count)]
weights = [0] * partition_count
for class_name, count in sorted(classes.items(), key=lambda item: (-item[1], item[0])):
    index = min(range(partition_count), key=lambda value: weights[value])
    batches[index].append((class_name, count))
    weights[index] += count

output_dir.mkdir(parents=True, exist_ok=True)
for index, batch in enumerate(batches, 1):
    # PHPUnit's filter is a PCRE against the fully-qualified testcase name.
    expression = "^(?:" + "|".join(
        re.escape(class_name) + "::" for class_name, _ in batch
    ) + ")"
    (output_dir / f"batch-{index:02d}.regex").write_text(expression, encoding="utf-8")
    (output_dir / f"batch-{index:02d}.classes").write_text(
        "".join(f"{class_name}\t{count}\n" for class_name, count in batch),
        encoding="utf-8",
    )

if sum(weights) != sum(classes.values()) or sum(weights) == 0:
    raise SystemExit("partition accounting failed")
print(f"classes={len(classes)} tests={sum(weights)} partitions={partition_count}")
PY

failed=0
for ((index = 1; index <= PARTITIONS; index++)); do
    batch="$(printf '%02d' "$index")"
    regex="$(cat "$runtime/batches/batch-$batch.regex")"
    log="$runtime/logs/full-batch-$batch.log"
    junit="$runtime/batches/full-batch-$batch.junit.xml"
    printf 'RC_FULL_PHPUNIT_START batch=%s\n' "$batch"
    PATH="${PATH:-/usr/bin:/bin}" bash "$SAFE_RUN" --runtime "$runtime/safe/$batch" -- \
        php vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result \
        --filter "$regex" --log-junit "$junit" >"$log" 2>&1
    status=$?
    printf '%s\n' "$status" >"$runtime/batches/full-batch-$batch.exit"
    printf 'RC_FULL_PHPUNIT_DONE batch=%s exit=%s\n' "$batch" "$status"
    ((status == 0)) || failed=1
    cleanup_application_caches
done

mkdir -p "$report_dir"
python3 - "$runtime" "$report_dir" "$source_sha" "$list_count" "$PARTITIONS" <<'PY'
import datetime
import glob
import hashlib
import json
import os
import pathlib
import re
import sys
import xml.etree.ElementTree as ET

runtime = pathlib.Path(sys.argv[1]).resolve()
report_dir = pathlib.Path(sys.argv[2]).resolve()
source_sha = sys.argv[3]
listed_tests = int(sys.argv[4])
partition_count = int(sys.argv[5])
root = pathlib.Path.cwd().resolve()
partitions = []
testcases = []
counts = {key: 0 for key in ("tests", "failures", "errors", "skipped")}
runtime_time = 0.0

for path in sorted((runtime / "batches").glob("full-batch-*.junit.xml")):
    document = ET.parse(path).getroot()
    suites = [document] if document.tag == "testsuite" else list(document)
    values = {
        key: sum(int(suite.attrib.get(key, 0)) for suite in suites)
        for key in counts
    }
    runtime_time += sum(float(suite.attrib.get("time", 0) or 0) for suite in suites)
    counts = {key: counts[key] + values[key] for key in counts}
    status_path = path.parent / path.name.replace(".junit.xml", ".exit")
    status = int(status_path.read_text().strip()) if status_path.exists() else 2
    log = runtime / "logs" / path.name.replace(".junit.xml", ".log")
    partitions.append({
        "name": path.name,
        **values,
        "exit": status,
        "log_sha256": hashlib.sha256(log.read_bytes()).hexdigest(),
    })
    for original in document.iter("testcase"):
        testcase = ET.fromstring(ET.tostring(original, encoding="utf-8"))
        file_attr = testcase.attrib.get("file")
        if file_attr:
            testcase.attrib["file"] = file_attr.split("/source/", 1)[-1]
        for child in list(testcase):
            if child.tag in ("system-out", "system-err"):
                testcase.remove(child)
            elif child.tag in ("failure", "error"):
                child.attrib["message"] = "sanitized full-suite result"
                child.text = None
                for grandchild in list(child):
                    child.remove(grandchild)
        testcases.append(testcase)

if counts["tests"] != listed_tests or len(testcases) != listed_tests:
    raise SystemExit(
        f"coverage accounting failed: list={listed_tests}, "
        f"junit={len(testcases)}, counts={counts['tests']}"
    )

aggregate = ET.Element("testsuite", {
    "name": "taxnest-full-phpunit",
    "tests": str(counts["tests"]),
    "failures": str(counts["failures"]),
    "errors": str(counts["errors"]),
    "skipped": str(counts["skipped"]),
    "assertions": str(sum(
        int(re.search(r"Assertions: (\d+)", (runtime / "logs" /
            p["name"].replace(".junit.xml", ".log")).read_text()).group(1))
        for p in partitions
    )),
    "time": f"{runtime_time:.6f}",
})
for testcase in testcases:
    aggregate.append(testcase)

stamp = f"full-phpunit-{source_sha}"
junit_path = report_dir / f"{stamp}.junit.xml"
json_path = report_dir / f"{stamp}.json"
markdown_path = report_dir / f"{stamp}.md"
ET.ElementTree(aggregate).write(junit_path, encoding="utf-8", xml_declaration=True)
junit_sha = hashlib.sha256(junit_path.read_bytes()).hexdigest()

failures, errors = [], []
for testcase in testcases:
    label = testcase.attrib.get("classname", "").replace(".", "\\") + "::" + testcase.attrib.get("name", "")
    if testcase.find("failure") is not None:
        failures.append(label)
    if testcase.find("error") is not None:
        errors.append(label)

generated = datetime.datetime.now(datetime.timezone.utc).isoformat().replace("+00:00", "Z")
summary = {
    "schema_version": 1,
    "generated_utc": generated,
    "source_sha": source_sha,
    "source_mode": "committed-checkout",
    "listed_tests": listed_tests,
    "partitions": partition_count,
    **counts,
    "assertions": int(aggregate.attrib["assertions"]),
    "failures_names": failures,
    "errors_names": errors,
    "junit": {"path": str(junit_path.relative_to(root)), "sha256": junit_sha},
    "partition_results": partitions,
}
json_path.write_text(json.dumps(summary, indent=2, sort_keys=True) + "\n", encoding="utf-8")
with markdown_path.open("w", encoding="utf-8") as report:
    report.write("# Full PHPUnit recertification\n\n")
    report.write(f"- Source SHA: `{source_sha}`\n")
    report.write(f"- Generated UTC: `{generated}`\n")
    report.write(f"- Coverage: `{listed_tests}` listed / `{len(testcases)}` JUnit testcases\n")
    report.write(f"- Sanitized JUnit SHA-256: `{junit_sha}`\n\n")
    report.write(
        f"`tests={counts['tests']}` `assertions={aggregate.attrib['assertions']}` "
        f"`failures={counts['failures']}` `errors={counts['errors']}` "
        f"`skipped={counts['skipped']}`\n\n"
    )
    report.write("## Failing testcase names\n\n")
    for label in failures:
        report.write(f"- **failure** `{label}`\n")
    for label in errors:
        report.write(f"- **error** `{label}`\n")
print(json_path)
PY

printf 'RC_FULL_PHPUNIT_SUMMARY=%s\n' "$report_dir/full-phpunit-$source_sha.json"
exit "$failed"