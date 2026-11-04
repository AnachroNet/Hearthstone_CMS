<?php

if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'holiday_date_calculator.php';

/** Case-insensitive DBC row field (MySQL drivers may lower-case column names). */
function sahtout_holiday_row_value(array $row, string $field): mixed
{
    if (array_key_exists($field, $row)) {
        return $row[$field];
    }
    $want = strtolower($field);
    foreach ($row as $key => $value) {
        if (strtolower((string) $key) === $want) {
            return $value;
        }
    }

    return null;
}

function sahtout_holiday_row_int(array $row, string $field): int
{
    return (int) sahtout_holiday_row_value($row, $field);
}

function sahtout_holiday_row_string(array $row, string $field): string
{
    return trim((string) (sahtout_holiday_row_value($row, $field) ?? ''));
}

function sahtout_holiday_label_from_texture(string $texture): string
{
    $texture = trim($texture);
    if ($texture === '') {
        return '';
    }
    $label = preg_replace('/^Calendar_/', '', $texture) ?? $texture;
    $label = str_replace('_', ' ', $label);
    $label = (string) preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $label);

    return trim($label);
}

/** @return array<int, string> */
function sahtout_holiday_calendar_load_name_map(?mysqli $siteDb): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    if (!$siteDb instanceof mysqli || $siteDb->connect_error) {
        return $cache;
    }
    $res = $siteDb->query('SELECT ID, Name_Lang_enUS FROM holidaynames_dbc');
    if (!$res) {
        error_log('holiday_calendar names: ' . $siteDb->error);

        return $cache;
    }
    while ($row = $res->fetch_assoc()) {
        $id = sahtout_holiday_row_int($row, 'ID');
        $name = sahtout_holiday_row_string($row, 'Name_Lang_enUS');
        if ($id > 0 && $name !== '') {
            $cache[$id] = $name;
        }
    }
    $res->free();

    return $cache;
}

/** @return array<int, string> */
function sahtout_holiday_calendar_load_description_map(?mysqli $siteDb): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    if (!$siteDb instanceof mysqli || $siteDb->connect_error) {
        return $cache;
    }
    $res = $siteDb->query('SELECT ID, Description_Lang_enUS FROM holidaydescriptions_dbc');
    if (!$res) {
        error_log('holiday_calendar descriptions: ' . $siteDb->error);

        return $cache;
    }
    while ($row = $res->fetch_assoc()) {
        $id = sahtout_holiday_row_int($row, 'ID');
        $text = sahtout_holiday_row_string($row, 'Description_Lang_enUS');
        if ($id > 0 && $text !== '') {
            $cache[$id] = $text;
        }
    }
    $res->free();

    return $cache;
}

function sahtout_holiday_calendar_tables_exist(?mysqli $siteDb): bool
{
    if (!$siteDb instanceof mysqli || $siteDb->connect_error) {
        return false;
    }

    $dbName = '';
    $dbRes = $siteDb->query('SELECT DATABASE() AS db');
    if ($dbRes instanceof mysqli_result) {
        $row = $dbRes->fetch_assoc();
        $dbName = trim((string) ($row['db'] ?? ''));
        $dbRes->free();
    }
    if ($dbName === '') {
        error_log('holiday_calendar: site DB has no DATABASE() selected');

        return false;
    }

    $table = 'holidays_dbc';
    $stmt = $siteDb->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1'
    );
    if (!$stmt) {
        error_log('holiday_calendar: information_schema probe failed: ' . $siteDb->error);
        $fallback = $siteDb->query("SHOW TABLES LIKE 'holidays_dbc'");

        return $fallback instanceof mysqli_result && $fallback->num_rows > 0;
    }
    $stmt->bind_param('ss', $dbName, $table);
    $stmt->execute();
    $result = $stmt->get_result();
    $ok = $result instanceof mysqli_result && $result->num_rows > 0;
    $result?->free();
    $stmt->close();

    if (!$ok) {
        error_log('holiday_calendar: `' . $table . '` not found in schema `' . $dbName . '`');
    }

    return $ok;
}

