#!/usr/bin/env python3
"""Apply pending Hearthstone CMS site-database release SQL (cross-platform admin tool)."""

from __future__ import annotations

import argparse
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_CONFIG = ROOT / "includes" / "config.local.php"
UPDATES_DIR = ROOT / "SQL" / "Hearthstone_DATABASE" / "UPDATES"
RELEASE_FILE = ROOT / "RELEASE"
VERSION_FILE = ROOT / "VERSION"
RELEASE_RE = re.compile(r"^(\d{10})[-_].+\.sql$", re.IGNORECASE)
BASELINE_VERSION = "1.0"
BASELINE_RELEASE = 2026110601


def read_code_version() -> str:
    if VERSION_FILE.is_file():
        v = VERSION_FILE.read_text(encoding="utf-8").strip()
        if v:
            return v
    return "?"


def read_code_release() -> int:
    if not RELEASE_FILE.is_file():
        return 0
    digits = re.sub(r"\D", "", RELEASE_FILE.read_text(encoding="utf-8"))
    return int(digits) if digits else 0


def load_site_database_config(config_path: Path) -> dict[str, str | int]:
    if not config_path.is_file():
        raise FileNotFoundError(f"Config not found: {config_path}")

    php = shutil.which("php")
    if php:
        snippet = (
            "define('ALLOWED_ACCESS', true);"
            f"$c = require {json.dumps(str(config_path.resolve()))};"
            "echo json_encode($c['database']['site'] ?? new stdClass());"
        )
        proc = subprocess.run(
            [php, "-r", snippet],
            capture_output=True,
            text=True,
            cwd=str(ROOT),
        )
        if proc.returncode == 0 and proc.stdout.strip():
            data = json.loads(proc.stdout)
            if isinstance(data, dict) and data:
                return normalize_db_cfg(data)

    raw = config_path.read_text(encoding="utf-8", errors="replace")
    block = re.search(
        r"'site'\s*=>\s*\[(.*?)\],\s*(?:'playerbots'|\])",
        raw,
        re.DOTALL,
    )
    if not block:
        raise ValueError("Could not find database.site in config — install config.local.php or use PHP CLI.")

    inner = block.group(1)
    out: dict[str, str | int] = {}
    for key in ("host", "user", "pass", "name"):
        m = re.search(rf"'{key}'\s*=>\s*'((?:\\\\'|[^'])*)'", inner)
        if m:
            out[key] = m.group(1).replace("\\'", "'")
    port_m = re.search(r"'port'\s*=>\s*(\d+)", inner)
    out["port"] = int(port_m.group(1)) if port_m else 3306
    if not out.get("name"):
        out["name"] = "hearthstone_site"
    return normalize_db_cfg(out)


def normalize_db_cfg(data: dict) -> dict[str, str | int]:
    return {
        "host": str(data.get("host") or "localhost"),
        "port": int(data.get("port") or 3306),
        "user": str(data.get("user") or ""),
        "pass": str(data.get("pass") or ""),
        "name": str(data.get("name") or "hearthstone_site"),
    }


def resolve_mysql_bin(explicit: str | None) -> str:
    if explicit:
        p = Path(explicit)
        if not p.is_file():
            raise FileNotFoundError(f"mysql client not found: {explicit}")
        return str(p.resolve())
    found = shutil.which("mysql") or shutil.which("mysql.exe")
    if not found:
        raise FileNotFoundError(
            "mysql client not on PATH. Install MySQL/MariaDB client tools or pass --mysql /path/to/mysql"
        )
    return found


def write_defaults_file(cfg: dict[str, str | int]) -> str:
    fd, path = tempfile.mkstemp(prefix="hearthstone_mysql_", suffix=".cnf", text=True)
    os.close(fd)
    content = (
        "[client]\n"
        f"host={cfg['host']}\n"
        f"port={cfg['port']}\n"
        f"user={cfg['user']}\n"
        f"password={cfg['pass']}\n"
    )
    Path(path).write_text(content, encoding="utf-8")
    try:
        os.chmod(path, 0o600)
    except OSError:
        pass
    return path


def mysql_run(
    mysql_bin: str,
    defaults_path: str,
    database: str,
    *,
    sql_file: Path | None = None,
    query: str | None = None,
) -> subprocess.CompletedProcess[str]:
    cmd = [mysql_bin, f"--defaults-extra-file={defaults_path}", database]
    if query is not None:
        cmd.extend(["-N", "-B", "-e", query])
        return subprocess.run(cmd, capture_output=True, text=True)
    if sql_file is None:
        raise ValueError("sql_file or query required")
    with sql_file.open("rb") as handle:
        return subprocess.run(cmd, stdin=handle, capture_output=True, text=True)


def site_settings_table_exists(
    mysql_bin: str, defaults_path: str, database: str
) -> bool:
    proc = mysql_run(
        mysql_bin,
        defaults_path,
        database,
        query="SHOW TABLES LIKE 'site_settings'",
    )
    if proc.returncode != 0:
        err = (proc.stderr or proc.stdout or "").strip()
        raise RuntimeError(err or f"mysql exited {proc.returncode}")
    return bool((proc.stdout or "").strip())


def site_setting(
    mysql_bin: str, defaults_path: str, database: str, key: str
) -> str | None:
    q = (
        "SELECT setting_value FROM site_settings "
        f"WHERE setting_key = '{key.replace(chr(39), chr(39) + chr(39))}' LIMIT 1"
    )
    proc = mysql_run(mysql_bin, defaults_path, database, query=q)
    if proc.returncode != 0:
        err = (proc.stderr or proc.stdout or "").strip()
        if "site_settings" in err and "doesn't exist" in err.lower():
            return None
        raise RuntimeError(err or f"mysql exited {proc.returncode}")
    line = (proc.stdout or "").strip()
    return line if line else None


