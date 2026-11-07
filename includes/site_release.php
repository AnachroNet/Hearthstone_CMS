<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

require_once __DIR__ . '/release.php';
require_once __DIR__ . '/site_settings.php';

const SAHTOUT_SITE_RELEASE_SETTING = 'site.release';
const SAHTOUT_SITE_VERSION_SETTING = 'site.version';

function sahtout_site_updates_dir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, SAHTOUT_SITE_UPDATES_SQL_DIR) . DIRECTORY_SEPARATOR;
}

/** @deprecated Use sahtout_site_updates_dir(). */
function sahtout_site_release_dir(): string
{
    return sahtout_site_updates_dir();
}

function sahtout_site_setting_string(mysqli $siteDb, string $key, string $default = ''): string
{
    if (!sahtout_site_settings_table_exists($siteDb)) {
        return $default;
    }
    $stmt = $siteDb->prepare(
        'SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1'
    );
    if (!$stmt) {
        return $default;
    }
    $stmt->bind_param('s', $key);
    if (!$stmt->execute()) {
        $stmt->close();

        return $default;
    }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    $val = trim((string) ($row['setting_value'] ?? ''));

    return $val !== '' ? $val : $default;
}

/** @return int Last applied release id, baseline when keys are missing, or 0 if site_settings is absent. */
function sahtout_site_release_applied(mysqli $siteDb): int
{
    if (!sahtout_site_settings_table_exists($siteDb)) {
        return 0;
    }
    $raw = sahtout_site_setting_string($siteDb, SAHTOUT_SITE_RELEASE_SETTING, '');
    if ($raw === '') {
        return SAHTOUT_SITE_DB_BASELINE_RELEASE;
    }

    return (int) preg_replace('/\D/', '', $raw);
}

/** @return string Applied CMS version, baseline when key is missing, or empty if site_settings is absent. */
function sahtout_site_version_applied(mysqli $siteDb): string
{
    if (!sahtout_site_settings_table_exists($siteDb)) {
        return '';
    }
    $raw = sahtout_site_setting_string($siteDb, SAHTOUT_SITE_VERSION_SETTING, '');
    if ($raw === '') {
        return SAHTOUT_SITE_DB_BASELINE_VERSION;
    }

    return $raw;
}

/** True when version/release are inferred from the v1.0 baseline, not stored in site_settings yet. */
function sahtout_site_release_using_baseline_assumption(mysqli $siteDb): bool
{
    if (!sahtout_site_settings_table_exists($siteDb)) {
        return false;
    }
    $hasVersion = sahtout_site_setting_string($siteDb, SAHTOUT_SITE_VERSION_SETTING, '') !== '';
    $hasRelease = sahtout_site_setting_string($siteDb, SAHTOUT_SITE_RELEASE_SETTING, '') !== '';

    return !$hasVersion || !$hasRelease;
}

/** Human-readable label, e.g. "Version 1.0 — Release 2026110601". */
function sahtout_site_release_label(string $version, int $release): string
{
    $version = trim($version);
    $release = max(0, $release);
    if ($version === '' && $release === 0) {
        return '';
    }
    if ($version === '') {
        return 'Release ' . (string) $release;
    }
    if ($release === 0) {
        return 'Version ' . $version;
    }

    return 'Version ' . $version . ' — Release ' . (string) $release;
}

/**
 * @return list<array{release: int, path: string, basename: string}>
 */
function sahtout_site_release_sql_manifest(?string $dir = null): array
{
    $dir = $dir ?? sahtout_site_updates_dir();
    if (!is_dir($dir)) {
        return [];
    }
    $files = glob($dir . '*.sql') ?: [];
    sort($files, SORT_STRING);
    $out = [];
    foreach ($files as $path) {
        $base = basename($path);
        if (!preg_match('/^(\d{10})[-_]/u', $base, $m)) {
            continue;
        }
        $out[] = [
            'release' => (int) $m[1],
            'path' => $path,
            'basename' => $base,
        ];
    }

    return $out;
}

