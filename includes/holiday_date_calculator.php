<?php

if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

/**
 * WoW holiday date math aligned with AzerothCore HolidayDateCalculator + GameEventMgr::LoadHolidayDates.
 */

function sahtout_holiday_rules(): array
{
    static $rules = null;
    if ($rules !== null) {
        return $rules;
    }

    $rules = [
        ['id' => 327, 'type' => 'lunar_new_year', 'offset' => -1],
        ['id' => 423, 'type' => 'weekday_on_or_after', 'month' => 2, 'day' => 3, 'weekday' => 1],
        ['id' => 335, 'type' => 'weekday_on_or_after', 'month' => 2, 'day' => 3, 'weekday' => 1],
        ['id' => 181, 'type' => 'easter_offset', 'offset' => 1],
        ['id' => 201, 'type' => 'weekday_on_or_after', 'month' => 4, 'day' => 25, 'weekday' => 1],
        ['id' => 341, 'type' => 'fixed', 'month' => 6, 'day' => 21],
        ['id' => 62, 'type' => 'fixed', 'month' => 7, 'day' => 4],
        ['id' => 398, 'type' => 'fixed', 'month' => 9, 'day' => 19],
        ['id' => 372, 'type' => 'fixed', 'month' => 9, 'day' => 13],
        ['id' => 321, 'type' => 'autumn_equinox', 'offset' => -2],
        ['id' => 324, 'type' => 'fixed', 'month' => 10, 'day' => 18],
        ['id' => 409, 'type' => 'fixed', 'month' => 11, 'day' => 1],
        ['id' => 404, 'type' => 'nth_weekday', 'month' => 11, 'weekday' => 4, 'nth' => 4, 'offset' => -4],
        ['id' => 141, 'type' => 'winter_solstice', 'offset' => -6],
        ['id' => 374, 'type' => 'darkmoon', 'location' => 0, 'offset' => -2],
        ['id' => 375, 'type' => 'darkmoon', 'location' => 1, 'offset' => -2],
        ['id' => 376, 'type' => 'darkmoon', 'location' => 2, 'offset' => -2],
    ];

    return $rules;
}

function sahtout_holiday_rule_for(int $holidayId): ?array
{
    foreach (sahtout_holiday_rules() as $rule) {
        if ($rule['id'] === $holidayId) {
            return $rule;
        }
    }

    return null;
}

function sahtout_holiday_decode_packed(int $packed): ?array
{
    if ($packed === 0) {
        return null;
    }

    $year = (($packed >> 24) & 0x1F) + 2000;
    // WoW stores tm_mon (0 = January) in bits 20–23 (see AzerothCore HolidayDateCalculator::UnpackDate).
    $month = (($packed >> 20) & 0xF) + 1;
    $day = (($packed >> 14) & 0x3F) + 1;

    if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || !checkdate($month, $day, $year)) {
        return null;
    }

    return ['year' => $year, 'month' => $month, 'day' => $day];
}

function sahtout_holiday_unpack_date(int $packed): ?array
{
    return sahtout_holiday_decode_packed($packed);
}

function sahtout_holiday_pack_date(int $year, int $month, int $day): int
{
    if (!checkdate($month, $day, $year)) {
        return 0;
    }

    $dt = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    $wday = (int) $dt->format('w');
    $yearOffset = $year < 2000 ? 0 : min(31, $year - 2000);

    return ($yearOffset << 24) | (($month - 1) << 20) | (($day - 1) << 14) | ($wday << 11);
}

function sahtout_holiday_add_days(int $year, int $month, int $day, int $deltaDays): array
{
    $dt = (new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day)))->modify(
        ($deltaDays >= 0 ? '+' : '') . $deltaDays . ' days'
    );

    return sahtout_holiday_normalize_ymd_array([
        'year' => (int) $dt->format('Y'),
        'month' => (int) $dt->format('n'),
        'day' => (int) $dt->format('j'),
    ]);
}

/** @param array{year: int, month: int, day: int} $parts */
function sahtout_holiday_normalize_ymd_array(array $parts): array
{
    try {
        $dt = new DateTimeImmutable(sprintf(
            '%04d-%02d-%02d',
            (int) $parts['year'],
            (int) $parts['month'],
            (int) $parts['day']
        ));

        return [
            'year' => (int) $dt->format('Y'),
            'month' => (int) $dt->format('n'),
            'day' => (int) $dt->format('j'),
        ];
    } catch (Exception $e) {
        return $parts;
    }
}

function sahtout_holiday_easter_sunday(int $year): array
{
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;

    return sahtout_holiday_normalize_ymd_array(['year' => $year, 'month' => $month, 'day' => $day]);
}

function sahtout_holiday_nth_weekday(int $year, int $month, int $weekday, int $nth): array
{
    $dt = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $firstWday = (int) $dt->format('w');
    $daysUntil = ($weekday - $firstWday + 7) % 7;
    $day = 1 + $daysUntil + ($nth - 1) * 7;

    return sahtout_holiday_add_days($year, $month, 1, $daysUntil + ($nth - 1) * 7);
}

