# Hearthstone CMS

Website CMS for **AzerothCore Wrath of the Lich King (3.3.5)** private servers. This project is a community fork of [SahtoutCMS](https://github.com/blodyiheb/SahtoutCMS); internal PHP identifiers may still use `sahtout_*` for smoother upstream merges.

**Repository:** [github.com/AnachroNet/Hearthstone_CMS](https://github.com/AnachroNet/Hearthstone_CMS)

---

## Requirements

### Server stack

| Component | Notes |
|-----------|--------|
| **Apache** | **mod_rewrite** and **mod_headers** enabled; **`AllowOverride All`** (or at least `FileInfo`) on the document root so `.htaccess` is applied |
| **PHP** | **8.2 or newer** |
| **MySQL or MariaDB** | For the site database and (typically the same server) AzerothCore `acore_auth`, `acore_characters`, and `acore_world` |
| **HTTPS** | Recommended in production |

The web installer (**Step 2: Environment Check**) validates PHP version, extensions, and Apache modules when `apache_get_modules()` is available (typical **mod_php**). Behind **PHP-FPM**, module checks may show as N/A; verify Apache config manually.

### PHP extensions

**Required:** `mysqli`, `curl`, `openssl`, `soap`, `gd`, `gmp`, `mbstring`, `xml`, `fileinfo`

**Optional (installer checks, not blocking):** `intl`, `zip`, `json`

Short roles: **gmp** (SRP6 auth), **soap** (GM commands / shop delivery), **mysqli** (all DB access), **fileinfo** + **gd** (admin shop uploads), **openssl** + **curl** (HTTPS, SMTP, reCAPTCHA).

Verify:

```bash
php -m
```

Set **`upload_max_filesize`** and **`post_max_filesize`** to at least **8M** in `php.ini` if you use admin image uploads.

### Build tools (repository root)

| Tool | Purpose |
|------|--------|
| **Composer** | PHPMailer and PHP dependencies — run `composer install` before using email or installer mail steps |
| **Node.js + npm** | Only if you change Tailwind sources — `npm install` and `npm run build`. A prebuilt `assets/css/tailwind.css` is included |

### Game server

- **AzerothCore** with **WotLK 3.3.5** databases imported and working  
- **SOAP** enabled on the worldserver (and configured in the installer) for GM commands and features that rely on it  
- Optional **Playerbots** database: name is configurable in `includes/config.local.php` (`database.playerbots.name`)

### Linux Apache example

```bash
sudo a2enmod rewrite headers
sudo systemctl reload apache2
```

Ensure the vhost `<Directory>` for the site allows **`AllowOverride All`**.

---

## Database setup (before the web installer)

The CMS uses **four logical connections**: `acore_auth`, `acore_characters`, `acore_world`, and a **dedicated site database** (installer default name **`hearthstone_site`**).

### 1. AzerothCore databases

Import and run the standard AzerothCore **auth**, **characters**, and **world** SQL baselines on your server. The installer will test connectivity and required tables (e.g. `account`, `characters`, `item_template`).

### 2. Site database schema

Create an empty database for the site, then import the maintained full seed:

```text
SQL/Hearthstone_DATABASE/BASE_INSTALL/2026110601-hearthstone_site_install_v1.0.sql
```

That file targets the **`hearthstone_site`** database name (`CREATE DATABASE` / `USE` at the top). If you want a different database name, create your DB first, then import with the `CREATE DATABASE` / `USE` lines adjusted or removed as appropriate.

For **upgrades** after install, apply scripts under **`SQL/Hearthstone_DATABASE/UPDATES/`** in release order (see **`SQL/Hearthstone_DATABASE/UPDATES/README.md`**). The repo root **`VERSION`** and **`RELEASE`** files are the shipped CMS version (e.g. **1.0**) and release id; the database stores them in **`site_settings.site.version`** and **`site_settings.site.release`** (`YYYYMMDD` + daily counter, e.g. **Version 1.0 — Release 2026110701**).

### 3. AzerothCore optional SQL (only for specific features)

Core **auth / characters / world** still come from your **AzerothCore revision** (or repack). Hearthstone CMS may point you at an extra **world** script when a feature needs it:

| File | Database | Feature / note |
|------|----------|----------------|
| **`SQL/acore_world_DATABASE/BASE_INSTALL/2026110601-token_gift_quest_addon_fix.sql`** | `acore_world` | Token gift letters use in-game mail; this aligns quest / locale bits for that flow. Apply **before** publishing token gifts if your world DB does not already match. |

**In-game breaking news** (Admin → News → In-game, when enabled under Admin → General) requires a **`mod_breaking_news`** table on **`acore_world`**. Install the **Breaking News** module on your core—for example [**AnachroNet/mod-breaking-news-override**](https://github.com/AnachroNet/mod-breaking-news-override) (or another compatible implementation that provides that table). It is **not** part of the site base install SQL. You can turn the whole feature off under **Admin → General** (`site.breaking_news_enabled`) so the site does not read the world DB for it.

**Token gift letters** (sealed-letter shop promos, Admin → A-Shop → Rewards → Token Gifts) can be turned off entirely under **Admin → General** (`shop.token_gifts_enabled`). When disabled, that sub-tab is hidden for everyone (including administrators), login sync skips granting tokens for those promos, and the optional world SQL above is irrelevant. For recovery without the UI, you can set **`features.shop_token_gifts_enabled`** to `true` or `false` in **`includes/config.local.php`** (see `config.local.example.php`); the value overrides the database until removed or set back to `null`.

### 4. Privileges

- **Privileges:** see **`SQL/DATABASE_PRIVILEGES.md`** for recommended MySQL grants per database.

---

## New installation (overview)

1. **Clone** the repository and enter the project root:

   ```bash
   git clone https://github.com/AnachroNet/Hearthstone_CMS.git
   cd Hearthstone_CMS
   ```

2. **Composer** (required for mail/installer):

   ```bash
   composer install
   ```

3. **Tailwind** (only if you edit CSS sources):

   ```bash
   npm install
   npm run build
   ```

4. **Deploy** the project tree to your Apache document root (not double-nested). Examples: `C:\xampp\htdocs\...` on Windows, `/var/www/html/...` on Linux.

5. **Prepare databases** as in the previous section.

6. **Run the web installer** in a browser:

   ```text
   http://your-server/install/
   ```

   Steps cover environment check, database credentials (auth / world / characters / site), realm, mail, SOAP, and final config files under `includes/`.

7. **After success:** delete or protect the **`install/`** directory so it is not publicly reachable.

8. **First admin:** ensure your game account’s corresponding row in the site DB (`user_currencies` or as shown in the legacy guide) has **`role` = `admin`**, then sign in and finish settings in the admin panel.

---

## SQL layout in this repo

```text
SQL/Hearthstone_DATABASE/BASE_INSTALL/       — full site DB for a major version (v1.0 base at release 2026110601)
SQL/Hearthstone_DATABASE/UPDATES/            — per-release SQL for existing installs (site.version / site.release)
SQL/Hearthstone_DATABASE/generated/          — tooling output (e.g. achievement catalog import)
SQL/acore_world_DATABASE/BASE_INSTALL/       — optional world SQL bundled with the CMS
SQL/acore_world_DATABASE/UPDATES/            — future world release patches
```

**Legacy SahtoutCMS upgrade SQL** that used to live under `migrations_archive/` is **not** shipped in this branch—see **`README_sahtoutcms_legacy.md`** if you need historical upgrade paths.

---

## Maintainer tools (`tools/`)

These are optional tools for development and diagnostics (not part of normal installs):

| Script | Purpose |
|--------|---------|
| **`tools/import_achievement_catalog.php`** | Build achievement catalog SQL from WotLK DBC files under `data/dbc/`. See the header in that script for usage. |
| **`tools/build_hearthstone_site_install_demo.py`** | Rebuild `SQL/Hearthstone_DATABASE/BASE_INSTALL/2026110601-hearthstone_site_install_v1.0.sql` from a full `hearthstone_site` mysqldump (scrubs volatile tables and injects demo `site_settings` / news / calendar / downloads). Run: `python tools/build_hearthstone_site_install_demo.py [path/to/dump.sql] [path/to/output.sql]` |

---

## Documentation

- **`SQL/DATABASE_PRIVILEGES.md`** — MySQL privileges by database  
- **`README_sahtoutcms_legacy.md`** — long-form SahtoutCMS V2 readme (feature list, extra detail, original links)

---

## License

See **`LICENSE`** in the repository root (inherits upstream project terms).
