<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

require_once __DIR__ . '/release.php';
require_once __DIR__ . '/site_settings.php';

const SAHTOUT_SITE_RELEASE_SETTING = 'site.release';

function sahtout_site_release_dir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'SQL' . DIRECTORY_SEPARATOR . 'Hearthstone_UPDATE' . DIRECTORY_SEPARATOR;
}

/** @return int Last applied release id stored in site_settings, or 0 when unset. */
function sahtout_site_release_applied(mysqli $siteDb): int
{
    if (!sahtout_site_settings_table_exists($siteDb)) {
        return 0;
    }
    $stmt = $siteDb->prepare(
        'SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1'
    );
    if (!$stmt) {
        return 0;
    }
    $key = SAHTOUT_SITE_RELEASE_SETTING;
    $stmt->bind_param('s', $key);
    if (!$stmt->execute()) {
        $stmt->close();

        return 0;
    }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return (int) preg_replace('/\D/', '', (string) ($row['setting_value'] ?? '0'));
}

/**
 * @return list<array{release: int, path: string, basename: string}>
 */
function sahtout_site_release_sql_manifest(?string $dir = null): array
{
    $dir = $dir ?? sahtout_site_release_dir();
    if (!is_dir($dir)) {
        return [];
    }
    $files = glob($dir . '*.sql') ?: [];
    sort($files, SORT_STRING);
    $out = [];
    foreach ($files as $path) {
        $base = basename($path);
        if (!preg_match('/^(\d{10})_/u', $base, $m)) {
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

function sahtout_site_release_mark_applied(mysqli $siteDb, int $releaseId, ?int $updatedBy = null): bool
{
    return sahtout_site_setting_set($siteDb, SAHTOUT_SITE_RELEASE_SETTING, (string) $releaseId, 'int', $updatedBy);
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
 * Apply all pending Hearthstone_UPDATE scripts in release order.
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
        $applied[] = $entry['basename'];
        $final = $entry['release'];
    }

    return [
        'applied' => $applied,
        'errors' => $errors,
        'final_release' => $final,
    ];
}
