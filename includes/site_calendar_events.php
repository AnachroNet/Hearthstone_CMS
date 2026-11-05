<?php

if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

/** @return array<int, string> bit => translation key */
function sahtout_site_calendar_events_weekday_definitions(): array
{
    return [
        1 => 'admin_events_dow_mon',
        2 => 'admin_events_dow_tue',
        4 => 'admin_events_dow_wed',
        8 => 'admin_events_dow_thu',
        16 => 'admin_events_dow_fri',
        32 => 'admin_events_dow_sat',
        64 => 'admin_events_dow_sun',
    ];
}

function sahtout_site_calendar_events_weekday_default_label(int $bit): string
{
    return match ($bit) {
        1 => 'Mon',
        2 => 'Tue',
        4 => 'Wed',
        8 => 'Thu',
        16 => 'Fri',
        32 => 'Sat',
        64 => 'Sun',
        default => '',
    };
}

function sahtout_site_calendar_events_table_exists(?mysqli $siteDb): bool
{
    if (!$siteDb instanceof mysqli || $siteDb->connect_error) {
        return false;
    }
    $res = $siteDb->query("SHOW TABLES LIKE 'site_calendar_events'");

    return $res instanceof mysqli_result && $res->num_rows > 0;
}

function sahtout_site_calendar_events_supports_recurrence(?mysqli $siteDb): bool
{
    if (!sahtout_site_calendar_events_table_exists($siteDb)) {
        return false;
    }
    $res = $siteDb->query("SHOW COLUMNS FROM site_calendar_events LIKE 'is_recurring'");

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

    $select = 'SELECT id, title, description, start_date, end_date, is_active, created_at, updated_at';
    if (sahtout_site_calendar_events_supports_recurrence($siteDb)) {
        $select .= ', is_recurring, recurrence_days';
    }
    $sql = $select . ' FROM site_calendar_events';
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
        if (!array_key_exists('is_recurring', $row)) {
            $row['is_recurring'] = 0;
            $row['recurrence_days'] = 0;
        }
        $rows[] = $row;
    }
    $res->free();

    return $rows;
}

/**
 * @return array{is_recurring: int, recurrence_days: int}
 */
function sahtout_site_calendar_events_recurrence_from_request(array $post): array
{
    $isRecurring = !empty($post['is_recurring']) ? 1 : 0;
    $days = 0;
    if ($isRecurring) {
        $selected = $post['recurrence_day'] ?? [];
        if (!is_array($selected)) {
            $selected = [$selected];
        }
        $allowed = array_keys(sahtout_site_calendar_events_weekday_definitions());
        foreach ($selected as $bit) {
            $bit = (int) $bit;
            if (in_array($bit, $allowed, true)) {
                $days |= $bit;
            }
        }
    }

    return ['is_recurring' => $isRecurring, 'recurrence_days' => $days];
}

function sahtout_site_calendar_events_validate_recurrence(
    int $isRecurring,
    int $recurrenceDays,
    string $startDate = '',
    string $endDate = ''
): ?string {
    if ($isRecurring !== 1) {
        return null;
    }
    if ($recurrenceDays === 0) {
        return translate('admin_events_validation_recurrence_days', 'Select at least one weekday for a recurring event.');
    }
    if ($startDate !== '' && $endDate !== '') {
        $probe = [
            'id' => 1,
            'title' => '',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'is_recurring' => 1,
            'recurrence_days' => $recurrenceDays,
        ];
        if (sahtout_site_calendar_events_row_to_occurrences($probe) === []) {
            return translate(
                'admin_events_validation_recurrence_none_in_range',
                'No selected weekdays fall between the start and end dates.'
            );
        }
    }

    return null;
}

/**
 * @return list<string> Short weekday labels (Mon, Tue, …)
 */
function sahtout_site_calendar_events_weekday_labels(int $recurrenceDays): array
{
    $labels = [];
    foreach (sahtout_site_calendar_events_weekday_definitions() as $bit => $key) {
        if (($recurrenceDays & $bit) === 0) {
            continue;
        }
        $labels[] = translate($key, sahtout_site_calendar_events_weekday_default_label($bit));
    }

    return $labels;
}

function sahtout_site_calendar_events_format_schedule(array $row): string
{
    $start = (string) ($row['start_date'] ?? '');
    $end = (string) ($row['end_date'] ?? '');
    if ($start === '' || $end === '') {
        return '';
    }

    $dateSpan = '';
    if ($start === $end) {
        $dateSpan = date('M j, Y', strtotime($start));
    } else {
        $dateSpan = date('M j, Y', strtotime($start)) . ' – ' . date('M j, Y', strtotime($end));
    }

    $isRecurring = (int) ($row['is_recurring'] ?? 0) === 1;
    if (!$isRecurring) {
        return $dateSpan;
    }

    $dayLabels = sahtout_site_calendar_events_weekday_labels((int) ($row['recurrence_days'] ?? 0));
    if ($dayLabels === []) {
        return $dateSpan;
    }

    return implode(', ', $dayLabels) . ' · ' . $dateSpan;
}

/**
 * @param array<string, mixed> $row
 * @return list<array{start: string, end: string, name: string, description: string, holiday_id: int, is_site_event: bool}>
 */
function sahtout_site_calendar_events_row_to_occurrences(array $row): array
{
    $start = (string) ($row['start_date'] ?? '');
    $end = (string) ($row['end_date'] ?? '');
    if ($start === '' || $end === '' || $end < $start) {
        return [];
    }

    $id = (int) ($row['id'] ?? 0);
    $base = [
        'name' => trim((string) ($row['title'] ?? '')),
        'description' => trim((string) ($row['description'] ?? '')),
        'holiday_id' => $id > 0 ? -$id : 0,
        'is_site_event' => true,
    ];

    $isRecurring = (int) ($row['is_recurring'] ?? 0) === 1;
    $recurrenceDays = (int) ($row['recurrence_days'] ?? 0);

    if (!$isRecurring || $recurrenceDays === 0) {
        return [[
            'start' => $start,
            'end' => $end,
        ] + $base];
    }

    $rangeStart = DateTimeImmutable::createFromFormat('Y-m-d', $start);
    $rangeEnd = DateTimeImmutable::createFromFormat('Y-m-d', $end);
    if (!$rangeStart || !$rangeEnd) {
        return [];
    }

    $out = [];
    for ($cursor = $rangeStart; $cursor <= $rangeEnd; $cursor = $cursor->modify('+1 day')) {
        $dow = (int) $cursor->format('N');
        $bit = 1 << ($dow - 1);
        if (($recurrenceDays & $bit) === 0) {
            continue;
        }
        $day = $cursor->format('Y-m-d');
        $out[] = [
            'start' => $day,
            'end' => $day,
        ] + $base;
    }

    return $out;
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

    $select = 'SELECT id, title, description, start_date, end_date';
    if (sahtout_site_calendar_events_supports_recurrence($siteDb)) {
        $select .= ', is_recurring, recurrence_days';
    }
    $stmt = $siteDb->prepare(
        $select . '
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
        if (!array_key_exists('is_recurring', $row)) {
            $row['is_recurring'] = 0;
            $row['recurrence_days'] = 0;
        }
        foreach (sahtout_site_calendar_events_row_to_occurrences($row) as $occ) {
            if ($occ['end'] < $rangeStart || $occ['start'] > $rangeEnd) {
                continue;
            }
            $out[] = $occ;
        }
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
