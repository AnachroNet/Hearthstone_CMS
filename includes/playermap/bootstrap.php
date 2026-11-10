<?php
if (defined('SAHTOUT_PLAYERMAP_BOOTSTRAPPED')) {
    return;
}

define('SAHTOUT_PLAYERMAP_BOOTSTRAPPED', true);

if (!defined('ALLOWED_ACCESS')) {
    define('ALLOWED_ACCESS', true);
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'paths.php';
require_once $project_root . 'includes/config.php';
require_once $project_root . 'includes/playermap.php';

global $site_db, $base_path;

if (!isset($site_db) || !($site_db instanceof mysqli) || $site_db->connect_error) {
    http_response_code(503);
    exit('Site database is not available.');
}

if (!sahtout_playermap_enabled($site_db)) {
    http_response_code(404);
    exit('Not found.');
}

sahtout_playermap_apply_tuning_defaults();

$legacy = sahtout_playermap_build_legacy_db_config();
$realm_db = $legacy['realm_db'];
$characters_db = $legacy['characters_db'];
$server = $legacy['server'];
$sahtout_playermap_default_realm_id = (int) $legacy['default_realm_id'];

global $sahtout_playermap_web_base, $sahtout_playermap_home_url, $sahtout_playermap_back_label;
$sahtout_playermap_web_base = rtrim((string) $base_path, '/') . '/playermap/';
require_once $project_root . 'languages/language.php';
$sahtout_playermap_back = sahtout_playermap_resolve_back_navigation((string) $base_path);
$sahtout_playermap_home_url = $sahtout_playermap_back['url'];
$sahtout_playermap_back_label = $sahtout_playermap_back['label'];
