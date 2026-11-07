Hearthstone CMS SQL layout
==========================

Each game/CMS database has its own folder under SQL/, with BASE_INSTALL and UPDATES as needed.

Hearthstone_DATABASE/          (hearthstone_site)
  BASE_INSTALL/
    2026110601-hearthstone_site_install_v1.0.sql  — v1.0 full schema + demo seed
  UPDATES/
    2026110701-adopt-a-bot.sql  — release patches (see README.md)
  generated/                   — maintainer tool output (see generated/README.md); gitignored *.sql

acore_world_DATABASE/
  BASE_INSTALL/
    2026110601-token_gift_quest_addon_fix.sql
  UPDATES/                     — future world release SQL

Code version / release: VERSION and RELEASE at project root (includes/release.php).
Database: site_settings.site.version and site_settings.site.release.
Apply pending UPDATES: python tools/apply_site_db_updates.py --apply (see UPDATES/README.md).

See DATABASE_PRIVILEGES.md for MySQL grants.
