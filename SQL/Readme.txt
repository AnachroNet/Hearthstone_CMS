Hearthstone CMS SQL layout
==========================

Hearthstone_DATABASE/
  FULL_UPDATE/           — import hearthstone_site_install_demo.sql for a new site (complete schema + demo data)
  generated/             — output from tools (e.g. achievement catalog import); gitignored except .gitkeep
  adopt_a_bot_claims.sql — reference CREATE for adopt_a_bot_claims (updates ship in Hearthstone_UPDATE/)

Hearthstone_UPDATE/
  YYYYMMDDNN_short-name.sql — ordered patches for existing site DBs; sets site_settings.site.release
  README.md              — release numbering and apply instructions

acore_world_DATABASE/
  token_gift_quest_addon_fix.sql  — optional world patch for token gift letters (see README.md Database section)

Shop accounts: no acore_auth trigger SQL is required. See DATABASE_PRIVILEGES.md.

Code release id: RELEASE at the project root (see includes/release.php).
