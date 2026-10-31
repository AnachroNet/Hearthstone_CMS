<?php

/**
 * LFGDungeons.dbc — recommended / target levels per instance map (WotLK 3.3.5).
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'dbc_reader.php';

const LFG_DUNGEON_FIELD_NAME_ENUS = 1;
const LFG_DUNGEON_FIELD_MIN_LEVEL = 18;
const LFG_DUNGEON_FIELD_MAX_LEVEL = 19;
const LFG_DUNGEON_FIELD_TARGET_LEVEL = 20;
const LFG_DUNGEON_FIELD_MAP_ID = 23;
const LFG_DUNGEON_FIELD_DIFFICULTY = 24;

/**
 * LFG dungeon entries keyed by instance map id (names from LFGDungeons.dbc).
 *
 * @return array<int, string> mapId => enUS name
 */
function sahtout_lfg_dungeon_map_names(): array
{
    $names = [];
    $dir = sahtout_data_dbc_dir();
    if ($dir === null) {
        return $names;
    }

    $path = sahtout_find_dbc_file($dir, 'LFGDungeons.dbc');
    if ($path === null) {
        return $names;
    }

    try {
        [$rows, $strings] = sahtout_read_wdbc_records($path);
    } catch (RuntimeException $e) {
        error_log('LFGDungeons.dbc: ' . $e->getMessage());

        return $names;
    }

    foreach ($rows as $row) {
        $mapId = (int) ($row[LFG_DUNGEON_FIELD_MAP_ID] ?? 0);
        if ($mapId <= 0) {
            continue;
        }
        $name = sahtout_dbc_string($strings, (int) ($row[LFG_DUNGEON_FIELD_NAME_ENUS] ?? 0));
        if ($name !== '' && !isset($names[$mapId])) {
            $names[$mapId] = $name;
        }
    }

    return $names;
}

/** @return array<int, list<array{difficulty:int, min:int, max:int, target:int}>> */
function sahtout_lfg_dungeon_index_by_map(): array
{
    static $index = null;
    if ($index !== null) {
        return $index;
    }

    $index = [];
    $dir = sahtout_data_dbc_dir();
    if ($dir === null) {
        return $index;
    }

    $path = sahtout_find_dbc_file($dir, 'LFGDungeons.dbc');
    if ($path === null) {
        return $index;
    }

    try {
        [$rows, ] = sahtout_read_wdbc_records($path);
    } catch (RuntimeException $e) {
        error_log('LFGDungeons.dbc: ' . $e->getMessage());

        return $index;
    }

    foreach ($rows as $row) {
        $mapId = (int) ($row[LFG_DUNGEON_FIELD_MAP_ID] ?? 0);
        if ($mapId <= 0) {
            continue;
        }
        $index[$mapId][] = [
            'difficulty' => (int) ($row[LFG_DUNGEON_FIELD_DIFFICULTY] ?? 0),
            'min' => (int) ($row[LFG_DUNGEON_FIELD_MIN_LEVEL] ?? 0),
            'max' => (int) ($row[LFG_DUNGEON_FIELD_MAX_LEVEL] ?? 0),
            'target' => (int) ($row[LFG_DUNGEON_FIELD_TARGET_LEVEL] ?? 0),
        ];
    }

    return $index;
}

/**
 * @param list<array{difficulty:int, min:int, max:int, target:int}> $entries
 * @return array{min:int, max:int, target:int}|null
 */
function sahtout_lfg_pick_dungeon_entry(array $entries): ?array
{
    if ($entries === []) {
        return null;
    }

    foreach ([0, 1, 2] as $preferredDiff) {
        foreach ($entries as $entry) {
            if ($entry['difficulty'] === $preferredDiff) {
                return [
                    'min' => $entry['min'],
                    'max' => $entry['max'],
                    'target' => $entry['target'],
                ];
            }
        }
    }

    $first = $entries[0];

    return [
        'min' => $first['min'],
        'max' => $first['max'],
        'target' => $first['target'],
    ];
}

/** @return array{min:int, max:int, target:int}|null */
function sahtout_lfg_dungeon_levels_for_map(int $mapId): ?array
{
    if ($mapId <= 0) {
        return null;
    }

    $index = sahtout_lfg_dungeon_index_by_map();
    if (!isset($index[$mapId])) {
        return null;
    }

    return sahtout_lfg_pick_dungeon_entry($index[$mapId]);
}

/** @param array{min:int, max:int, target:int}|null $levels */
function sahtout_format_dungeon_level_caption(?array $levels): ?string
{
    if ($levels === null) {
        return null;
    }

    $min = (int) ($levels['min'] ?? 0);
    $max = (int) ($levels['max'] ?? 0);
    $target = (int) ($levels['target'] ?? 0);

    if ($target > 0) {
        return 'Level ' . $target;
    }
    if ($min > 0 && $max > 0 && $min !== $max) {
        return 'Level ' . $min . '–' . $max;
    }
    if ($min > 0) {
        return 'Level ' . $min;
    }
    if ($max > 0) {
        return 'Level ' . $max;
    }

    return null;
}
