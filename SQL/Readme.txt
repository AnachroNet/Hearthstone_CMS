SahtoutCMS SQL layout
=====================

Hearthstone_DATABASE/
  FULL_UPDATE/     — full or demo sahtout_site imports
  SINGLE_UPDATE/   — incremental migrations on sahtout_site

acore_world_DATABASE/
  SINGLE_UPDATE/   — incremental migrations on acore_world

Root SQL files:
  acore_world_*.sql              — import into acore_world as documented per file
  acore_characters_shopitem_trigger.sql — optional shop delivery (characters DB)
  acore_auth_drop_sahtout_triggers.sql  — remove legacy auth triggers (optional)
  acore_auth_sahtout_site.sql           — DEPRECATED stub; do not install triggers

Shop accounts: no acore_auth SQL required. See DATABASE_PRIVILEGES.md.