def list_update_sql() -> list[tuple[int, Path]]:
    if not UPDATES_DIR.is_dir():
        return []
    items: list[tuple[int, Path]] = []
    for path in sorted(UPDATES_DIR.glob("*.sql")):
        m = RELEASE_RE.match(path.name)
        if m:
            items.append((int(m.group(1)), path))
    items.sort(key=lambda x: x[0])
    return items


def pending_updates(applied_release: int) -> list[tuple[int, Path]]:
    return [(rid, p) for rid, p in list_update_sql() if rid > applied_release]


def parse_release_value(raw: str | None) -> int:
    if not raw:
        return 0
    digits = re.sub(r"\D", "", raw)
    return int(digits) if digits else 0


def effective_db_version(
    raw: str | None, *, table_exists: bool
) -> tuple[str | None, bool]:
    if not table_exists:
        return None, False
    if raw:
        return raw, False
    return BASELINE_VERSION, True


def effective_db_release(
    raw: str | None, *, table_exists: bool
) -> tuple[int, bool]:
    if not table_exists:
        return 0, False
    parsed = parse_release_value(raw)
    if parsed > 0:
        return parsed, False
    return BASELINE_RELEASE, True


def print_status(
    db_version: str | None,
    db_release: int,
    pending: list[tuple[int, Path]],
    *,
    assumed_baseline: bool,
) -> None:
    code_v = read_code_version()
    code_r = read_code_release()
    print(f"Codebase:  Version {code_v} — Release {code_r}")
    if db_version is None and db_release == 0:
        print("Database:  (site_settings table missing)")
    else:
        dv = db_version if db_version else "—"
        print(f"Database:  Version {dv} — Release {db_release or '—'}")
        if assumed_baseline:
            print(
                f"           (assumed v{BASELINE_VERSION} baseline release {BASELINE_RELEASE} "
                "— site.version or site.release not set)"
            )
    if not pending:
        print("Pending:   none")
    else:
        print("Pending:")
        for rid, path in pending:
            print(f"  - {rid}: {path.name}")


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Apply pending SQL/Hearthstone_DATABASE/UPDATES release scripts to hearthstone_site.",
    )
    parser.add_argument(
        "--config",
        type=Path,
        default=DEFAULT_CONFIG,
        help=f"Path to config.local.php (default: {DEFAULT_CONFIG.relative_to(ROOT)})",
    )
    parser.add_argument(
        "--mysql",
        dest="mysql_bin",
        help="Path to mysql client binary (default: search PATH)",
    )
    parser.add_argument(
        "--list",
        action="store_true",
        help="Show codebase vs database version/release and pending files (default action)",
    )
    parser.add_argument(
        "--apply",
        action="store_true",
        help="Apply all pending update SQL files in order",
    )
    parser.add_argument(
        "--yes",
        "-y",
        action="store_true",
        help="Skip confirmation prompt when applying",
    )
    args = parser.parse_args()

    if not args.apply:
        args.list = True

    try:
        cfg = load_site_database_config(args.config)
        mysql_bin = resolve_mysql_bin(args.mysql_bin)
    except (FileNotFoundError, ValueError, json.JSONDecodeError) as exc:
        print(f"Error: {exc}", file=sys.stderr)
        return 1

    if not cfg.get("user"):
        print("Error: database.site.user is empty in config.", file=sys.stderr)
        return 1

    defaults_path = write_defaults_file(cfg)
    database = str(cfg["name"])
    try:
        try:
            table_exists = site_settings_table_exists(
                mysql_bin, defaults_path, database
            )
            raw_version = (
                site_setting(mysql_bin, defaults_path, database, "site.version")
                if table_exists
                else None
            )
            raw_release = (
                site_setting(mysql_bin, defaults_path, database, "site.release")
                if table_exists
                else None
            )
            db_version, assumed_v = effective_db_version(
                raw_version, table_exists=table_exists
            )
            db_release, assumed_r = effective_db_release(
                raw_release, table_exists=table_exists
            )
            assumed_baseline = assumed_v or assumed_r
        except RuntimeError as exc:
            print(f"Error: {exc}", file=sys.stderr)
            return 1

        pending = pending_updates(db_release)
        print_status(
            db_version, db_release, pending, assumed_baseline=assumed_baseline
        )

        if not args.apply:
            return 0

        if not pending:
            print("\nNothing to apply.")
            return 0

        if not args.yes:
            answer = input("\nApply the pending script(s) above? [y/N] ").strip().lower()
            if answer not in ("y", "yes"):
                print("Cancelled.")
                return 0

        for rid, path in pending:
            print(f"\nApplying {path.name} ...")
            proc = mysql_run(mysql_bin, defaults_path, database, sql_file=path)
            if proc.returncode != 0:
                err = (proc.stderr or proc.stdout or "").strip()
                print(f"Failed: {err}", file=sys.stderr)
                return 1
            new_release = parse_release_value(
                site_setting(mysql_bin, defaults_path, database, "site.release")
            )
            new_version = site_setting(mysql_bin, defaults_path, database, "site.version")
            if new_release < rid:
                print(
                    f"Warning: script finished but site.release is {new_release}, expected at least {rid}.",
                    file=sys.stderr,
                )
                return 1
            print(
                f"OK — database now Version {new_version or '?'} — Release {new_release}"
            )

        print("\nAll pending updates applied.")
        return 0
    finally:
        try:
            os.remove(defaults_path)
        except OSError:
            pass


if __name__ == "__main__":
    sys.exit(main())
