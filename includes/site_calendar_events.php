<?php

if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

function sahtout_site_calendar_events_table_exists(?mysqli $siteDb): bool
{
    if (!$siteDb instanceof mysqli || $siteDb->connect_error) {
        return false;
    }
    $res = $siteDb->query("SHOW TABLES LIKE 'site_calendar_events'");

    return $res instanceof mysqli_result && $res->num_rows > 0;
}

/**
 * @return list<array<string, mixed>>
 */
function sahtout_site_calendar_events_fetch_all(?mysqli $siteDb, bool $activeOnly = false): array
{
    if (!sahtout_site_calendar_events_table_exists($siteDb)) {
        return [];
    }

    $sql = 'SELECT id, title, description, start_date, end_date, is_active, created_at, updated_at
            FROM site_calendar_events';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY start_date DESC, title ASC';

    $res = $siteDb->query($sql);
    if (!$res) {
        error_log('site_calendar_events fetch: ' . $siteDb->error);

        return [];
    }

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $res->free();

    return $rows;
}

/**
 * Calendar occurrences for custom site events (same shape as WoW holiday spans).
 *
 * @return list<array{start: string, end: string, name: string, description: string, holiday_id: int, is_site_event: bool}>
 */
function sahtout_site_calendar_event_occurrences(?mysqli $siteDb, int $fromYear, int $toYear): array
{
    if (!sahtout_site_calendar_events_table_exists($siteDb) || $fromYear > $toYear) {
        return [];
    }

    $rangeStart = sprintf('%04d-01-01', $fromYear);
    $rangeEnd = sprintf('%04d-12-31', $toYear);

    $stmt = $siteDb->prepare(
        'SELECT id, title, description, start_date, end_date
         FROM site_calendar_events
         WHERE is_active = 1 AND end_date >= ? AND start_date <= ?
         ORDER BY start_date ASC, title ASC'
    );
    if (!$stmt) {
        error_log('site_calendar_events range: ' . $siteDb->error);

        return [];
    }
    $stmt->bind_param('ss', $rangeStart, $rangeEnd);
    $stmt->execute();
    $result = $stmt->get_result();

    $out = [];
    while ($row = $result->fetch_assoc()) {
        $start = (string) ($row['start_date'] ?? '');
        $end = (string) ($row['end_date'] ?? '');
        if ($start === '' || $end === '') {
            continue;
        }
        if ($end < $start) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        $out[] = [
            'start' => $start,
            'end' => $end,
            'name' => trim((string) ($row['title'] ?? '')),
            'description' => trim((string) ($row['description'] ?? '')),
            'holiday_id' => $id > 0 ? -$id : 0,
            'is_site_event' => true,
        ];
    }
    $result?->free();
    $stmt->close();

    return $out;
}

function sahtout_site_calendar_events_validate_dates(string $startDate, string $endDate): ?string
{
    $startDate = trim($startDate);
    $endDate = trim($endDate);
    if ($startDate === '' || $endDate === '') {
        return translate('admin_events_validation_dates', 'Start and end dates are required.');
    }
    $start = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
    $end = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
    if (!$start || $start->format('Y-m-d') !== $startDate || !$end || $end->format('Y-m-d') !== $endDate) {
        return translate('admin_events_validation_format', 'Use valid YYYY-MM-DD dates.');
    }
    if ($end < $start) {
        return translate('admin_events_validation_order', 'End date must be on or after the start date.');
    }

    return null;
}
