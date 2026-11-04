<?php
$talentCalcBase = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
if ($talentCalcBase === '/' || $talentCalcBase === '') {
    $talentCalcBase = '/';
} else {
    $talentCalcBase = rtrim($talentCalcBase, '/') . '/';
}
