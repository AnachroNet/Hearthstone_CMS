<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

/** WotLK 3.3.5 AreaTable.dbc — first locale string (enUS). */
const SAHTOUT_AREA_DBC_FIELD_ID = 0;
const SAHTOUT_AREA_DBC_FIELD_MAP_ID = 1;
const SAHTOUT_AREA_DBC_FIELD_NAME_ENUS = 11;

function sahtout_area_dbc_table_exists(mysqli $siteDb): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $res = $siteDb->query("SHOW TABLES LIKE 'area_dbc'");
    $exists = $res && $res->num_rows > 0;
    $res?->free();

    return $exists;
}

/**
 * @return array<int, string> area_id => English name
 */
function sahtout_area_dbc_name_map(mysqli $siteDb): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    if (!sahtout_area_dbc_table_exists($siteDb)) {
        return $cache;
    }

    $res = $siteDb->query('SELECT area_id, area_name FROM area_dbc ORDER BY area_id ASC');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $id = (int) ($row['area_id'] ?? 0);
            $name = trim((string) ($row['area_name'] ?? ''));
            if ($id > 0 && $name !== '') {
                $cache[$id] = $name;
            }
        }
        $res->free();
    }

    return $cache;
}

function sahtout_area_dbc_display_name(mysqli $siteDb, int $areaId): string
{
    $names = sahtout_area_dbc_name_map($siteDb);

    return $names[$areaId] ?? 'Unknown zone';
}

/**
 * @return list<array{area_id:int, area_name:string, map_id:int}>
 */
function sahtout_area_dbc_parse_file(string $areaPath): array
{
    require_once __DIR__ . '/dbc_reader.php';

    [$rows, $strings] = sahtout_read_wdbc_records($areaPath);
    $out = [];
    foreach ($rows as $row) {
        $areaId = (int) ($row[SAHTOUT_AREA_DBC_FIELD_ID] ?? 0);
        if ($areaId <= 0) {
            continue;
        }
        $name = sahtout_dbc_string($strings, (int) ($row[SAHTOUT_AREA_DBC_FIELD_NAME_ENUS] ?? 0));
        if ($name === '') {
            continue;
        }
        $out[] = [
            'area_id' => $areaId,
            'area_name' => $name,
            'map_id' => (int) ($row[SAHTOUT_AREA_DBC_FIELD_MAP_ID] ?? 0),
        ];
    }
    usort($out, static fn(array $a, array $b): int => $a['area_id'] <=> $b['area_id']);

    return $out;
}
