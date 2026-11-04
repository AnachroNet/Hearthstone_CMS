<?php

if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'holiday_date_calculator.php';

function sahtout_holiday_calendar_tables_exist(?mysqli $siteDb): bool
{
    if (!$siteDb instanceof mysqli || $siteDb->connect_error) {
        return false;
    }
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $res = $siteDb->query("SHOW TABLES LIKE 'holidays_dbc'");
    $exists = $res && $res->num_rows > 0;
    $res?->free();

    return $exists;
}

function sahtout_holiday_calendar_should_show(int $holidayId, int $calendarFilterType, int $flags): bool
{
    if ($calendarFilterType === 2) {
        return false;
    }
    if ($holidayId === 406) {
        return false;
    }
    if ($holidayId === 335) {
        return false;
    }
    if ($flags === 0 && $holidayId === 406) {
        return false;
    }

    return true;
}

/**
 * @return list<array<string, mixed>>
 */
function sahtout_holiday_calendar_load_holidays(?mysqli $siteDb): array
{
    if (!sahtout_holiday_calendar_tables_exist($siteDb)) {
        return [];
    }

    $sql = 'SELECT h.*,
            hn.Name_Lang_enUS AS holiday_name,
            hd.Description_Lang_enUS AS holiday_description
        FROM holidays_dbc h
        LEFT JOIN holidaynames_dbc hn ON hn.ID = h.HolidayNameID
        LEFT JOIN holidaydescriptions_dbc hd ON hd.ID = h.HolidayDescriptionID
        ORDER BY h.Priority DESC, h.ID ASC';

    $res = $siteDb->query($sql);
    if (!$res) {
        error_log('holiday_calendar: ' . $siteDb->error);

        return [];
    }

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $id = (int) ($row['ID'] ?? 0);
        $filter = (int) ($row['CalendarFilterType'] ?? 0);
        $flags = (int) ($row['Flags'] ?? 0);
        if (!sahtout_holiday_calendar_should_show($id, $filter, $flags)) {
            continue;
        }
        $rows[] = $row;
    }
    $res->free();

    return $rows;
}

function sahtout_holiday_duration_hours(array $holidayRow, int $dateIndex): int
{
    $key = 'Duration_' . $dateIndex;
    $dur = (int) ($holidayRow[$key] ?? 0);
    if ($dur > 0) {
        return $dur;
    }

    return max(24, (int) ($holidayRow['Duration_1'] ?? 24));
}

/**
 * @return list<array{start: string, end: string, name: string, description: string, holiday_id: int}>
 */
function sahtout_holiday_occurrences_in_year(array $holidayRow, int $year): array
{
    $holidayId = (int) ($holidayRow['ID'] ?? 0);
    $name = trim((string) ($holidayRow['holiday_name'] ?? 'World event'));
    if ($name === '') {
        $name = 'World event';
    }
    $description = trim((string) ($holidayRow['holiday_description'] ?? ''));

    $packedDates = sahtout_holiday_packed_for_year($holidayId, $year);
    if ($packedDates === []) {
        for ($i = 1; $i <= 26; $i++) {
            $raw = (int) ($holidayRow['Date_' . $i] ?? 0);
            if ($raw === 0) {
                continue;
            }
            $annual = sahtout_holiday_annualize_packed($raw, $year);
            if ($annual !== null) {
                $packedDates[] = $annual;
            }
        }
        $packedDates = array_values(array_unique($packedDates));
    }

    $occurrences = [];
    $dateIdx = 0;
    foreach ($packedDates as $packed) {
        $dateIdx++;
        $u = sahtout_holiday_unpack_date($packed);
        if ($u === null || (int) $u['year'] !== $year) {
            continue;
        }
        $start = sprintf('%04d-%02d-%02d', $u['year'], $u['month'], $u['day']);
        $hours = sahtout_holiday_duration_hours($holidayRow, $dateIdx);
        $endDt = (new DateTimeImmutable($start))->modify('+' . max(0, $hours - 1) . ' hours');
        $end = $endDt->format('Y-m-d');
        $occurrences[] = [
            'start' => $start,
            'end' => $end,
            'name' => $name,
            'description' => $description,
            'holiday_id' => $holidayId,
        ];
    }

    return $occurrences;
}

