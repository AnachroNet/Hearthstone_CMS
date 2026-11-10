# Live player map (Hearthstone CMS)

Based on **[AzerothCore Playermap](https://github.com/azerothcore/playermap/tree/master)**. See the site **Credits** page for attribution details.

## Enable

1. Apply `SQL/Hearthstone_DATABASE/UPDATES/2026111002-playermap.sql` on **hearthstone_site** (or run `tools/apply_site_db_updates.py --apply`).
2. **Admin → General** → enable **Live player map** and choose faction visibility.
3. Open **`/playermap/`** (nav link appears when enabled).

Configuration uses **`includes/config.local.php`** (auth, characters, realm address/port). Optional tuning: the **`playermap`** key in that file. No `playermap_config.php` is required.

English map strings ship as `map_english.php` / `zone_names_english.php` in this directory.
