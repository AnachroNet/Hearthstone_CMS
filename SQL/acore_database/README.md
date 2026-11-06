# Archived AzerothCore optional SQL

Scripts in **`migrations_archive/`** are **not** part of a clean Hearthstone CMS install. They exist for **legacy upgrades** or **optional features** (armory data, breaking-news module, deprecated shop delivery procedure, removing old auth triggers).

**New installs:** Import the standard AzerothCore **auth**, **characters**, and **world** baselines from your core revision, then use the site full seed:

`../Hearthstone_DATABASE/FULL_UPDATE/hearthstone_site_install_demo.sql`

Apply a file from `migrations_archive/` only when the feature docs or admin UI point you at it (for example breaking news, armory spell table, or cleaning up old triggers).

| File | Target DB | Notes |
|------|-----------|--------|
| `acore_world_armory_spell.sql` | `acore_world` | Large optional `armory_spell` table for armory-related features |
| `acore_world_itemdisplayinfo_dbc.sql` | `acore_world` | Optional ItemDisplayInfo data |
| `acore_world_mod_breaking_news.sql` | `acore_world` | Optional breaking news / mod table for admin |
| `acore_world_spellcharge.sql` | `acore_world` | Optional spell charge data |
| `acore_characters_shopitem_trigger.sql` | `acore_characters` | Legacy optional shop delivery via `SendStoreItem` (prefer current CMS delivery path) |
| `acore_auth_drop_sahtout_triggers.sql` | `acore_auth` | **Drop** deprecated triggers if an old install added them |
| `acore_auth_sahtout_site.sql` | — | **Deprecated stub**; do **not** install triggers (see `../DATABASE_PRIVILEGES.md`) |

World scripts that are still **actively documented** for some installs live under **`../acore_world_DATABASE/`** (for example `token_gift_quest_addon_fix.sql`), not in this archive.
