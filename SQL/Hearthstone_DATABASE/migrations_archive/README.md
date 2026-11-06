# Archived site database migrations

These scripts were used to **upgrade older site-database installs** between releases. For a **new installation**, import the current full schema instead:

`SQL/Hearthstone_DATABASE/FULL_UPDATE/hearthstone_site_install_demo.sql`

Keep this folder for reference or for servers that still run legacy dumps and cannot re-import. The application expects the schema shipped in that full file.

Generated tooling output (for example `achievement_catalog_import.sql` from `tools/import_achievement_catalog.php`) is written to **`generated/`** beside this folder.
