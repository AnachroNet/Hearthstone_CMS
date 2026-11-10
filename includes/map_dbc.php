<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

/** WotLK Map.dbc field indices (3.3.5). */
const SAHTOUT_MAP_DBC_FIELD_ID = 0;
const SAHTOUT_MAP_DBC_FIELD_TYPE = 2;
const SAHTOUT_MAP_DBC_FIELD_NAME_ENUS = 5;
const SAHTOUT_MAP_DBC_FIELD_MAX_PLAYERS = 65;

function sahtout_map_dbc_table_exists(mysqli $siteDb): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $res = $siteDb->query("SHOW TABLES LIKE 'map_dbc'");
    $exists = $res && $res->num_rows > 0;
    $res?->free();

    return $exists;
}

/**
 * @return array<int, string> map_id => name (sorted by map_id)
 */
function sahtout_map_dbc_name_map(mysqli $siteDb): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    if (!sahtout_map_dbc_table_exists($siteDb)) {
        return $cache;
    }

    $res = $siteDb->query('SELECT map_id, map_name FROM map_dbc ORDER BY map_id ASC');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $id = (int) ($row['map_id'] ?? 0);
            $name = trim((string) ($row['map_name'] ?? ''));
            if ($id >= 0 && $name !== '') {
                $cache[$id] = $name;
            }
        }
        $res->free();
    }

    return $cache;
}

function sahtout_map_dbc_display_name(mysqli $siteDb, int $mapId): string
{
    $names = sahtout_map_dbc_name_map($siteDb);

    return $names[$mapId] ?? (string) $mapId;
}

/**
 * @return array<int, string> map_id => name for dropdowns (sorted by name, then id)
 */
function sahtout_map_dbc_names_sorted_by_label(mysqli $siteDb): array
{
    $names = sahtout_map_dbc_name_map($siteDb);
    if ($names === []) {
        return [];
    }
    $pairs = [];
    foreach ($names as $id => $name) {
        $pairs[] = ['id' => $id, 'name' => $name];
    }
    usort($pairs, static function (array $a, array $b): int {
        $cmp = strcasecmp($a['name'], $b['name']);

        return $cmp !== 0 ? $cmp : $a['id'] <=> $b['id'];
    });
    $sorted = [];
    foreach ($pairs as $pair) {
        $sorted[$pair['id']] = $pair['name'];
    }

    return $sorted;
}

/**
 * Parse Map.dbc into rows suitable for SQL import.
 *
 * @return list<array{map_id:int, map_name:string, map_type:int, max_players:int}>
 */
function sahtout_map_dbc_parse_file(string $mapPath): array
{
    require_once __DIR__ . '/dbc_reader.php';

    [$mapRows, $mapStrings] = sahtout_read_wdbc_records($mapPath);
    $out = [];
    foreach ($mapRows as $row) {
        $mapId = sahtout_sanitize_map_id((int) ($row[SAHTOUT_MAP_DBC_FIELD_ID] ?? 0));
        if ($mapId === null) {
            continue;
        }
        $name = sahtout_dbc_string($mapStrings, (int) ($row[SAHTOUT_MAP_DBC_FIELD_NAME_ENUS] ?? 0));
        if ($name === '') {
            continue;
        }
        $out[] = [
            'map_id' => $mapId,
            'map_name' => $name,
            'map_type' => (int) ($row[SAHTOUT_MAP_DBC_FIELD_TYPE] ?? 0),
            'max_players' => (int) ($row[SAHTOUT_MAP_DBC_FIELD_MAX_PLAYERS] ?? 0),
        ];
    }
    usort($out, static fn(array $a, array $b): int => $a['map_id'] <=> $b['map_id']);

    return $out;
}
