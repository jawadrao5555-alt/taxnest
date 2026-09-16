#!/usr/bin/env python3
"""Exercise the isolated MariaDB startup ownership and safety contract.

Every MariaDB executable, account lookup, and ownership operation is a local
fixture.  The lab is pointed at a disposable temporary root and never gets a
real server, socket, or chown implementation.
"""
from pathlib import Path
import os
import shutil
import stat
import subprocess
import tempfile


ROOT = Path(__file__).resolve().parents[2]
LAB = ROOT / "scripts/rc-mariadb-lab.sh"
BASH = shutil.which("bash")
checks = 0
results = []


def fail(message):
    raise AssertionError(message)


def assert_true(condition, message):
    if not condition:
        fail(message)


def mode(path):
    return stat.S_IMODE(path.stat().st_mode)


def event_rows(events):
    if not events.exists():
        return []
    return [line.split("\t") for line in events.read_text().splitlines() if line]


def row_names(rows):
    return [row[0] for row in rows]


class Fixture:
    def __init__(self, temp):
        self.temp = Path(temp)
        self.root = self.temp / "root"
        self.root.mkdir(mode=0o700)
        self.tools = self.temp / "tools"
        self.tools.mkdir()
        self.events = self.temp / "events.log"
        self.id_log = self.temp / "id.log"
        self.server_marker = self.temp / "server.started"
        self.install_marker = self.temp / "install.called"
        self.data = self.root / "data"
        self.run_dir = self.root / "run"
        self.log = self.root / "log"
        self.home = self.root / "home"
        self.config = self.root / "my.cnf"
        self._write_tools()

    def _write(self, name, body):
        path = self.tools / name
        path.write_text(f"#!{BASH}\nset -Eeuo pipefail\n{body}\n")
        path.chmod(0o755)
        return path

    def _write_tools(self):
        self._write(
            "id",
            r"""
{
    printf '%s' "$1"
    [[ $# -gt 1 ]] && printf '\t%s' "$2"
    printf '\n'
} >> "$MOCK_ID_LOG"
if [[ "${1:-}" == "-u" && $# -eq 1 ]]; then
    [[ "${MOCK_ID_FAIL_CURRENT:-0}" != 1 ]] || exit 1
    printf '%s\n' "${MOCK_CURRENT_UID:-0}"
elif [[ "${1:-}" == "-u" && "${2:-}" == "mysql" ]]; then
    [[ "${MOCK_ID_FAIL_MYSQL_UID:-0}" != 1 ]] || exit 1
    printf '%s\n' "${MOCK_MYSQL_UID_OUTPUT:-${MOCK_MYSQL_UID:-1234}}"
elif [[ "${1:-}" == "-g" && "${2:-}" == "mysql" ]]; then
    [[ "${MOCK_ID_FAIL_MYSQL_GID:-0}" != 1 ]] || exit 1
    printf '%s\n' "${MOCK_MYSQL_GID_OUTPUT:-${MOCK_MYSQL_GID:-2345}}"
elif [[ "${1:-}" == "-g" && $# -eq 1 ]]; then
    printf '%s\n' "${MOCK_CURRENT_GID:-1000}"
else
    exit 1
fi
""",
        )
        self._write(
            "chown",
            r"""
{
    printf 'chown'
    for arg in "$@"; do printf '\t%s' "$arg"; done
    printf '\n'
} >> "$MOCK_EVENTS"
[[ "${MOCK_CHOWN_FAIL:-0}" != 1 ]]
""",
        )
        self._write(
            "mariadbd",
            r"""
if [[ "${1:-}" == "--version" ]]; then
    printf '%s\n' 'mariadbd Ver 10.6.23-MariaDB fixture'
    exit 0
fi
{
    printf 'server'
    for arg in "$@"; do printf '\t%s' "$arg"; done
    printf '\n'
} >> "$MOCK_EVENTS"
touch "$MOCK_SERVER_MARKER"
""",
        )
        self._write(
            "mariadb",
            r"""
if [[ "${1:-}" == "--version" ]]; then
    printf '%s\n' 'mariadb Ver 10.6.23-MariaDB fixture'
    exit 0
fi
exit 0
""",
        )
        self._write(
            "mariadb-admin",
            r"""
if [[ "${1:-}" == "--version" ]]; then
    printf '%s\n' 'mariadb-admin Ver 10.6.23-MariaDB fixture'
    exit 0
fi
is_ping=0
is_shutdown=0
for arg in "$@"; do
    [[ "$arg" == "ping" ]] && is_ping=1
    [[ "$arg" == "shutdown" ]] && is_shutdown=1
done
if (( is_ping )); then
    [[ -e "$MOCK_SERVER_MARKER" ]]
elif (( is_shutdown )); then
    exit 0
else
    exit 1
fi
""",
        )
        self._write(
            "mariadb-install-db",
            r"""
{
    printf 'install'
    for arg in "$@"; do printf '\t%s' "$arg"; done
    printf '\n'
} >> "$MOCK_EVENTS"
datadir="$MOCK_DATA"
for arg in "$@"; do
    case "$arg" in
        --datadir=*) datadir="${arg#--datadir=}" ;;
    esac
done
mkdir -p "$datadir/mysql"
touch "$MOCK_INSTALL_MARKER"
""",
        )

    def environment(self, **overrides):
        env = os.environ.copy()
        env.update(
            {
                "PATH": f"{self.tools}:{env.get('PATH', '')}",
                "HOME": str(self.temp / "outside-home"),
                "RC_MARIADB_ROOT": str(self.root),
                "RC_MARIADB_PORT": "33116",
                "RC_MARIADBD": str(self.tools / "mariadbd"),
                "RC_MARIADB_CLIENT": str(self.tools / "mariadb"),
                "RC_MARIADB_ADMIN": str(self.tools / "mariadb-admin"),
                "RC_MARIADB_INSTALL_DB": str(self.tools / "mariadb-install-db"),
                "MOCK_EVENTS": str(self.events),
                "MOCK_ID_LOG": str(self.id_log),
                "MOCK_SERVER_MARKER": str(self.server_marker),
                "MOCK_INSTALL_MARKER": str(self.install_marker),
                "MOCK_DATA": str(self.data),
                "MOCK_CURRENT_UID": "0",
                "MOCK_MYSQL_UID": "1234",
                "MOCK_MYSQL_GID": "2345",
            }
        )
        env.update({key: str(value) for key, value in overrides.items()})
        return env

    def run(self, **overrides):
        return subprocess.run(
            [BASH, str(LAB), "start"],
            env=self.environment(**overrides),
            capture_output=True,
            text=True,
            timeout=8,
        )