/**
 * @return list<array{release: int, path: string, basename: string}>
 */
function sahtout_site_release_pending(mysqli $siteDb): array
{
    $applied = sahtout_site_release_applied($siteDb);
    $pending = [];
    foreach (sahtout_site_release_sql_manifest() as $entry) {
        if ($entry['release'] > $applied) {
            $pending[] = $entry;
        }
    }

    return $pending;
}

/** True when this codebase expects newer site DB release SQL than the database has recorded. */
function sahtout_site_database_release_behind_code(mysqli $siteDb): bool
{
    if (SAHTOUT_CODE_RELEASE <= 0) {
        return false;
    }
    if (sahtout_site_release_pending($siteDb) !== []) {
        return true;
    }

    return sahtout_site_release_applied($siteDb) < SAHTOUT_CODE_RELEASE;
}

function sahtout_site_release_mark_applied(mysqli $siteDb, int $releaseId, ?int $updatedBy = null): bool
{
    return sahtout_site_setting_set($siteDb, SAHTOUT_SITE_RELEASE_SETTING, (string) $releaseId, 'int', $updatedBy);
}

function sahtout_site_version_mark_applied(mysqli $siteDb, string $version, ?int $updatedBy = null): bool
{
    $version = trim($version);
    if ($version === '') {
        return false;
    }

    return sahtout_site_setting_set($siteDb, SAHTOUT_SITE_VERSION_SETTING, $version, 'string', $updatedBy);
}

/**
 * Execute one release SQL file against the site DB connection (must already USE the site schema).
 *
 * @return array{ok: bool, error: ?string}
 */
function sahtout_site_release_execute_sql_file(mysqli $siteDb, string $path): array
{
    if (!is_readable($path)) {
        return ['ok' => false, 'error' => 'File not readable: ' . $path];
    }
    $sql = (string) file_get_contents($path);
    if (trim($sql) === '') {
        return ['ok' => false, 'error' => 'Empty SQL file: ' . $path];
    }
    if (!$siteDb->multi_query($sql)) {
        return ['ok' => false, 'error' => $siteDb->error ?: 'multi_query failed'];
    }
    do {
        if ($result = $siteDb->store_result()) {
            $result->free();
        }
        if ($siteDb->errno) {
            return ['ok' => false, 'error' => $siteDb->error];
        }
    } while ($siteDb->more_results() && $siteDb->next_result());

    if ($siteDb->errno) {
        return ['ok' => false, 'error' => $siteDb->error];
    }

    return ['ok' => true, 'error' => null];
}

/**
 * Apply all pending Hearthstone_DATABASE/UPDATES scripts in release order.
 *
 * @return array{applied: list<string>, errors: list<string>, final_release: int}
 */
function sahtout_site_release_apply_pending(mysqli $siteDb, ?int $updatedBy = null): array
{
    $applied = [];
    $errors = [];
    $final = sahtout_site_release_applied($siteDb);

    foreach (sahtout_site_release_pending($siteDb) as $entry) {
        $exec = sahtout_site_release_execute_sql_file($siteDb, $entry['path']);
        if (!$exec['ok']) {
            $errors[] = $entry['basename'] . ': ' . ($exec['error'] ?? 'unknown error');
            break;
        }
        if (!sahtout_site_release_mark_applied($siteDb, $entry['release'], $updatedBy)) {
            $errors[] = $entry['basename'] . ': applied SQL but failed to set ' . SAHTOUT_SITE_RELEASE_SETTING;
            break;
        }
        sahtout_site_version_mark_applied($siteDb, SAHTOUT_CODE_VERSION, $updatedBy);
        $applied[] = $entry['basename'];
        $final = $entry['release'];
    }

    return [
        'applied' => $applied,
        'errors' => $errors,
        'final_release' => $final,
    ];
}
