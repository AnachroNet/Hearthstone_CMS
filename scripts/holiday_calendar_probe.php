#!/usr/bin/env php
<?php

/**
 * CLI: diagnose home event calendar data (run on the web server).
 *
 *   php scripts/holiday_calendar_probe.php
 *   php scripts/holiday_calendar_probe.php 2026 12
 */

define('ALLOWED_ACCESS', true);

$projectRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once $projectRoot . 'includes/config.settings.php';
require_once $projectRoot . 'includes/holiday_calendar.php';

$year = isset($argv[1]) ? (int) $argv[1] : (int) date('Y');
$month = isset($argv[2]) ? (int) $argv[2] : (int) date('n');

if (!isset($site_db) || !($site_db instanceof mysqli)) {
    fwrite(STDERR, "site_db unavailable\n");
    exit(1);
}

$dbName = $site_db->query('SELECT DATABASE() AS d')->fetch_assoc()['d'] ?? '?';
echo "Site DB: {$dbName}\n";

$count = (int) ($site_db->query('SELECT COUNT(*) AS c FROM holidays_dbc')->fetch_assoc()['c'] ?? 0);
echo "holidays_dbc rows: {$count}\n";

$loaded = sahtout_holiday_calendar_load_holidays($site_db);
echo 'Loaded holidays: ' . count($loaded) . "\n";

$w141 = null;
foreach ($loaded as $row) {
    if ((int) ($row['resolved_holiday_id'] ?? 0) === 141) {
        $w141 = $row;
        break;
    }
}
if ($w141 === null) {
    echo "Winter Veil (141) not in loaded set\n";
} else {
    $occ = sahtout_holiday_occurrences_in_year($w141, $year);
    echo "Winter Veil {$year} occurrences: " . count($occ) . "\n";
    foreach ($occ as $o) {
        echo "  {$o['start']} .. {$o['end']} {$o['name']}\n";
    }
}

$world = isset($world_db) && $world_db instanceof mysqli ? $world_db : null;
$cal = sahtout_holiday_calendar_build($site_db, $year, $month, $world);
echo "Build {$year}-{$month}: loaded={$cal['loaded_holiday_count']} total_occ={$cal['total_occurrences']} month_occ={$cal['month_occurrence_count']}\n";

foreach ($cal['month_events'] as $ev) {
    echo "  month: {$ev['start']} .. {$ev['end']} {$ev['name']}\n";
}
