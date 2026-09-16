#!/usr/bin/env python3
"""Exercise discovery only: synthetic executables, no server or database starts."""
from pathlib import Path
import shlex
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]
LAB = ROOT / "scripts/rc-mariadb-lab.sh"
BASH = shutil.which("bash")
TOOLS = ("mariadbd", "mariadb", "mariadb-admin", "mariadb-install-db")
OVERRIDES = ("RC_MARIADBD", "RC_MARIADB_CLIENT", "RC_MARIADB_ADMIN",
             "RC_MARIADB_INSTALL_DB")
checks = 0


def case(label, *, layout="split", versions=None, bad_exit=None, missing=None,
         override=None, extra=None, success=True, alias=False):
    global checks
    with tempfile.TemporaryDirectory(prefix="taxnest-rc-mariadb-discovery-") as temp:
        root = Path(temp)
        bindir, sbindir = root / "usr/bin", root / "usr/sbin"
        bindir.mkdir(parents=True)
        sbindir.mkdir(parents=True)
        for command in ("realpath", "dirname"):
            (bindir / command).symlink_to(shutil.which(command))
        called = root / "install-db-was-called"
        locations = {}
        for index, name in enumerate(TOOLS):
            parent = sbindir if index == 0 or layout == "siblings" else bindir
            executable = parent / name
            locations[name] = executable
            if name == "mariadb-install-db":
                body = f"printf called > {shlex.quote(str(called))}\nexit 99\n"
            else:
                output = (versions or {}).get(
                    name, f"{name} Ver 10.6.23-MariaDB for debian-linux-gnu")
                body = (
                    '[[ "${1:-}" == --version ]] || exit 98\n'
                    f"printf '%s\\n' {shlex.quote(output)}\n"
                    f"exit {1 if bad_exit == name else 0}\n"
                )
            executable.write_text(f"#!{BASH}\n" + body)
            executable.chmod(0o755)
        if layout == "symlink":
            for name, alias_name in zip(
                    TOOLS, ("mysqld", "mysql", "mysqladmin", "mysql_install_db")):
                target = locations[name].with_name(alias_name)
                locations[name].rename(target)
                locations[name].symlink_to(target.name)
        if alias:
            locations["mariadbd"].rename(sbindir / "mysqld")
        if missing:
            locations[missing].unlink()
        env = {
            "PATH": f"{sbindir}:{bindir}", "HOME": temp,
            "RC_MARIADB_ROOT": str(root / "state"), "RC_MARIADB_PORT": "33116",
        }
        if override == "all":
            env.update({key: str(locations[name])
                        for key, name in zip(OVERRIDES, TOOLS)})
        elif override:
            env[override] = str(root / "absent-explicit-override")
        env.update(extra or {})
        result = subprocess.run([BASH, str(LAB), "env"], env=env,
                                capture_output=True, text=True, timeout=5)
        assert (result.returncode == 0) == success, (
            label, result.returncode, result.stdout, result.stderr)
        assert not called.exists(), "Discovery invoked install-db"
        if success:
            assert f"RC_MARIADB_CLIENT={locations['mariadb']}\n" in result.stdout
            assert "RC_MARIADB_SERVER_VERSION=" in result.stdout
        checks += 1


for layout in ("split", "siblings", "symlink"):
    case(layout, layout=layout)
case("vendor-before-number", versions={
    name: f"{name} MariaDB Distrib 10.6.23" for name in TOOLS[:3]})
case("mysqld-server-alias", alias=True)
case("valid-explicit-overrides", override="all")
for tool in TOOLS[:3]:
    for version in ("MySQL 8.0.40", "MariaDB 10.5.23", "MariaDB 10.11.23",
                    "MariaDB 110.6.23", "MariaDB 10.60.23", "MariaDB 10.6"):
        case(f"reject-{tool}-{version}", versions={tool: version}, success=False)
    case(f"reject-{tool}-nonzero-version", bad_exit=tool, success=False)
for tool in TOOLS:
    case(f"require-{tool}", missing=tool, success=False)
for key in OVERRIDES:
    case(f"reject-invalid-{key}", override=key, success=False)
case("unsafe-root", extra={"RC_MARIADB_ROOT": "/var/lib/mysql"}, success=False)
case("unsafe-port", extra={"RC_MARIADB_PORT": "9000"}, success=False)
case("required-egress-guard", extra={"RC_MARIADB_REQUIRE_EGRESS_GUARD": "1"},
     success=False)
print(f"PASS: {checks} isolated MariaDB discovery/authenticity cases; "
      "no database, install-db, or network operation executed.")