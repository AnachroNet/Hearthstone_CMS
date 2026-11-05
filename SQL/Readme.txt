SahtoutCMS SQL layout
=====================

Sahtout_site DATABASE/
  FULL UPDATE/     — full or demo sahtout_site imports
  SINGLE UPDATE/   — incremental migrations on sahtout_site

Root SQL files:
  acore_world_*.sql              — import into acore_world as documented per file
  acore_characters_shopitem_trigger.sql — optional shop delivery (characters DB)
  acore_auth_drop_sahtout_triggers.sql  — remove legacy auth triggers (optional)
  acore_auth_sahtout_site.sql           — DEPRECATED stub; do not install triggers

Shop accounts: no acore_auth SQL required. See DATABASE_PRIVILEGES.md.
