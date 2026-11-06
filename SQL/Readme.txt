Hearthstone CMS SQL layout
==========================

Hearthstone_DATABASE/
  FULL_UPDATE/           — import hearthstone_site_install_demo.sql for a new site (complete schema + demo data)
                         — FULL_UPDATE/archive/  optional old full dumps (not needed for new installs; see README there)
  migrations_archive/    — one-off upgrades for very old site DBs (see README inside)
  generated/             — output from tools (e.g. achievement catalog import); gitignored except .gitkeep

acore_world_DATABASE/
  token_gift_quest_addon_fix.sql  — optional world patch for token gift letters
  migrations_archive/             — reserved for archived world patches

acore_database/
  migrations_archive/    — optional or legacy AzerothCore patches (armory, breaking news, old triggers, etc.)
                         — not used for a clean install; see acore_database/README.md

Shop accounts: no acore_auth trigger SQL is required. See DATABASE_PRIVILEGES.md.
