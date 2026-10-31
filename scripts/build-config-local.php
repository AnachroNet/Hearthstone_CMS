<?php
/**
 * One-time helper: build includes/config.local.php from legacy config files.
 * Run from project root: php scripts/build-config-local.php
 * Optional: php scripts/build-config-local.php /path/to/old/config.php
 */
define('ALLOWED_ACCESS', true);
require_once __DIR__ . '/../includes/config_local_io.php';

if (is_file(sahtout_local_config_path())) {
    fwrite(STDERR, "config.local.php already exists. Remove it first if you want to rebuild.\n");
    exit(1);
}

$legacyMain = $argv[1] ?? null;
$config = sahtout_migrate_legacy_config_files($legacyMain);
if ($config === null) {
    fwrite(STDERR, "No legacy config with database credentials found.\n");
    fwrite(STDERR, "Copy includes/config.local.example.php to includes/config.local.php, or pass a backup:\n");
    fwrite(STDERR, "  php scripts/build-config-local.php /path/to/old/config.php\n");
    fwrite(STDERR, "You can also save a pre-refactor config as includes/config.legacy.php and re-run.\n");
    exit(1);
}

if (!sahtout_save_local_config($config)) {
    fwrite(STDERR, "Failed to write includes/config.local.php\n");
    exit(1);
}

echo "Wrote includes/config.local.php\n";
