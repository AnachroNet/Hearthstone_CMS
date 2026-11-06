Hearthstone CMS SQL layout
==========================

Hearthstone_DATABASE/
  FULL_UPDATE/           — import hearthstone_site_install_demo.sql for a new site (complete schema + demo data)
  generated/             — output from tools (e.g. achievement catalog import); gitignored except .gitkeep

acore_world_DATABASE/
  token_gift_quest_addon_fix.sql  — optional world patch for token gift letters (see README.md Database section)

Shop accounts: no acore_auth trigger SQL is required. See DATABASE_PRIVILEGES.md.
