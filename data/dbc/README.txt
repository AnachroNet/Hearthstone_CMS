DBC files (WotLK 3.3.5a) for Hearthstone CMS / SahtoutCMS
========================================================

This directory normally holds DBCs copied from your worldserver `data/dbc/` tree or from the
client `Data/<locale>/` folder (for example enUS or enGB).

Included examples (optional)
----------------------------
The following binaries are checked in as **examples** so you can run importers and talent
layout without copying files first. They were produced from an **AzerothCore** worldserver
build in **late October 2026**. For a live realm, replace them with DBCs that match your
client and core revision.

  Achievement.dbc
  Achievement_Category.dbc
  Achievement_Criteria.dbc
  LFGDungeons.dbc
  Map.dbc
  Talent.dbc
  TalentTab.dbc

Talent tree layout (character pages)
------------------------------------
  - Talent.dbc
  - TalentTab.dbc

The site writes a cache at `cache/wotlk_talent_map.php` on first use.

Achievement catalog import (site DB reference table)
----------------------------------------------------
  - Achievement.dbc
  - Achievement_Criteria.dbc
  - Achievement_Category.dbc
  - Map.dbc (recommended — dungeon presets use 5-player instances vs raids)
  - LFGDungeons.dbc (recommended — dungeon level on reward presets and admin UI)

From the CMS web root (`Web/SahtoutCMS`; uses `includes/config.local.php` and the world DB when
present to map boss kills to instances):

  php tools/import_achievement_catalog.php

Then apply `SQL/Hearthstone_DATABASE/migrations_archive/achievement_catalog.sql` once if the
table is missing (new installs use `SQL/Hearthstone_DATABASE/FULL_UPDATE/hearthstone_site_install_demo.sql`).
For a full refresh from DBC, run `tools/import_achievement_catalog.php` again — output goes to
`SQL/Hearthstone_DATABASE/generated/achievement_catalog_import.sql`; apply that file after each DBC refresh.

The import also seeds `achievement_token_rewards` with presets (every 10 levels, every 50 quests,
per-dungeon completions); tokens start at 0 until you configure rewards.

Override path
-------------
You can set `TALENT_DBC_DIR` in `includes/config.local.php` to point at another folder if DBCs
live outside this directory.
