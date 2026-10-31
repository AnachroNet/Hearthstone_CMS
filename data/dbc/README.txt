Optional WotLK client DBC files (3.3.5a), copied from your worldserver `data/dbc/` folder
or from the client Data/enUS (or enGB) directory.

Talent tree layout (character pages):
  - Talent.dbc
  - TalentTab.dbc
The site builds a cache at cache/wotlk_talent_map.php on first use.

Achievement catalog import (site DB reference table):
  - Achievement.dbc
  - Achievement_Criteria.dbc
  - Achievement_Category.dbc
  - Map.dbc (recommended — dungeon presets use 5-player instances vs raids)
  - LFGDungeons.dbc (recommended — dungeon level on reward presets and admin UI)
Run from Web/SahtoutCMS (uses includes/config.local.php world DB when present to map boss kills to instances):
  php tools/import_achievement_catalog.php
Then apply SQL/Sahtout_site DATABASE/SINGLE UPDATE/achievement_catalog.sql (once)
and achievement_catalog_import.sql (after each DBC refresh).

The import also seeds achievement_token_rewards with presets: every 10 levels,
every 50 quests, and per-dungeon completions (tokens 0, disabled until you configure).

You can also set TALENT_DBC_DIR in includes/config.local.php to another folder path.