def assert_success(result, label):
    assert_true(
        result.returncode == 0,
        f"{label} failed ({result.returncode}): {result.stdout}\n{result.stderr}",
    )


def assert_failure(result, label):
    assert_true(
        result.returncode != 0,
        f"{label} unexpectedly succeeded: {result.stdout}\n{result.stderr}",
    )


def assert_no_start(fixture, label):
    rows = event_rows(fixture.events)
    names = row_names(rows)
    assert_true("install" not in names, f"{label} invoked install-db")
    assert_true("server" not in names, f"{label} started mariadbd")


def assert_user_and_defaults(rows, command, expected_user):
    matching = [row[1:] for row in rows if row[0] == command]
    assert_true(matching, f"missing {command} invocation")
    args = matching[-1]
    assert_true(
        args and args[0].startswith("--defaults-file="),
        f"{command} did not keep --defaults-file first: {args}",
    )
    assert_true(
        f"--user={expected_user}" in args,
        f"{command} did not receive the fixed account: {args}",
    )


def assert_root_ownership(fixture):
    rows = event_rows(fixture.events)
    chowns = [row[1:] for row in rows if row[0] == "chown"]
    assert_true(chowns, "root startup did not issue ownership operations")
    expected_owner = "1234:2345"
    root_owner = "0:2345"
    expected_recursive = {
        str(fixture.data),
        str(fixture.run_dir),
        str(fixture.log),
        str(fixture.home),
    }
    recursive_paths = set()
    saw_root = False
    saw_config = False
    for args in chowns:
        if root_owner in args:
            saw_root = saw_root or str(fixture.root) in args
            saw_config = saw_config or str(fixture.config) in args
        if expected_owner in args and "--no-dereference" in args:
            recursive_paths.update(
                path for path in expected_recursive if path in args
            )
    assert_true(saw_root, f"root ownership was not set to {root_owner}: {chowns}")
    assert_true(
        saw_config, f"config ownership was not set to {root_owner}: {chowns}"
    )
    assert_true(
        recursive_paths == expected_recursive,
        f"recursive mysql ownership missed paths: {chowns}",
    )
    for args in chowns:
        if expected_owner in args and any(
            path in args for path in expected_recursive
        ):
            assert_true(
                "-R" in args or "--recursive" in args,
                f"directory ownership was not recursive: {args}",
            )
            assert_true(
                "--no-dereference" in args,
                f"directory ownership followed symlinks: {args}",
            )


def assert_all_ownership_precedes_start(fixture):
    rows = event_rows(fixture.events)
    last_chown = max(
        (index for index, row in enumerate(rows) if row[0] == "chown"),
        default=-1,
    )
    starts = [
        index
        for index, row in enumerate(rows)
        if row[0] in {"install", "server"}
    ]
    assert_true(last_chown >= 0, "no ownership event was recorded")
    assert_true(starts, "no install or server event was recorded")
    assert_true(
        last_chown < min(starts),
        f"ownership was not complete before startup: {rows}",
    )


def root_fresh_case():
    with tempfile.TemporaryDirectory(prefix="taxnest-rc-mariadb-startup-") as temp:
        fixture = Fixture(temp)
        result = fixture.run(RC_MARIADB_USER="root")
        assert_success(result, "root fresh datadir")
        rows = event_rows(fixture.events)
        assert_user_and_defaults(rows, "install", "mysql")
        assert_user_and_defaults(rows, "server", "mysql")
        assert_root_ownership(fixture)
        assert_all_ownership_precedes_start(fixture)
        for path in (
            fixture.root,
            fixture.data,
            fixture.run_dir,
            fixture.log,
            fixture.home,
        ):
            assert_true(path.is_dir(), f"startup did not create {path}")
            assert_true(mode(path) == 0o750, f"{path} mode is {oct(mode(path))}")
        assert_true(
            fixture.config.is_file() and mode(fixture.config) == 0o640,
            "root config does not have mode 0640",
        )
        results.append("root fresh datadir")


