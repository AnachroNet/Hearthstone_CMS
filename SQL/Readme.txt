Hearthstone CMS SQL layout
==========================

Hearthstone_DATABASE/
  FULL_UPDATE/           — import hearthstone_site_install_demo.sql for a new site (complete schema + demo data)
  migrations_archive/    — one-off upgrades for very old dumps (see README inside)
  generated/             — output from tools (e.g. achievement catalog import); gitignored except .gitkeep

acore_world_DATABASE/
  token_gift_quest_addon_fix.sql  — optional world patch for token gift letters
  migrations_archive/               — reserved for archived world patches

Root SQL files (repository root):
  acore_world_*.sql              — import into acore_world as documented per file
  acore_characters_shopitem_trigger.sql — optional shop delivery (characters DB)
  acore_auth_drop_sahtout_triggers.sql  — remove legacy auth triggers (optional)
  acore_auth_sahtout_site.sql           — DEPRECATED stub; do not install triggers

Shop accounts: no acore_auth SQL required. See DATABASE_PRIVILEGES.md.