function sahtout_holiday_weekday_on_or_after(int $year, int $month, int $day, int $weekday): array
{
    $dt = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    $w = (int) $dt->format('w');
    $add = ($weekday - $w + 7) % 7;

    return sahtout_holiday_add_days($year, $month, $day, $add);
}

function sahtout_holiday_sind(float $deg): float
{
    return sin($deg * M_PI / 180.0);
}

function sahtout_holiday_date_to_jd(int $year, int $month, float $day): float
{
    if ($month <= 2) {
        $year -= 1;
        $month += 12;
    }
    $a = intdiv($year, 100);
    $b = 2 - $a + intdiv($a, 4);

    return floor(365.25 * ($year + 4716)) + floor(30.6001 * ($month + 1)) + $day + $b - 1524.5;
}

function sahtout_holiday_jd_to_date(float $jd): array
{
    $jd += 0.5;
    $z = (int) $jd;
    $a = $z;
    if ($z >= 2299161) {
        $alpha = (int) (($z - 1867216.25) / 36524.25);
        $a = $z + 1 + $alpha - intdiv($alpha, 4);
    }
    $b = $a + 1524;
    $c = (int) (($b - 122.1) / 365.25);
    $d = (int) (365.25 * $c);
    $e = (int) (($b - $d) / 30.6001);
    $day = $b - $d - (int) (30.6001 * $e);
    $month = ($e < 14) ? $e - 1 : $e - 13;
    $year = ($month > 2) ? $c - 4716 : $c - 4715;

    return sahtout_holiday_normalize_ymd_array(['year' => $year, 'month' => $month, 'day' => $day]);
}

function sahtout_holiday_new_moon(float $k): float
{
    $t = $k / 1236.85;
    $t2 = $t * $t;
    $t3 = $t2 * $t;
    $t4 = $t3 * $t;
    $jde = 2451550.09766 + 29.530588861 * $k + 0.00015437 * $t2 - 0.000000150 * $t3 + 0.00000000073 * $t4;
    $e = 1.0 - 0.002516 * $t - 0.0000074 * $t2;
    $e2 = $e * $e;
    $m = 2.5534 + 29.10535670 * $k - 0.0000014 * $t2 - 0.00000011 * $t3;
    $mPrime = 201.5643 + 385.81693528 * $k + 0.0107582 * $t2 + 0.00001238 * $t3 - 0.000000058 * $t4;
    $f = 160.7108 + 390.67050284 * $k - 0.0016118 * $t2 - 0.00000227 * $t3 + 0.000000011 * $t4;
    $omega = 124.7746 - 1.56375588 * $k + 0.0020672 * $t2 + 0.00000215 * $t3;

    $correction =
        -0.40720 * sahtout_holiday_sind($mPrime)
        + 0.17241 * $e * sahtout_holiday_sind($m)
        + 0.01608 * sahtout_holiday_sind(2 * $mPrime)
        + 0.01039 * sahtout_holiday_sind(2 * $f)
        + 0.00739 * $e * sahtout_holiday_sind($mPrime - $m)
        - 0.00514 * $e * sahtout_holiday_sind($mPrime + $m)
        + 0.00208 * $e2 * sahtout_holiday_sind(2 * $m)
        - 0.00111 * sahtout_holiday_sind($mPrime - 2 * $f)
        - 0.00057 * sahtout_holiday_sind($mPrime + 2 * $f);

    return $jde + $correction;
}

function sahtout_holiday_lunar_new_year(int $year): array
{
    $jan21 = sahtout_holiday_date_to_jd($year, 1, 21.0);
    $feb21 = sahtout_holiday_date_to_jd($year, 2, 21.0);
    $k = floor(($year - 2000.0) * 12.3685);

    for ($i = -2; $i <= 2; $i++) {
        $nmJde = sahtout_holiday_new_moon($k + $i);
        $nmJd = $nmJde - 70.0 / 86400.0 + 8.0 / 24.0;
        if ($nmJd >= $jan21 && $nmJd < $feb21) {
            return sahtout_holiday_jd_to_date($nmJd);
        }
    }

    return ['year' => $year, 'month' => 1, 'day' => 25];
}

function sahtout_holiday_autumn_equinox(int $year): array
{
    $y = ($year - 2000.0) / 1000.0;
    $jde0 = 2451810.21715 + 365242.01767 * $y - 0.11575 * $y * $y + 0.00337 * $y ** 3 + 0.00078 * $y ** 4;
    $t = ($jde0 - 2451545.0) / 36525.0;
    $w = 35999.373 * $t - 2.47;
    $rad = M_PI / 180.0;
    $deltaLambda = 1.0 + 0.0334 * cos($w * $rad) + 0.0007 * cos(2.0 * $w * $rad);
    $s = 485 * cos((324.96 + 1934.136 * $t) * $rad)
        + 203 * cos((337.23 + 32964.467 * $t) * $rad)
        + 199 * cos((342.08 + 20.186 * $t) * $rad);
    $jde = $jde0 + (0.00001 * $s) / $deltaLambda;

    return sahtout_holiday_jd_to_date($jde);
}

