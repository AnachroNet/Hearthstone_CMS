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
SQL/Hearthstone_DATABASE/FULL_UPDATE/hearthstone_site_install_demo.sql
```

That file targets the **`hearthstone_site`** database name (`CREATE DATABASE` / `USE` at the top). If you want a different database name, create your DB first, then import with the `CREATE DATABASE` / `USE` lines adjusted or removed as appropriate.

**Older deployments** that cannot re-import may still use one-off scripts preserved under **`SQL/Hearthstone_DATABASE/migrations_archive/`** (for example `site_drop_cross_db_foreign_keys.sql` when upgrading from dumps that used cross-database foreign keys).

### 3. World database (optional CMS scripts)

Some features ship SQL for **`acore_world`**, for example:

```text
SQL/acore_world_DATABASE/token_gift_quest_addon_fix.sql
```

Root-level files such as `SQL/acore_world_armory_spell.sql` are documented in the legacy README and apply to `acore_world` when you enable those features.

### 4. Privileges and cleanup

- **Privileges:** see **`SQL/DATABASE_PRIVILEGES.md`** for recommended MySQL grants per database.  
- **Legacy auth triggers:** if an old install added them, remove with `SQL/acore_auth_drop_sahtout_triggers.sql`.  
- **Cross-database foreign keys:** if you upgrade from an old dump that used them on the site DB, run **`SQL/Hearthstone_DATABASE/migrations_archive/site_drop_cross_db_foreign_keys.sql`**.

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
SQL/Hearthstone_DATABASE/FULL_UPDATE/        — current full site DB (use for new installs)
SQL/Hearthstone_DATABASE/migrations_archive/ — legacy one-off upgrades from older dumps
SQL/Hearthstone_DATABASE/generated/          — tooling output (e.g. achievement catalog import)
SQL/acore_world_DATABASE/                    — optional world SQL bundled with the CMS
```

Other `SQL/*.sql` files at the repo root apply to **auth**, **characters**, or **world** as indicated in comments or in **`README_sahtoutcms_legacy.md`**.

---

## Documentation

- **`SQL/DATABASE_PRIVILEGES.md`** — MySQL privileges by database  
- **`README_sahtoutcms_legacy.md`** — long-form SahtoutCMS V2 readme (feature list, extra detail, original links)

---

## License

See **`LICENSE`** in the repository root (inherits upstream project terms).
