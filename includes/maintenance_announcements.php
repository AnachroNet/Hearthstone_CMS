<?php

if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

function sahtout_maintenance_announcements_table_exists(mysqli $siteDb): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $res = $siteDb->query("SHOW TABLES LIKE 'maintenance_announcements'");
    $exists = $res && $res->num_rows > 0;
    $res?->free();

    return $exists;
}

function sahtout_maintenance_timezone(): DateTimeZone
{
    global $site_timezone;
    $id = (isset($site_timezone) && is_string($site_timezone) && $site_timezone !== '')
        ? $site_timezone
        : (date_default_timezone_get() ?: 'UTC');
    try {
        return new DateTimeZone($id);
    } catch (Exception $e) {
        error_log('[maintenance_announcements] invalid timezone ' . $id . ': ' . $e->getMessage());

        return new DateTimeZone('UTC');
    }
}

function sahtout_maintenance_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', sahtout_maintenance_timezone());
}

/** Parse HTML datetime-local (site timezone) to MySQL datetime string. */
function sahtout_maintenance_parse_datetime_local(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $raw, sahtout_maintenance_timezone());
    if ($dt === false) {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $raw, sahtout_maintenance_timezone());
    }
    if ($dt === false) {
        return null;
    }

    return $dt->format('Y-m-d H:i:s');
}

function sahtout_maintenance_datetime_to_input(string $dbValue): string
{
    try {
        $dt = new DateTimeImmutable($dbValue, sahtout_maintenance_timezone());
    } catch (Exception $e) {
        return '';
    }

    return $dt->format('Y-m-d\TH:i');
}

/**
 * @return list<array<string, mixed>>
 */
function sahtout_maintenance_fetch_visible(mysqli $siteDb): array
{
    if (!sahtout_maintenance_announcements_table_exists($siteDb)) {
        return [];
    }
    $now = sahtout_maintenance_now()->format('Y-m-d H:i:s');
    $stmt = $siteDb->prepare(
        'SELECT id, message, visible_from, visible_until, posted_by
         FROM maintenance_announcements
         WHERE visible_from <= ? AND visible_until >= ?
         ORDER BY visible_from DESC, id DESC'
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('ss', $now, $now);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

/**
 * @return list<array<string, mixed>>
 */
function sahtout_maintenance_fetch_all(mysqli $siteDb, int $limit = 50): array
{
    if (!sahtout_maintenance_announcements_table_exists($siteDb)) {
        return [];
    }
    $limit = max(1, min(200, $limit));
    $stmt = $siteDb->prepare(
        'SELECT id, message, visible_from, visible_until, posted_by, created_at
         FROM maintenance_announcements
         ORDER BY visible_from DESC, id DESC
         LIMIT ?'
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function sahtout_maintenance_announcement_status(array $row, ?DateTimeImmutable $now = null): string
{
    $now = $now ?? sahtout_maintenance_now();
    try {
        $from = new DateTimeImmutable((string) ($row['visible_from'] ?? ''), sahtout_maintenance_timezone());
        $until = new DateTimeImmutable((string) ($row['visible_until'] ?? ''), sahtout_maintenance_timezone());
    } catch (Exception $e) {
        return 'unknown';
    }
    if ($now < $from) {
        return 'scheduled';
    }
    if ($now > $until) {
        return 'expired';
    }

    return 'active';
}