function sahtout_holiday_winter_solstice(int $year): array
{
    $y = ($year - 2000.0) / 1000.0;
    $jde0 = 2451900.05952 + 365242.74049 * $y - 0.06223 * $y * $y - 0.00823 * $y ** 3 + 0.00032 * $y ** 4;
    $t = ($jde0 - 2451545.0) / 36525.0;
    $w = 35999.373 * $t - 2.47;
    $rad = M_PI / 180.0;
    $deltaLambda = 1.0 + 0.0334 * cos($w * $rad) + 0.0007 * cos(2.0 * $w * $rad);
    $s = 485 * cos((324.96 + 1934.136 * $t) * $rad)
        + 203 * cos((337.23 + 32964.467 * $t) * $rad)
        + 199 * cos((342.08 + 20.186 * $t) * $rad);
    $jde = $jde0 + (0.00001 * $s) / $deltaLambda;

    return sahtout_holiday_jd_to_date($jde);
}

function sahtout_holiday_calculate_date(array $rule, int $year): array
{
    switch ($rule['type']) {
        case 'fixed':
            return sahtout_holiday_normalize_ymd_array([
                'year' => $year,
                'month' => (int) $rule['month'],
                'day' => (int) $rule['day'],
            ]);
        case 'nth_weekday':
            $d = sahtout_holiday_nth_weekday($year, $rule['month'], $rule['weekday'], $rule['nth']);
            if (!empty($rule['offset'])) {
                return sahtout_holiday_add_days($d['year'], $d['month'], $d['day'], $rule['offset']);
            }

            return $d;
        case 'easter_offset':
            $d = sahtout_holiday_easter_sunday($year);

            return sahtout_holiday_add_days($d['year'], $d['month'], $d['day'], $rule['offset']);
        case 'weekday_on_or_after':
            return sahtout_holiday_weekday_on_or_after($year, $rule['month'], $rule['day'], $rule['weekday']);
        case 'lunar_new_year':
            $d = sahtout_holiday_lunar_new_year($year);

            return sahtout_holiday_add_days($d['year'], $d['month'], $d['day'], $rule['offset']);
        case 'autumn_equinox':
            $d = sahtout_holiday_autumn_equinox($year);

            return sahtout_holiday_add_days($d['year'], $d['month'], $d['day'], $rule['offset']);
        case 'winter_solstice':
            $d = sahtout_holiday_winter_solstice($year);

            return sahtout_holiday_add_days($d['year'], $d['month'], $d['day'], $rule['offset']);
        case 'darkmoon':
            for ($month = 1; $month <= 12; $month++) {
                if ($month % 3 === $rule['location']) {
                    $d = sahtout_holiday_nth_weekday($year, $month, 0, 1);
                    if (!empty($rule['offset'])) {
                        return sahtout_holiday_add_days($d['year'], $d['month'], $d['day'], $rule['offset']);
                    }

                    return $d;
                }
            }

            return ['year' => $year, 'month' => 1, 'day' => 1];
        default:
            return ['year' => $year, 'month' => 1, 'day' => 1];
    }
}

/** @return list<int> packed dates */
function sahtout_holiday_darkmoon_packed_dates(int $locationOffset, int $startYear, int $numYears, int $dayOffset = 0): array
{
    $out = [];
    for ($year = $startYear; $year < $startYear + $numYears && $year <= 2030; $year++) {
        for ($month = 1; $month <= 12; $month++) {
            if ($month % 3 !== $locationOffset) {
                continue;
            }
            $d = sahtout_holiday_nth_weekday($year, $month, 0, 1);
            if ($dayOffset !== 0) {
                $d = sahtout_holiday_add_days($d['year'], $d['month'], $d['day'], $dayOffset);
            }
            $out[] = sahtout_holiday_pack_date($d['year'], $d['month'], $d['day']);
        }
    }

    return $out;
}

function sahtout_holiday_packed_for_year(int $holidayId, int $year): array
{
    $rule = sahtout_holiday_rule_for($holidayId);
    if ($rule === null) {
        return [];
    }
    if ($rule['type'] === 'darkmoon') {
        return sahtout_holiday_darkmoon_packed_dates(
            $rule['location'],
            $year,
            1,
            $rule['offset'] ?? 0
        );
    }
    if ($year > 2030 || $year < 2000) {
        return [];
    }
    $d = sahtout_holiday_calculate_date($rule, $year);

    return [sahtout_holiday_pack_date($d['year'], $d['month'], $d['day'])];
}

function sahtout_holiday_annualize_packed(int $packed, int $targetYear): ?int
{
    if ($packed === 0 || $targetYear < 2000 || $targetYear > 2030) {
        return null;
    }

    $month = (($packed >> 20) & 0xF) + 1;
    $day = (($packed >> 14) & 0x3F) + 1;
    if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || !checkdate($month, $day, $targetYear)) {
        return null;
    }

    $repacked = sahtout_holiday_pack_date($targetYear, $month, $day);

    return $repacked !== 0 ? $repacked : null;
}
