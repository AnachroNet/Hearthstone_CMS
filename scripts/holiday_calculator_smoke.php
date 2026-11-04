#!/usr/bin/env php
<?php

/**
 * Offline smoke test for holiday date rules (no database).
 *
 *   php scripts/holiday_calculator_smoke.php
 */

define('ALLOWED_ACCESS', true);
require_once dirname(__DIR__) . '/includes/holiday_date_calculator.php';
require_once dirname(__DIR__) . '/includes/holiday_calendar.php';

$year = isset($argv[1]) ? (int) $argv[1] : 2026;
$fail = 0;

$stub141 = [
    'resolved_holiday_id' => 141,
    'ID' => 141,
    'Duration_1' => 432,
    'holiday_name' => 'Feast of Winter Veil',
    'holiday_description' => '',
];

$occ141 = sahtout_holiday_occurrences_in_year($stub141, $year);
echo "Winter Veil {$year}: " . count($occ141) . " occurrence(s)\n";
foreach ($occ141 as $o) {
    echo "  {$o['start']} .. {$o['end']}\n";
}
if ($occ141 === []) {
    $fail = 1;
    echo "  FAIL: expected one span\n";
}

$stub404 = [
    'resolved_holiday_id' => 404,
    'ID' => 404,
    'Duration_1' => 167,
    'holiday_name' => "Pilgrim's Bounty",
    'holiday_description' => '',
];
$occ404 = sahtout_holiday_occurrences_in_year($stub404, $year);
echo "Pilgrim's Bounty {$year}: " . count($occ404) . " occurrence(s)\n";
foreach ($occ404 as $o) {
    echo "  {$o['start']} .. {$o['end']}\n";
}
if ($occ404 === []) {
    $fail = 1;
}

exit($fail);
