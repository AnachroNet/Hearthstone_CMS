# Live player map (Hearthstone CMS)

Based on **[AzerothCore Playermap](https://github.com/azerothcore/playermap/tree/master)**. See the site **Credits** page for attribution details.

## Enable

1. Apply pending site SQL (at least `2026111002-playermap.sql` and `2026111003-area-dbc.sql`) on **hearthstone_site** (or run `tools/apply_site_db_updates.py --apply`).
2. **Admin → General** → enable **Live player map** and choose faction visibility.
3. Open **`/playermap/`** (nav link appears when enabled).

Configuration uses **`includes/config.local.php`** (auth, characters, realm address/port). Optional tuning: the **`playermap`** key in that file. No `playermap_config.php` is required.

UI strings: **`languages/en/playermap.php`**. Zone names: **`area_dbc`** on the site database (fallback: `languages/en/playermap_zones.php` if the table is missing). Regenerate from **AreaTable.dbc** with `tools/import_area_dbc.php`.