def root_existing_case():
    with tempfile.TemporaryDirectory(prefix="taxnest-rc-mariadb-startup-") as temp:
        fixture = Fixture(temp)
        fixture.data.mkdir()
        (fixture.data / "mysql").mkdir()
        (fixture.data / "mysql" / "existing").write_text("existing")
        result = fixture.run()
        assert_success(result, "root existing datadir")
        rows = event_rows(fixture.events)
        assert_true("install" not in row_names(rows), "existing datadir was initialized")
        assert_user_and_defaults(rows, "server", "mysql")
        assert_root_ownership(fixture)
        assert_all_ownership_precedes_start(fixture)
        results.append("root existing datadir")


def nonroot_case():
    with tempfile.TemporaryDirectory(prefix="taxnest-rc-mariadb-startup-") as temp:
        fixture = Fixture(temp)
        result = fixture.run(MOCK_CURRENT_UID="1000", RC_MARIADB_USER="root")
        assert_success(result, "nonroot startup")
        rows = event_rows(fixture.events)
        assert_true(
            not [row for row in rows if row[0] == "chown"],
            f"nonroot startup changed ownership: {rows}",
        )
        assert_true(
            "mysql" not in fixture.id_log.read_text(),
            "nonroot startup looked up the fixed mysql account",
        )
        for command in ("install", "server"):
            matching = [row[1:] for row in rows if row[0] == command]
            assert_true(matching, f"nonroot startup missing {command}")
            assert_true(
                not any(arg.startswith("--user=") for arg in matching[-1]),
                f"nonroot startup selected a user: {matching[-1]}",
            )
        results.append("nonroot unchanged")


def symlink_case():
    with tempfile.TemporaryDirectory(prefix="taxnest-rc-mariadb-startup-") as temp:
        fixture = Fixture(temp)
        fixture.data.mkdir()
        outside = Path(temp) / "escape-target"
        outside.mkdir()
        (fixture.data / "nested").mkdir()
        (fixture.data / "nested" / "escape").symlink_to(outside, target_is_directory=True)
        fixture.config.write_text("sentinel config\n")
        result = fixture.run()
        assert_failure(result, "symlinked lab subtree")
        assert_true(
            fixture.config.read_text() == "sentinel config\n",
            "unsafe startup overwrote config before rejecting symlink",
        )
        assert_true(
            not [row for row in event_rows(fixture.events) if row[0] == "chown"],
            "unsafe startup chowned before rejecting symlink",
        )
        assert_no_start(fixture, "symlinked lab subtree")
        assert_true(
            not list(outside.iterdir()),
            "unsafe startup touched the symlink target",
        )
        results.append("symlink escape rejected before mutation")


def chown_failure_case():
    with tempfile.TemporaryDirectory(prefix="taxnest-rc-mariadb-startup-") as temp:
        fixture = Fixture(temp)
        result = fixture.run(MOCK_CHOWN_FAIL="1")
        assert_failure(result, "chown failure")
        assert_no_start(fixture, "chown failure")
        results.append("chown failure stops startup")


def invalid_account_case(label, **overrides):
    with tempfile.TemporaryDirectory(prefix="taxnest-rc-mariadb-startup-") as temp:
        fixture = Fixture(temp)
        result = fixture.run(**overrides)
        assert_failure(result, label)
        assert_no_start(fixture, label)
        assert_true(
            not [row for row in event_rows(fixture.events) if row[0] == "chown"],
            f"{label} attempted ownership changes",
        )
        results.append(label)


def static_guard_case():
    source = LAB.read_text()
    for marker in (
        "guarded() {",
        'LD_PRELOAD="$RC_MARIADB_LD_PRELOAD" "$@"',
        'guarded "$INSTALL_DB"',
        'guarded "$SERVER"',
    ):
        assert_true(marker in source, f"startup changed guarded execution: {marker}")
    results.append("LD_PRELOAD guard preserved")


static_guard_case()
root_fresh_case()
root_existing_case()
nonroot_case()
symlink_case()
chown_failure_case()
invalid_account_case("missing mysql uid", MOCK_ID_FAIL_MYSQL_UID="1")
invalid_account_case("missing mysql gid", MOCK_ID_FAIL_MYSQL_GID="1")
invalid_account_case("mysql uid zero", MOCK_MYSQL_UID_OUTPUT="0")
invalid_account_case("mysql gid zero", MOCK_MYSQL_GID_OUTPUT="0")
invalid_account_case("mysql uid nonnumeric", MOCK_MYSQL_UID_OUTPUT="mysql")
invalid_account_case("mysql gid nonnumeric", MOCK_MYSQL_GID_OUTPUT="mysql")

checks = len(results)
print(f"PASS: {checks} focused MariaDB startup regression cases.")
print("RESULTS: " + "; ".join(results))