/**
 * @return array{year: int, month: int, events_by_day: array<int, list<array>>, month_events: list<array>, holidays_enabled: bool}
 */
function sahtout_holiday_calendar_build(?mysqli $siteDb, int $year, int $month): array
{
    $empty = [
        'year' => $year,
        'month' => $month,
        'events_by_day' => [],
        'month_events' => [],
        'holidays_enabled' => sahtout_holiday_calendar_tables_exist($siteDb),
    ];

    if (!$empty['holidays_enabled']) {
        return $empty;
    }

    $holidays = sahtout_holiday_calendar_load_holidays($siteDb);
    if ($holidays === []) {
        return $empty;
    }

    $all = [];
    foreach ([$year - 1, $year, $year + 1] as $y) {
        foreach ($holidays as $row) {
            foreach (sahtout_holiday_occurrences_in_year($row, $y) as $occ) {
                $all[] = $occ;
            }
        }
    }

    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $daysInMonth = (int) (new DateTimeImmutable($monthStart))->format('t');
    $eventsByDay = [];
    $monthEvents = [];

    foreach ($all as $occ) {
        if ($occ['end'] < $monthStart) {
            continue;
        }
        $monthEnd = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);
        if ($occ['start'] > $monthEnd) {
            continue;
        }

        $monthEvents[] = $occ;

        $cursor = new DateTimeImmutable($occ['start'] < $monthStart ? $monthStart : $occ['start']);
        $until = new DateTimeImmutable($occ['end'] > $monthEnd ? $monthEnd : $occ['end']);
        while ($cursor <= $until) {
            if ((int) $cursor->format('n') === $month && (int) $cursor->format('Y') === $year) {
                $d = (int) $cursor->format('j');
                $eventsByDay[$d] ??= [];
                $eventsByDay[$d][] = $occ;
            }
            $cursor = $cursor->modify('+1 day');
        }
    }

    usort($monthEvents, static function ($a, $b) {
        return strcmp($a['start'], $b['start']) ?: strcmp($a['name'], $b['name']);
    });

    foreach ($eventsByDay as &$list) {
        usort($list, static fn ($a, $b) => strcmp($a['name'], $b['name']));
    }
    unset($list);

    return [
        'year' => $year,
        'month' => $month,
        'events_by_day' => $eventsByDay,
        'month_events' => $monthEvents,
        'holidays_enabled' => true,
    ];
}

function sahtout_holiday_calendar_parse_month(?string $calParam): array
{
    $now = new DateTimeImmutable('now');
    if ($calParam === null || !preg_match('/^\d{4}-\d{2}$/', $calParam)) {
        return [(int) $now->format('Y'), (int) $now->format('n')];
    }
    [$y, $m] = array_map('intval', explode('-', $calParam, 2));
    if ($m < 1 || $m > 12) {
        return [(int) $now->format('Y'), (int) $now->format('n')];
    }
    if ($y < 2000 || $y > 2030) {
        return [(int) $now->format('Y'), (int) $now->format('n')];
    }

    return [$y, $m];
}

function sahtout_holiday_calendar_nav_url(string $basePath, int $year, int $month): string
{
    return htmlspecialchars($basePath . '?cal=' . sprintf('%04d-%02d', $year, $month), ENT_QUOTES, 'UTF-8');
}

function sahtout_holiday_calendar_shift_month(int $year, int $month, int $delta): array
{
    $dt = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->modify(
        ($delta >= 0 ? '+' : '') . $delta . ' months'
    );

    return [(int) $dt->format('Y'), (int) $dt->format('n')];
}
