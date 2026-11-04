<?php

if (!defined('ALLOWED_ACCESS')) {
    exit('Forbidden');
}

/**
 * Map WoW class id → integrated talent calculator slug / talents folder.
 *
 * @return array{slug: string, dir: string}|null
 */
function sahtout_talent_calculator_for_class(int $classId): ?array
{
    static $map = [
        1 => ['slug' => 'warrior', 'dir' => 'Warrior'],
        2 => ['slug' => 'paladin', 'dir' => 'Paladin'],
        3 => ['slug' => 'hunter', 'dir' => 'Hunter'],
        4 => ['slug' => 'rogue', 'dir' => 'Rogue'],
        5 => ['slug' => 'priest', 'dir' => 'Priest'],
        6 => ['slug' => 'deathknight', 'dir' => 'Deathknight'],
        7 => ['slug' => 'shaman', 'dir' => 'Shaman'],
        8 => ['slug' => 'mage', 'dir' => 'Mage'],
        9 => ['slug' => 'warlock', 'dir' => 'Warlock'],
        11 => ['slug' => 'druid', 'dir' => 'Druid'],
    ];

    return $map[$classId] ?? null;
}

function sahtout_talent_calculator_normalize_name(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/\s+/', ' ', $name);

    return $name;
}

/**
 * Talent names in calculator index order (from donotlocalize.js).
 *
 * @return list<string>
 */
function sahtout_talent_calculator_talent_names(string $talentDir): array
{
    static $cache = [];
    if (isset($cache[$talentDir])) {
        return $cache[$talentDir];
    }

    $projectRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR;
    $path = $projectRoot . 'talent-calculator' . DIRECTORY_SEPARATOR . 'talents'
        . DIRECTORY_SEPARATOR . $talentDir . DIRECTORY_SEPARATOR . 'donotlocalize.js';

    if (!is_readable($path)) {
        return $cache[$talentDir] = [];
    }

    $js = file_get_contents($path);
    if ($js === false) {
        return $cache[$talentDir] = [];
    }

    if (!preg_match_all('/nltalent\[i\]\s*=\s*\[\s*"((?:[^"\\\\]|\\\\.)*)"\s*\]/', $js, $matches)) {
        return $cache[$talentDir] = [];
    }

    $names = [];
    foreach ($matches[1] as $raw) {
        $names[] = stripcslashes($raw);
    }

    return $cache[$talentDir] = $names;
}

/**
 * @param list<array{name: string, rank: int}> $learned
 */
function sahtout_talent_calculator_build_tal_string(string $talentDir, array $learned): ?string
{
    $names = sahtout_talent_calculator_talent_names($talentDir);
    if ($names === []) {
        return null;
    }

    $ranks = array_fill(0, count($names), 0);
    $indexByName = [];
    foreach ($names as $i => $name) {
        $indexByName[sahtout_talent_calculator_normalize_name($name)] = $i;
    }

    foreach ($learned as $row) {
        $name = (string) ($row['name'] ?? '');
        if ($name === '') {
            continue;
        }
        $key = sahtout_talent_calculator_normalize_name($name);
        if (!isset($indexByName[$key])) {
            continue;
        }
        $idx = $indexByName[$key];
        $rank = max(0, min(9, (int) ($row['rank'] ?? 0)));
        $ranks[$idx] = max($ranks[$idx], $rank);
    }

    $out = '';
    foreach ($ranks as $rank) {
        $out .= (string) $rank;
    }

    return $out;
}

/**
 * @param list<array{name: string, rank: int}> $learned
 *
 * @return array{supported: bool, slug: ?string, dir: ?string, tal: ?string, url: ?string, has_points: bool}
 */
function sahtout_talent_calculator_spec_meta(int $classId, array $learned, string $basePath): array
{
    $meta = [
        'supported' => false,
        'slug' => null,
        'dir' => null,
        'tal' => null,
        'url' => null,
        'has_points' => false,
    ];

    $class = sahtout_talent_calculator_for_class($classId);
    if ($class === null) {
        return $meta;
    }

    $tal = sahtout_talent_calculator_build_tal_string($class['dir'], $learned);
    if ($tal === null) {
        return $meta;
    }

    $hasPoints = false;
    foreach ($learned as $row) {
        if ((int) ($row['rank'] ?? 0) > 0) {
            $hasPoints = true;
            break;
        }
    }

    $meta['supported'] = true;
    $meta['slug'] = $class['slug'];
    $meta['dir'] = $class['dir'];
    $meta['tal'] = $tal;
    $meta['has_points'] = $hasPoints;

    if ($hasPoints) {
        $meta['url'] = rtrim($basePath, '/') . '/talent-calculator/'
            . rawurlencode($class['slug']) . '.php?tal=' . rawurlencode($tal);
    } else {
        $meta['url'] = rtrim($basePath, '/') . '/talent-calculator/'
            . rawurlencode($class['slug']) . '.php';
    }

    return $meta;
}

/**
 * @param list<array{name: string, rank: int}> $talentRows
 */
function sahtout_talent_calculator_learned_from_rows(array $talentRows): array
{
    $learned = [];
    foreach ($talentRows as $row) {
        $learned[] = [
            'name' => (string) ($row['name'] ?? ''),
            'rank' => (int) ($row['rank'] ?? 0),
        ];
    }

    return $learned;
}
