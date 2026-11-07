# Generated SQL (do not delete this folder)

This directory is **intentionally empty in git** except for this note and `.gitkeep`.

Maintainer tools write large, reproducible SQL here so it does not bloat the repository:

- **`tools/import_achievement_catalog.php`** → `achievement_catalog_import.sql` (see `data/dbc/README.txt`)

Files matching `*.sql` in this folder are **gitignored**. After regenerating, apply the output to your site database as documented for that tool.

This is **not** where version base installs or release updates live — use:

- `../BASE_INSTALL/` — full schema for a major version  
- `../UPDATES/` — per-release patches (`site.version` / `site.release`)
