#!/usr/bin/env python3
"""Build a distributable sahtout_site seed from a production mysqldump."""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_SRC = ROOT.parent / "sahtout_site.dump.sql"
DEFAULT_OUT = (
    ROOT
    / "SQL"
    / "Sahtout_site DATABASE"
    / "FULL UPDATE"
    / "sahtout_site_install_demo.sql"
)

EMPTY_DATA_TABLES = frozenset(
    {
        "achievement_token_grants",
        "character_stat_token_grants",
        "character_teleport_log",
        "purchases",
        "failed_logins",
        "password_resets",
        "pending_accounts",
        "reset_attempts",
        "maintenance_announcements",
        "token_gift_grants",
        "token_gift_loot_deployments",
        "token_gift_reward_sync_state",
        "token_gifts",
        "user_currencies",
        "user_inquiries",
        "website_activity_log",
    }
)

REPLACE_DATA_TABLES = frozenset(
    {
        "server_news",
        "site_calendar_events",
        "site_settings",
        "site_downloads",
    }
)

TABLE_COMMENT_RE = re.compile(r"^-- Table structure for table `([^`]+)`")
INSERT_RE = re.compile(r"^INSERT INTO `")
ACORE_FK_RE = re.compile(
    r",?\s*CONSTRAINT `[^`]+` FOREIGN KEY \([^)]+\) REFERENCES `acore_(?:auth|characters)`\.`[^`]+` \([^)]+\)"
    r"(?: ON DELETE (?:CASCADE|SET NULL|RESTRICT|NO ACTION))?"
    r"(?: ON UPDATE (?:CASCADE|SET NULL|RESTRICT|NO ACTION))?",
    re.IGNORECASE,
)


def strip_cross_db_foreign_keys(sql: str) -> str:
    return ACORE_FK_RE.sub("", sql)


def sql_escape(value: str) -> str:
    return value.replace("\\", "\\\\").replace("'", "\\'")


def site_settings_insert() -> str:
    social = {
        "facebook": "",
        "twitter": "",
        "tiktok": "",
        "youtube": "",
        "discord": "",
        "twitch": "",
        "kick": "",
        "instagram": "",
        "github": "",
        "linkedin": "",
    }
    social_json = json.dumps(social, separators=(",", ":"))
    rows = [
        ("site.title", "SahtoutCMS", "string"),
        ("site.logo_path", "img/logo.png", "string"),
        ("site.timezone", "", "string"),
        ("social.links", social_json, "json"),
        ("discord.guild_id", "", "string"),
        ("discord.widget_enabled", "1", "bool"),
        ("registration.allow_shared_email", "0", "bool"),
        ("registration.signup_tokens_enabled", "0", "bool"),
        ("registration.signup_tokens_amount", "0", "int"),
        (
            "shop.token_gift_loot_ids",
            "2276,2277,2278,2279,2280,2284",
            "string",
        ),
        (
            "shop.token_gift_world_ids",
            "300000,300001,300002,300003,300004,300005,300006,300007,300008,300009",
            "string",
        ),
        ("shop.token_gift_item_template_ref", "2223", "int"),
        ("shop.token_gift_quest_template_ref", "123", "int"),
        ("shop.token_gift_id_base", "300000", "int"),
        ("shop.token_gift_admin_access", "admin_only", "string"),
    ]
    parts = []
    for key, val, vtype in rows:
        esc = sql_escape(val)
        parts.append(
            f"('{sql_escape(key)}','{esc}','{vtype}','general',NULL,1,'2026-01-01 00:00:00',NULL)"
        )
    return "INSERT INTO `site_settings` VALUES " + ",".join(parts) + ";"


def server_news_insert() -> str:
    posts = [
        (
            1,
            "Welcome to your realm site",
            "welcome-to-your-realm-site",
            "This is sample news content. Edit or replace it under Admin → News. "
            "Use news posts for patch notes, events, and maintenance windows.",
            "Staff",
            "2026-01-01 12:00:00",
            "img/newsimg/news1.jpg",
            1,
            "update",
        ),
        (
            2,
            "Wrath of the Lich King (3.3.5a)",
            "wotlk-335-client",
            "Players need a World of Warcraft 3.3.5a client. Update the Downloads page "
            "with your realmlist and client links before going live.",
            "Staff",
            "2026-01-02 12:00:00",
            "img/newsimg/news2.jpg",
            0,
            "update",
        ),
        (
            3,
            "Realm Shop and milestone tokens",
            "realm-shop-milestone-tokens",
            "The sample shop catalog and achievement rewards are pre-configured. "
            "Achievement and character-stat rewards grant website tokens when players "
            "log in. Adjust prices and criteria in Admin → Shop → Rewards.",
            "Staff",
            "2026-01-03 12:00:00",
            "img/newsimg/news3.jpg",
            0,
            "other",
        ),
    ]
    vals = []
    for row in posts:
        (
            pid,
            title,
            slug,
            content,
            posted_by,
            post_date,
            image_url,
            is_important,
            category,
        ) = row
        vals.append(
            f"({pid},'{sql_escape(title)}','{sql_escape(slug)}',"
            f"'{sql_escape(content)}','{sql_escape(posted_by)}',"
            f"'{post_date}','{sql_escape(image_url)}',{is_important},'{category}')"
        )
    return "INSERT INTO `server_news` VALUES " + ",".join(vals) + ";"