/** @return array<string, mixed> */
function sahtout_holiday_calendar_empty_month(int $year, int $month): array
{
    return [
        'year' => $year,
        'month' => $month,
        'events_by_day' => [],
        'month_events' => [],
        'holidays_enabled' => true,
    ];
}

function sahtout_holiday_calendar_timezone(string $siteTimezone): DateTimeZone
{
    $tz = trim($siteTimezone);
    if ($tz === '') {
        $tz = 'UTC';
    }
    try {
        return new DateTimeZone($tz);
    } catch (Exception $e) {
        return new DateTimeZone('UTC');
    }
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

    $res = $siteDb->query('SELECT * FROM holidays_dbc ORDER BY Priority DESC, ID ASC');
    if (!$res) {
        error_log('holiday_calendar: ' . $siteDb->error);

        return [];
    }

    $names = sahtout_holiday_calendar_load_name_map($siteDb);
    $descriptions = sahtout_holiday_calendar_load_description_map($siteDb);

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $id = sahtout_holiday_row_int($row, 'ID');
        $filter = sahtout_holiday_row_int($row, 'CalendarFilterType');
        $flags = sahtout_holiday_row_int($row, 'Flags');
        if (!sahtout_holiday_calendar_should_show($id, $filter, $flags)) {
            continue;
        }

        $nameId = sahtout_holiday_row_int($row, 'HolidayNameID');
        $descId = sahtout_holiday_row_int($row, 'HolidayDescriptionID');
        $name = $names[$nameId] ?? sahtout_holiday_label_from_texture(sahtout_holiday_row_string($row, 'TextureFilename'));
        if ($name === '') {
            $name = 'World event #' . $id;
        }
        $row['holiday_name'] = $name;
        $row['holiday_description'] = $descriptions[$descId] ?? '';

        $rows[] = $row;
    }
    $res->free();

    if ($rows === []) {
        error_log('holiday_calendar: holidays_dbc returned no displayable rows');
    }

    return $rows;
}

function sahtout_holiday_duration_hours(array $holidayRow, int $dateIndex): int
{
    $dur = sahtout_holiday_row_int($holidayRow, 'Duration_' . $dateIndex);
    if ($dur > 0) {
        return $dur;
    }

    return max(24, sahtout_holiday_row_int($holidayRow, 'Duration_1'));
}

/**
 * @return list<array{start: string, end: string, name: string, description: string, holiday_id: int}>
 */
