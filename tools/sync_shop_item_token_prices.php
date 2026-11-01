#!/usr/bin/env php
<?php
/**
 * Set shop_items.token_cost from vendor sell price for real item listings (1 token = 1 gold).
 *
 * Usage (from Web/SahtoutCMS):
 *   php tools/sync_shop_item_token_prices.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the command line: php tools/sync_shop_item_token_prices.php\n");
    exit(1);
}

define('ALLOWED_ACCESS', true);
$projectRoot = dirname(__DIR__);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'shop_token_pricing.php';

/** @var mysqli $site_db */
/** @var mysqli $world_db */
global $site_db, $world_db;

if (!isset($site_db) || $site_db->connect_error) {
    fwrite(STDERR, "Site database unavailable.\n");
    exit(1);
}

$world = (isset($world_db) && $world_db instanceof mysqli && !$world_db->connect_error) ? $world_db : null;
$stats = sahtout_sync_shop_item_token_prices($site_db, $world);

fwrite(STDOUT, sprintf(
    "Shop token sync: %d updated, %d unchanged, %d skipped (non-items or no vendor price).\n",
    $stats['updated'],
    $stats['unchanged'],
    $stats['skipped']
));

exit(0);