def site_calendar_events_insert() -> str:
    events = [
        (
            1,
            "Grand opening weekend",
            "Sample calendar event. Replace dates and text in Admin → Events.",
            "2026-12-05",
            "2026-12-07",
            0,
            0,
        ),
        (
            2,
            "Weekly community meetup",
            "Every Wednesday evening — voice chat optional. "
            "Demonstrates a single-day event on the site calendar.",
            "2026-12-10",
            "2026-12-10",
            0,
            0,
        ),
        (
            3,
            "Saturday dungeon night",
            "Recurring Saturday event (bitmask 32 = Saturday). "
            "Adjust recurrence in Admin → Events.",
            "2026-12-06",
            "2027-03-28",
            1,
            32,
        ),
    ]
    vals = []
    for eid, title, desc, start, end, recur, days in events:
        vals.append(
            f"({eid},'{sql_escape(title)}','{sql_escape(desc)}',"
            f"'{start}','{end}',{recur},{days},1,"
            f"'2026-01-01 00:00:00','2026-01-01 00:00:00')"
        )
    return "INSERT INTO `site_calendar_events` VALUES " + ",".join(vals) + ";"


def site_downloads_insert() -> str:
    rows = [
        (
            1,
            "Example realmlist.wtf",
            "Replace this URL with your hosted realmlist file. "
            "Players copy it to Data/enUS/realmlist.wtf in their client.",
            "https://example.com/realmlist.wtf",
            "other",
            "<1 KB",
            0,
        ),
        (
            2,
            "Example 3.3.5a client link",
            "Point this at your preferred client download (torrent, mirror, or self-hosted).",
            "https://example.com/wow-335-client",
            "client",
            "varies",
            1,
        ),
        (
            3,
            "AddOn pack (optional)",
            "Optional starter AddOns for your realm. Upload to your CDN and update the link.",
            "https://example.com/addons.zip",
            "addon",
            "varies",
            2,
        ),
    ]
    vals = []
    for rid, title, desc, url, cat, size, sort_order in rows:
        vals.append(
            f"({rid},'{sql_escape(title)}','{sql_escape(desc)}',"
            f"'{sql_escape(url)}','{cat}','{sql_escape(size)}',{sort_order},1,"
            f"'2026-01-01 00:00:00','2026-01-01 00:00:00')"
        )
    return "INSERT INTO `site_downloads` VALUES " + ",".join(vals) + ";"


REPLACE_SQL = {
    "site_settings": site_settings_insert,
    "server_news": server_news_insert,
    "site_calendar_events": site_calendar_events_insert,
    "site_downloads": site_downloads_insert,
}


def should_skip_line(line: str) -> bool:
    if "rocksdb" in line.lower():
        return True
    if line.startswith("/*!50717") or line.startswith("/*!50112"):
        return True
    return False


def sanitize_line(line: str) -> str:
    line = line.replace("DEFINER=`root`@`localhost`", "DEFINER=CURRENT_USER")
    return line


def build(src: Path, out: Path) -> None:
    text = src.read_text(encoding="utf-8", errors="replace").splitlines(keepends=True)
    out_lines: list[str] = []

    header = """-- SahtoutCMS distributable site database (demo seed)
-- Generated from a production dump: scrubbed accounts, logs, token gifts, and site-specific settings.
-- Import after creating empty database `sahtout_site`, or uncomment CREATE DATABASE below.
-- No SQL required on acore_auth for shop accounts (PHP sync via includes/site_account.php).
-- Upgrading from older releases: run SINGLE UPDATE/site_drop_cross_db_foreign_keys.sql and
-- optional SQL/acore_auth_drop_sahtout_triggers.sql on auth.
--
-- Includes: shop catalog (shop_items + site_items), achievement/stat reward criteria,
-- sample news & calendar events, reference DBC tables, default site_settings.
--
"""
    out_lines.append(header)
    out_lines.append(
        "CREATE DATABASE IF NOT EXISTS `sahtout_site` "
        "DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
    )
    out_lines.append("USE `sahtout_site`;\n\n")

    current_table: str | None = None
    skip_insert = False

    for raw in text:
        if should_skip_line(raw):
            continue

        m = TABLE_COMMENT_RE.match(raw.rstrip("\n"))
        if m:
            current_table = m.group(1)
            skip_insert = False

        line = sanitize_line(raw)

        if INSERT_RE.match(line):
            table = current_table
            if table in EMPTY_DATA_TABLES:
                continue
            if table in REPLACE_DATA_TABLES:
                fn = REPLACE_SQL[table]
                out_lines.append(fn() + "\n")
                skip_insert = True
                continue
            skip_insert = False
            out_lines.append(line)
            continue

        if skip_insert and line.strip() and not line.startswith("/*!"):
            if "UNLOCK TABLES" in line or "ALTER TABLE" in line and "ENABLE KEYS" in line:
                skip_insert = False
            elif line.strip().startswith("--"):
                skip_insert = False
                out_lines.append(line)
            continue

        if raw.startswith("-- MySQL dump") or raw.startswith("-- Host:") or raw.startswith(
            "-- Server version"
        ):
            continue
        if raw.startswith("-- Dump completed on"):
            continue

        out_lines.append(line)

    out.parent.mkdir(parents=True, exist_ok=True)
    body = strip_cross_db_foreign_keys("".join(out_lines))
    out.write_text(body, encoding="utf-8", newline="\n")
    print(f"Wrote {out} ({out.stat().st_size} bytes)")


def main() -> int:
    src = Path(sys.argv[1]) if len(sys.argv) > 1 else DEFAULT_SRC
    out = Path(sys.argv[2]) if len(sys.argv) > 2 else DEFAULT_OUT
    if not src.is_file():
        print(f"Source dump not found: {src}", file=sys.stderr)
        return 1
    build(src, out)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