function sahtout_holiday_occurrences_in_year(array $holidayRow, int $year): array
{
    $holidayId = sahtout_holiday_row_int($holidayRow, 'ID');
    $name = trim((string) ($holidayRow['holiday_name'] ?? 'World event'));
    if ($name === '') {
        $name = 'World event';
    }
    $description = trim((string) ($holidayRow['holiday_description'] ?? ''));

    /** @var list<array{year: int, month: int, day: int}> $startDates */
    $startDates = [];
    $rule = sahtout_holiday_rule_for($holidayId);
    if ($rule !== null && $year >= 2000 && $year <= 2030) {
        if ($rule['type'] === 'darkmoon') {
            $location = (int) ($rule['location'] ?? 0);
            $offset = (int) ($rule['offset'] ?? 0);
            for ($m = 1; $m <= 12; $m++) {
                if ($m % 3 !== $location) {
                    continue;
                }
                $d = sahtout_holiday_nth_weekday($year, $m, 0, 1);
                if ($offset !== 0) {
                    $d = sahtout_holiday_add_days($d['year'], $d['month'], $d['day'], $offset);
                }
                if ((int) $d['year'] === $year) {
                    $startDates[] = $d;
                }
            }
        } else {
            $startDates[] = sahtout_holiday_calculate_date($rule, $year);
        }
    }

    if ($startDates === []) {
        for ($i = 1; $i <= 26; $i++) {
            $raw = sahtout_holiday_row_int($holidayRow, 'Date_' . $i);
            if ($raw === 0) {
                continue;
            }
            $annual = sahtout_holiday_annualize_packed($raw, $year);
            $u = $annual !== null ? sahtout_holiday_unpack_date($annual) : null;
            if ($u !== null && (int) $u['year'] === $year) {
                $startDates[] = $u;
            }
        }
    }

    $occurrences = [];
    $dateIdx = 0;
    foreach ($startDates as $u) {
        $dateIdx++;
        $occYear = (int) $u['year'];
        if ($occYear !== $year) {
            continue;
        }
        if (!checkdate((int) $u['month'], (int) $u['day'], $occYear)) {
            continue;
        }
        $start = sprintf('%04d-%02d-%02d', $occYear, $u['month'], $u['day']);
        $hours = sahtout_holiday_duration_hours($holidayRow, $dateIdx);
        try {
            $endDt = (new DateTimeImmutable($start))->modify('+' . max(0, $hours - 1) . ' hours');
        } catch (Exception $e) {
            continue;
        }
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
    if ($year < 2000 || $year > 2030 || $month < 1 || $month > 12) {
        return sahtout_holiday_calendar_parse_month(null);
    }

    try {
        $dt = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->modify(
            ($delta >= 0 ? '+' : '') . $delta . ' months'
        );

        return [(int) $dt->format('Y'), (int) $dt->format('n')];
    } catch (Exception $e) {
        return sahtout_holiday_calendar_parse_month(null);
    }
}

/**
 * Home page calendar context; never throws (failures are logged, home page keeps rendering).
 *
 * @return array{
 *   show: bool,
 *   year: int,
 *   month: int,
 *   calendar: array<string, mixed>|null,
 *   today: ?DateTimeImmutable,
 *   is_current_month: bool,
 *   today_day: int,
 *   prev_y: int,
 *   prev_m: int,
 *   next_y: int,
 *   next_m: int,
 *   month_label: string
 * }
 */
function sahtout_holiday_calendar_for_home(?mysqli $siteDb, ?string $calParam, string $siteTimezone = ''): array
{
    [$year, $month] = sahtout_holiday_calendar_parse_month($calParam);
    $out = [
        'show' => false,
        'year' => $year,
        'month' => $month,
        'calendar' => null,
        'today' => null,
        'is_current_month' => false,
        'today_day' => 1,
        'prev_y' => $year,
        'prev_m' => $month,
        'next_y' => $year,
        'next_m' => $month,
        'month_label' => '',
    ];

    if (!$siteDb instanceof mysqli || $siteDb->connect_error) {
        error_log('holiday_calendar: site DB connection unavailable for calendar');

        return $out;
    }

    if (!sahtout_holiday_calendar_tables_exist($siteDb)) {
        return $out;
    }

    $out['show'] = true;

    try {
        $tz = sahtout_holiday_calendar_timezone($siteTimezone);
        $today = new DateTimeImmutable('now', $tz);
        $out['today'] = $today;
        $out['is_current_month'] = $year === (int) $today->format('Y') && $month === (int) $today->format('n');
        $out['today_day'] = (int) $today->format('j');
        [$out['prev_y'], $out['prev_m']] = sahtout_holiday_calendar_shift_month($year, $month, -1);
        [$out['next_y'], $out['next_m']] = sahtout_holiday_calendar_shift_month($year, $month, 1);
        $out['month_label'] = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('F Y');

        $out['calendar'] = sahtout_holiday_calendar_build($siteDb, $year, $month);
    } catch (Throwable $e) {
        error_log('sahtout_holiday_calendar_for_home: ' . $e->getMessage());
        $out['calendar'] = sahtout_holiday_calendar_empty_month($year, $month);
    }

    if (!is_array($out['calendar'])) {
        $out['calendar'] = sahtout_holiday_calendar_empty_month($year, $month);
    }

    return $out;
}
