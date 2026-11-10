# Hearthstone site database — UPDATES

One SQL file per **release** after the current version’s base install. Apply in ascending order by the 10-digit release prefix in the filename (`YYYYMMDD` + daily counter).

| File | Release |
|------|---------|
| `2026110701-adopt-a-bot.sql` | Adopt a Bot schema; upserts `site.version` (**1.0**) and `site.release` (**2026110701**) in `site_settings` |
| `2026111001-map-dbc.sql` | `map_dbc` reference table (135 maps from Map.dbc); upserts `site.version` **1.1** and `site.release` **2026111001** |

There is no separate “version tracking only” patch: release SQL should always update `site.version` and `site.release` when you ship. Fresh v1.0 installs get **2026110601** from `BASE_INSTALL` instead.

If `site_settings` exists but those keys are missing (older live sites), the CMS and `tools/apply_site_db_updates.py` assume **Version 1.0 — Release 2026110601** when deciding which UPDATES still need to run.

New installs: import **`../BASE_INSTALL/2026110601-hearthstone_site_install_v1.0.sql`**, then run any UPDATE whose release id is **greater** than the `site.release` value in that dump.

Codebase tracking: root **`VERSION`** and **`RELEASE`** files; PHP helpers in **`includes/site_release.php`**.

### Apply from the server (recommended)

From the project root (with `includes/config.local.php` configured and the `mysql` client on PATH):

```bash
python3 tools/apply_site_db_updates.py --list
python3 tools/apply_site_db_updates.py --apply
```

On Windows, use `python` instead of `python3` if needed. The script reads site DB credentials from `config.local.php`, shows pending files, and runs them in order. Use `--yes` to skip the confirmation prompt.

When you ship a new **version** (e.g. 1.1), add a new file under **`BASE_INSTALL/`** that folds in all intervening updates, and continue numbering releases in this folder until the next version bump.
