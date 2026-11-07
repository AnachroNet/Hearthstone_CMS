<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

if (!defined('SAHTOUT_CODE_VERSION')) {
    $versionFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'VERSION';
    $rawVersion = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '0';
    define('SAHTOUT_CODE_VERSION', $rawVersion !== '' ? $rawVersion : '0');
}

if (!defined('SAHTOUT_CODE_RELEASE')) {
    $releaseFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'RELEASE';
    $raw = is_file($releaseFile) ? trim((string) file_get_contents($releaseFile)) : '0';
    define('SAHTOUT_CODE_RELEASE', (int) preg_replace('/\D/', '', $raw));
}

/** Relative path from project root to the current major-version base install SQL. */
const SAHTOUT_SITE_BASE_INSTALL_SQL = 'SQL/Hearthstone_DATABASE/BASE_INSTALL/2026110601-hearthstone_site_install_v1.0.sql';

/** Relative path from project root to ordered site DB release patches. */
const SAHTOUT_SITE_UPDATES_SQL_DIR = 'SQL/Hearthstone_DATABASE/UPDATES';
