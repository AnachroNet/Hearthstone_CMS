# Database privileges (Hearthstone CMS)

Hearthstone CMS keeps **all CMS-owned tables in `hearthstone_site`**. It does **not** require triggers, views, or schema changes on AzerothCore databases for a normal install.

Use one MySQL user for everything on a small server, or split users as below for least privilege.

## `hearthstone_site` (required)

| Privilege | Purpose |
|-----------|---------|
| **ALL** on `hearthstone_site.*` | Shop, news, settings, `user_currencies`, logs, rewards, etc. |

## `acore_auth` (website account & admin)

No **TRIGGER** privilege is needed.

| Tables | Typical privileges | Used for |
|--------|-------------------|----------|
| `account` | SELECT, INSERT, UPDATE | Login, registration, profile email/password, last_login |
| `account_access` | SELECT, INSERT, UPDATE, DELETE | GM level (header/session), admin user panel |
| `account_banned` | SELECT, INSERT, UPDATE | Login ban check, admin bans |
| `uptime` | SELECT | Realm status (optional) |

Some very old forks used **`after_account_insert` / `after_account_update` triggers** on auth. That approach is **deprecated**; the CMS syncs accounts in PHP (`includes/site_account.php`). If those triggers still exist on your server from a legacy install, drop them manually in MySQL—this repo no longer ships a helper SQL file for that.

## `acore_characters` (game data & shop delivery)

| Tables | Typical privileges | Used for |
|--------|-------------------|----------|
| `characters` | SELECT, UPDATE | Armory, shop deliveries, reward sync, admin |
| `item_instance`, mail, etc. | SELECT, INSERT, UPDATE | Shop item delivery (as implemented by your CMS version) |

Exact table list depends on enabled features (SOAP vs direct SQL shop, stat rewards, token gifts).

## `acore_playerbots` (optional)

Configured as `database.playerbots.name` in `includes/config.local.php` (default `acore_playerbots`; empty name disables integration). Queries run on the **characters** connection; no separate mysqli link.

| Privilege | Purpose |
|-----------|---------|
| **SELECT** on `playerbots_account_type` (typical) | Realm bot/human counts, armory “humans only” filters |

RNDBOT username filtering in admin does **not** require this database.

## `acore_world` (read-heavy)

| Privilege | Purpose |
|-----------|---------|
| **SELECT** on world tables | Item/quest lookups, token gift publish, DBC-backed admin tools |
| **INSERT/UPDATE/DELETE** | Only if you use CMS features that write world data (e.g. token gift publish, breaking news module) |

## Cross-database links

`user_currencies.account_id` matches `acore_auth.account.id`. Rows are created in PHP (`includes/site_account.php`) on register, activate, and login—not via auth triggers or foreign keys.

Avoid defining **foreign keys from `hearthstone_site` to `acore_*`**; they complicate backups and are not required.
