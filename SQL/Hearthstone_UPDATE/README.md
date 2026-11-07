# Hearthstone_UPDATE

Ordered SQL patches for **existing** `hearthstone_site` databases. Pair with the release id in the repo root **`RELEASE`** file and the applied copy in **`site_settings.site.release`**.

## Release id format

`YYYYMMDD` + two-digit daily counter, e.g. **`2026110701`** = first release on 2026-11-07.

- **`RELEASE`** (repo) — release shipped with this codebase (`includes/release.php` → `SAHTOUT_CODE_RELEASE`).
- **`site.release`** (DB) — last update script successfully applied on this server.

Auto-apply helpers live in **`includes/site_release.php`** (`sahtout_site_release_pending()`, `sahtout_site_release_apply_pending()`). Each `*.sql` file should end by setting `site.release` to its own id.

## File naming

```text
{releaseId}_{short-description}.sql
```

Example: `2026110701_adopt-a-bot.sql`

## Applying manually

1. Select your site database (`USE hearthstone_site;` or equivalent).
2. Compare `site.release` to the files in this folder; run any file whose numeric prefix is **greater** than the stored value, in ascending order.
3. If you never set `site.release` but deployed code from 2026-11-06, run `2026110601_release-baseline.sql` first (optional if you only need the adopt-a-bot patch and accept jumping straight to `2026110701`).

## New installs

Use **`SQL/Hearthstone_DATABASE/FULL_UPDATE/hearthstone_site_install_demo.sql`** for a full schema. After import, run pending **`Hearthstone_UPDATE`** scripts (or set `site.release` to match your deployed code).

Legacy one-off scripts under **`SQL/Hearthstone_DATABASE/adopt_a_bot_claims*.sql`** are deprecated; use **`2026110701_adopt-a-bot.sql`** instead.
