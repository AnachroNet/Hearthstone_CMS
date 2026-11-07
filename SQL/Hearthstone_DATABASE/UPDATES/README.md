# Hearthstone site database — UPDATES

One SQL file per **release** after the current version’s base install. Apply in ascending order by the 10-digit release prefix in the filename (`YYYYMMDD` + daily counter).

| File | Release |
|------|---------|
| `2026110701-adopt-a-bot.sql` | Adopt a Bot schema; upserts `site.version` (**1.0**) and `site.release` (**2026110701**) in `site_settings` |

There is no separate “version tracking only” patch: release SQL should always update `site.version` and `site.release` when you ship. Fresh v1.0 installs get **2026110601** from `BASE_INSTALL` instead.

New installs: import **`../BASE_INSTALL/2026110601-hearthstone_site_install_v1.0.sql`**, then run any UPDATE whose release id is **greater** than the `site.release` value in that dump.

Codebase tracking: root **`VERSION`** and **`RELEASE`** files; PHP helpers in **`includes/site_release.php`**.

When you ship a new **version** (e.g. 1.1), add a new file under **`BASE_INSTALL/`** that folds in all intervening updates, and continue numbering releases in this folder until the next version bump.
