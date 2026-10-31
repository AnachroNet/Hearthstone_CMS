Optional WotLK client DBC files for talent tree layout on character pages.

Place these files from a 3.3.5a client Data/enUS (or enGB) folder here:
  - Talent.dbc
  - TalentTab.dbc

The site builds a cache file at cache/wotlk_talent_map.php on first use.
Without these files, the Talents tab still lists learned talents by name,
but without the 51/10/0-style tree breakdown.

You can also set TALENT_DBC_DIR in includes/config.local.php to another folder path.
